<?php

namespace Tests\Feature\Server;

use App\Http\Controllers\V2\Admin\Server\ManageController;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Support\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Mockery\MockInterface;
use Tests\TestCase;

class ServerKernelDefaultsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Route::post('/_tests/kernel-defaults/save', [ManageController::class, 'save']);
    }

    private function payload(string $type = Server::TYPE_SHADOWSOCKS, array $overrides = []): array
    {
        return array_replace([
            'name' => '默认内核测试', 'type' => $type,
            'host' => 'kernel.example.invalid', 'port' => '24443', 'server_port' => 24443,
            'rate' => 1, 'enabled' => true,
            'protocol_settings' => match ($type) {
                Server::TYPE_SHADOWSOCKS => ['cipher' => 'aes-128-gcm'],
                Server::TYPE_VMESS, Server::TYPE_VLESS, Server::TYPE_TROJAN => ['tls' => 0, 'network' => 'tcp'],
                Server::TYPE_HYSTERIA => ['version' => 2],
                Server::TYPE_SOCKS, Server::TYPE_NAIVE, Server::TYPE_HTTP => ['tls' => 0],
                Server::TYPE_MIERU => ['transport' => 'TCP'],
                default => [],
            },
        ], $overrides);
    }

    public function test_new_nodes_persist_protocol_default_and_allow_explicit_choice(): void
    {
        foreach (Server::VALID_TYPES as $type) {
            $expected = $type === Server::TYPE_VLESS ? 'xray' : 'singbox';
            foreach ([[], ['kernel_type' => null], ['kernel_type' => '']] as $override) {
                $this->postJson('/_tests/kernel-defaults/save', $this->payload($type, $override))->assertOk();
                $this->assertSame($expected, Server::latest('id')->firstOrFail()->kernel_type, $type);
            }
        }

        foreach (['xray', 'singbox', 'sing-box'] as $kernel) {
            foreach ([Server::TYPE_SHADOWSOCKS, Server::TYPE_VLESS] as $type) {
                $this->postJson('/_tests/kernel-defaults/save', $this->payload($type, ['kernel_type' => $kernel]))->assertOk();
                $this->assertSame($kernel, Server::latest('id')->firstOrFail()->kernel_type);
            }
        }
    }

    public function test_editing_and_copying_existing_nodes_preserves_their_kernel(): void
    {
        foreach ([null, 'xray', 'singbox'] as $kernel) {
            $server = Server::create($this->payload(overrides: ['kernel_type' => $kernel]));
            foreach ([Server::TYPE_SHADOWSOCKS, Server::TYPE_VLESS, Server::TYPE_VMESS] as $type) {
                for ($repeat = 0; $repeat < 2; $repeat++) {
                    $this->postJson('/_tests/kernel-defaults/save', $this->payload($type, [
                        'id' => $server->id, 'name' => '已编辑节点',
                    ]))->assertOk();
                    $this->assertSame($kernel, $server->fresh()->kernel_type);
                }
            }
            app(ManageController::class)->copy(Request::create('/', 'POST', ['id' => $server->id]));
            $copy = Server::latest('id')->firstOrFail();
            $this->assertNotSame($server->id, $copy->id);
            $this->assertSame($kernel, $copy->kernel_type);
        }
    }

    public function test_invalid_kernel_does_not_create_or_change_a_node(): void
    {
        $this->postJson('/_tests/kernel-defaults/save', $this->payload(overrides: ['kernel_type' => 'unknown']))
            ->assertUnprocessable()->assertJsonValidationErrors('kernel_type');
        $this->assertSame(0, Server::count());

        $server = Server::create($this->payload(overrides: ['kernel_type' => 'xray']));
        $this->postJson('/_tests/kernel-defaults/save', $this->payload(overrides: [
            'id' => $server->id, 'kernel_type' => 'unknown',
        ]))->assertUnprocessable()->assertJsonValidationErrors('kernel_type');
        $this->assertSame('xray', $server->fresh()->kernel_type);
    }

    public function test_machine_and_single_node_endpoints_keep_new_and_legacy_kernel_choices(): void
    {
        $credential = bin2hex(random_bytes(24));
        $machine = ServerMachine::create(['name' => '默认内核测试机器', 'token' => $credential, 'is_active' => true]);
        $expected = [];
        foreach ([Server::TYPE_SHADOWSOCKS => 'singbox', Server::TYPE_VLESS => 'xray'] as $type => $kernel) {
            $this->postJson('/_tests/kernel-defaults/save', $this->payload($type, [
                'machine_id' => $machine->id, 'server_port' => 24443 + count($expected),
            ]))->assertOk();
            $expected[Server::latest('id')->firstOrFail()->id] = $kernel;
        }
        $legacy = Server::create($this->payload(overrides: ['machine_id' => $machine->id, 'kernel_type' => null]));
        $expected[$legacy->id] = 'xray';

        $this->mock(Setting::class, function (MockInterface $mock) use ($credential): void {
            $mock->shouldReceive('get')->andReturnUsing(fn(string $key) => $key === 'server_token' ? $credential : null);
        });

        for ($repeat = 0; $repeat < 2; $repeat++) {
            $auth = ['machine_id' => $machine->id, 'token' => $credential];
            $response = $this->postJson('/api/v2/server/machine/nodes', $auth)->assertOk();
            $actual = collect($response->json('nodes'))->pluck('kernel_type', 'id')->all();
            $this->assertEquals($expected, $actual);
            foreach ($expected as $id => $kernel) {
                $this->getJson('/api/v2/server/config?' . http_build_query($auth + ['node_id' => $id]))
                    ->assertOk()->assertJsonPath('kernel_type', $kernel);
                $this->getJson('/api/v1/server/UniProxy/config?' . http_build_query(['token' => $credential, 'node_id' => $id]))
                    ->assertOk()->assertJsonPath('kernel_type', $kernel)
                    ->assertJsonPath('protocol', Server::findOrFail($id)->type);
            }
        }
        $this->assertNull($legacy->fresh()->kernel_type);
    }
}
