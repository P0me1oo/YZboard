<?php

namespace Tests\Feature\Server;

use App\Http\Controllers\V2\Admin\Server\ManageController;
use App\Models\Server;
use App\Models\ServerMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ServerPortConflictTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['save', 'checkPort', 'update', 'batchUpdate', 'copy'] as $action) {
            Route::post('/_tests/server-port/' . $action, [ManageController::class, $action]);
        }
    }

    private function machine(): ServerMachine
    {
        return ServerMachine::create([
            'name' => '端口检查测试服务器',
            'token' => bin2hex(random_bytes(24)),
            'is_active' => true,
        ]);
    }

    private function payload(array $overrides = []): array
    {
        $type = $overrides['type'] ?? Server::TYPE_VLESS;
        return array_replace([
            'name' => '端口检查测试节点', 'type' => $type,
            'host' => 'port.example.invalid', 'port' => '443', 'server_port' => 24443,
            'rate' => 1, 'enabled' => true,
            'protocol_settings' => match ($type) {
                Server::TYPE_SHADOWSOCKS => ['cipher' => 'aes-128-gcm'],
                Server::TYPE_VLESS, Server::TYPE_VMESS, Server::TYPE_TROJAN => ['tls' => 0, 'network' => 'tcp'],
                Server::TYPE_HYSTERIA => ['version' => 2],
                Server::TYPE_SOCKS, Server::TYPE_HTTP, Server::TYPE_NAIVE => ['tls' => 1],
                Server::TYPE_MIERU => ['transport' => 'TCP'],
                default => [],
            },
        ], $overrides);
    }

    private function node(array $overrides = []): Server
    {
        return Server::create($this->payload($overrides));
    }

    private function preview(array $payload): array
    {
        return array_intersect_key($payload, array_flip([
            'id', 'machine_id', 'server_port', 'type', 'kernel_type', 'enabled', 'protocol_settings',
        ]));
    }

    public function test_duplicate_internal_port_is_reported_and_cannot_be_saved(): void
    {
        $machine = $this->machine();
        $other = $this->node(['machine_id' => $machine->id, 'name' => '已占用节点']);
        $payload = $this->payload(['machine_id' => $machine->id, 'kernel_type' => 'singbox']);

        $check = $this->postJson('/_tests/server-port/checkPort', $this->preview($payload))
            ->assertOk()->assertJsonPath('data.valid', false);
        $message = $check->json('data.message');
        $this->assertStringContainsString('24443/TCP', $message);
        $this->assertStringContainsString('已占用节点', $message);
        $this->assertStringContainsString((string) $other->id, $message);

        for ($repeat = 0; $repeat < 2; $repeat++) {
            $this->postJson('/_tests/server-port/save', $payload)
                ->assertUnprocessable()->assertJsonValidationErrors('server_port');
        }
        $this->assertSame(1, Server::count());
    }

    public static function transportCases(): array
    {
        return [
            'HY2 与 TCP 共存' => ['hysteria', ['version' => 2], 'singbox', 'tcp', true],
            'HY2 与 UDP 重复' => ['hysteria', ['version' => 2], 'xray', 'udp', false],
            'HY1 与 UDP 重复' => ['hysteria', ['version' => 1], 'singbox', 'udp', false],
            'TUIC 与 TCP 共存' => ['tuic', [], 'singbox', 'tcp', true],
            'TUIC 与 UDP 重复' => ['tuic', [], 'singbox', 'udp', false],
            'SS 同时占用 TCP' => ['shadowsocks', ['cipher' => 'aes-128-gcm'], 'singbox', 'tcp', false],
            'SS 同时占用 UDP' => ['shadowsocks', ['cipher' => 'aes-128-gcm'], 'xray', 'udp', false],
            'Xray SOCKS 固定 UDP 端口' => ['socks', ['tls' => 0], 'xray', 'udp', false],
            'sing-box SOCKS 临时 UDP 端口' => ['socks', ['tls' => 0], 'singbox', 'udp', true],
            '历史 SOCKS 默认 Xray' => ['socks', ['tls' => 0], null, 'udp', false],
            'sing-box 别名仍按 SOCKS TCP 判断' => ['socks', ['tls' => 0], 'sing-box', 'tcp', false],
            'Naive 同时占用 UDP' => ['naive', ['tls' => 1], 'singbox', 'udp', false],
            'Naive 同时占用 TCP' => ['naive', ['tls' => 1], 'singbox', 'tcp', false],
            'Mieru TCP 与 UDP 共存' => ['mieru', ['transport' => 'TCP'], 'singbox', 'udp', true],
            'Mieru UDP 与 UDP 重复' => ['mieru', ['transport' => 'UDP'], 'singbox', 'udp', false],
            'VLESS KCP 与 TCP 共存' => ['vless', ['tls' => 0, 'network' => 'kcp'], 'xray', 'tcp', true],
            'VLESS mKCP 与 UDP 重复' => ['vless', ['tls' => 0, 'network' => 'mkcp'], 'xray', 'udp', false],
            'VLESS Hysteria 传输占用 UDP' => ['vless', ['tls' => 1, 'network' => 'hysteria'], 'xray', 'udp', false],
            'VMess QUIC 与 TCP 共存' => ['vmess', ['tls' => 1, 'network' => 'quic'], 'singbox', 'tcp', true],
            'VMess WebSocket 占用 TCP' => ['vmess', ['tls' => 1, 'network' => 'ws'], 'singbox', 'tcp', false],
            'Trojan gRPC 与 UDP 共存' => ['trojan', ['tls' => 1, 'network' => 'grpc'], 'xray', 'udp', true],
            'VLESS XHTTP 占用 TCP' => ['vless', ['tls' => 1, 'network' => 'xhttp'], 'xray', 'tcp', false],
            'AnyTLS 与 UDP 共存' => ['anytls', [], 'singbox', 'udp', true],
            'HTTP 与 UDP 共存' => ['http', ['tls' => 1], 'singbox', 'udp', true],
        ];
    }

    #[DataProvider('transportCases')]
    public function test_transport_overlap_matches_node_listeners(
        string $type, array $settings, ?string $kernel, string $occupied, bool $allowed
    ): void {
        $machine = $this->machine();
        $this->node([
            'machine_id' => $machine->id,
            'type' => $occupied === 'udp' ? Server::TYPE_HYSTERIA : Server::TYPE_VLESS,
        ]);
        // 显式创建历史空内核节点，检查时不能套用新建节点的默认内核。
        $candidate = $this->node([
            'type' => $type, 'kernel_type' => $kernel, 'protocol_settings' => $settings,
            'server_port' => 24444, 'machine_id' => $machine->id,
        ]);
        $payload = $this->payload([
            'id' => $candidate->id, 'machine_id' => $machine->id, 'type' => $type,
            'kernel_type' => $kernel, 'protocol_settings' => $settings,
        ]);
        $this->postJson('/_tests/server-port/checkPort', $this->preview($payload))
            ->assertOk()->assertJsonPath('data.valid', $allowed);
        $response = $this->postJson('/_tests/server-port/save', $payload);
        if ($allowed) {
            $response->assertOk();
            $this->assertSame(24443, (int) $candidate->fresh()->server_port);
        } else {
            $response->assertUnprocessable()->assertJsonValidationErrors('server_port');
            $this->assertSame(24444, (int) $candidate->fresh()->server_port);
        }
    }

    public function test_connection_ports_other_machines_and_unbound_nodes_are_independent(): void
    {
        $first = $this->machine();
        $second = $this->machine();
        $this->node(['machine_id' => $first->id]);
        foreach ([
            ['machine_id' => $first->id, 'server_port' => 24444],
            ['machine_id' => $second->id],
            ['machine_id' => null],
            ['machine_id' => 0],
            [],
        ] as $override) {
            $response = $this->postJson('/_tests/server-port/save', $this->payload($override));
            $this->assertSame(200, $response->status(), json_encode($override));
            $this->assertSame('443', (string) Server::latest('id')->firstOrFail()->port);
        }
        $this->assertSame(6, Server::count());
    }

    public function test_copy_and_unrelated_edits_keep_identical_ports(): void
    {
        $machine = $this->machine();
        $source = $this->node(['machine_id' => $machine->id]);
        for ($repeat = 0; $repeat < 2; $repeat++) {
            $this->postJson('/_tests/server-port/copy', ['id' => $source->id])->assertOk();
            $copy = Server::latest('id')->firstOrFail();
            $this->assertNotSame($source->id, $copy->id);
            foreach (['machine_id', 'port', 'server_port', 'enabled'] as $field) {
                $this->assertEquals($source->$field, $copy->$field);
            }
            $payload = $this->payload(['id' => $copy->id, 'name' => '副本新名称']);
            $this->postJson('/_tests/server-port/checkPort', $this->preview($payload))
                ->assertOk()->assertJsonPath('data.valid', true);
            $this->postJson('/_tests/server-port/save', $payload)->assertOk();
            $this->postJson('/_tests/server-port/save', $payload)->assertOk();
            $this->assertSame('副本新名称', $copy->fresh()->name);
        }

        $occupied = $this->node(['machine_id' => $machine->id, 'server_port' => 24444]);
        $this->postJson('/_tests/server-port/save', $this->payload([
            'id' => $copy->id, 'server_port' => $occupied->server_port,
        ]))->assertUnprocessable()->assertJsonValidationErrors('server_port');
        $this->postJson('/_tests/server-port/save', $this->payload([
            'id' => $copy->id, 'server_port' => 24445,
        ]))->assertOk();
        $this->assertSame(24445, (int) $copy->fresh()->server_port);
    }

    public function test_rebinding_and_changing_transport_recheck_the_port(): void
    {
        $machine = $this->machine();
        $this->node(['machine_id' => $machine->id]);
        $node = $this->node();
        foreach (['checkPort', 'save'] as $action) {
            $payload = $this->payload(['id' => $node->id, 'machine_id' => $machine->id]);
            $response = $this->postJson('/_tests/server-port/' . $action, $action === 'checkPort' ? $this->preview($payload) : $payload);
            $action === 'checkPort'
                ? $response->assertOk()->assertJsonPath('data.valid', false)
                : $response->assertUnprocessable()->assertJsonValidationErrors('server_port');
        }
        $this->postJson('/_tests/server-port/update', ['id' => $node->id, 'machine_id' => $machine->id])
            ->assertUnprocessable()->assertJsonValidationErrors('server_port');
        $this->assertNull($node->fresh()->machine_id);

        $udp = $this->node([
            'machine_id' => $machine->id, 'protocol_settings' => ['tls' => 1, 'network' => 'hysteria'],
        ]);
        $this->postJson('/_tests/server-port/save', $this->payload(['id' => $udp->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('server_port');
        $this->assertSame('hysteria', $udp->fresh()->protocol_settings['network']);
    }

    public function test_new_socks_defaults_and_kernel_switch_use_the_same_check(): void
    {
        $machine = $this->machine();
        $this->node(['machine_id' => $machine->id, 'type' => Server::TYPE_HYSTERIA]);
        $payload = $this->payload(['machine_id' => $machine->id, 'type' => Server::TYPE_SOCKS]);
        $this->postJson('/_tests/server-port/checkPort', $this->preview($payload))
            ->assertOk()->assertJsonPath('data.valid', true);
        $this->postJson('/_tests/server-port/save', $payload)->assertOk();
        $socks = Server::latest('id')->firstOrFail();
        $this->assertSame('singbox', $socks->kernel_type);
        $this->postJson('/_tests/server-port/update', ['id' => $socks->id, 'kernel_type' => 'xray'])
            ->assertUnprocessable()->assertJsonValidationErrors('server_port');
        $this->assertSame('singbox', $socks->fresh()->kernel_type);
    }

    public function test_hidden_and_disabled_nodes_reserve_their_internal_port(): void
    {
        $machine = $this->machine();
        $this->node(['machine_id' => $machine->id, 'show' => false, 'enabled' => false]);
        $this->postJson('/_tests/server-port/save', $this->payload(['machine_id' => $machine->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('server_port');
    }

    public function test_disabling_a_duplicate_is_allowed_but_reenabling_is_checked(): void
    {
        $machine = $this->machine();
        $source = $this->node(['machine_id' => $machine->id]);
        $this->postJson('/_tests/server-port/copy', ['id' => $source->id])->assertOk();
        $copy = Server::latest('id')->firstOrFail();
        $this->postJson('/_tests/server-port/update', ['id' => $copy->id, 'enabled' => false])->assertOk();
        $this->postJson('/_tests/server-port/update', ['id' => $copy->id, 'enabled' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('server_port');
        $this->postJson('/_tests/server-port/save', $this->payload(['id' => $copy->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('server_port');
        $this->assertFalse($copy->fresh()->enabled);
    }

    public function test_batch_rebinding_rolls_back_all_nodes_on_conflict(): void
    {
        $firstMachine = $this->machine();
        $secondMachine = $this->machine();
        $target = $this->machine();
        $first = $this->node(['machine_id' => $firstMachine->id]);
        $second = $this->node(['machine_id' => $secondMachine->id]);
        $this->postJson('/_tests/server-port/batchUpdate', [
            'ids' => [$first->id, $second->id], 'machine_id' => $target->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('server_port');
        $this->assertSame($firstMachine->id, $first->fresh()->machine_id);
        $this->assertSame($secondMachine->id, $second->fresh()->machine_id);

        $second->update(['protocol_settings' => ['tls' => 1, 'network' => 'hysteria']]);
        for ($repeat = 0; $repeat < 2; $repeat++) {
            $this->postJson('/_tests/server-port/batchUpdate', [
                'ids' => [$first->id, $second->id], 'machine_id' => $target->id,
            ])->assertOk();
        }
        $this->assertSame($target->id, $first->fresh()->machine_id);
        $this->assertSame($target->id, $second->fresh()->machine_id);
    }

    public function test_batch_kernel_change_cannot_add_an_occupied_udp_listener(): void
    {
        $machine = $this->machine();
        $this->node(['machine_id' => $machine->id, 'type' => Server::TYPE_HYSTERIA]);
        $socks = $this->node([
            'machine_id' => $machine->id, 'type' => Server::TYPE_SOCKS, 'kernel_type' => 'singbox',
        ]);
        $this->postJson('/_tests/server-port/batchUpdate', [
            'ids' => [$socks->id], 'kernel_type' => 'xray',
        ])->assertUnprocessable()->assertJsonValidationErrors('server_port');
        $this->assertSame('singbox', $socks->fresh()->kernel_type);
    }

    public function test_invalid_internal_ports_are_rejected_before_writing(): void
    {
        foreach ([0, -1, 65536, '443-444', '443,444', 'abc', 443.5, []] as $port) {
            $this->postJson('/_tests/server-port/save', $this->payload(['server_port' => $port]))
                ->assertUnprocessable()->assertJsonValidationErrors('server_port');
        }
        $this->assertSame(0, Server::count());
    }
}
