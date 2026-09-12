<?php

namespace Tests\Feature\Server;

use App\Http\Controllers\V2\Admin\Server\ManageController;
use App\Models\Server;
use App\Models\ServerMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ServerSwitchTest extends TestCase
{
    use RefreshDatabase;

    private array $pushes = [];

    protected function setUp(): void
    {
        parent::setUp();
        Route::post('/_tests/server-switch', [ManageController::class, 'update']);
        Redis::shouldReceive('publish')->byDefault()->andReturnUsing(function (string $channel, string $message): int {
            $this->assertSame('node:push', $channel);
            $this->pushes[] = json_decode($message, true, flags: JSON_THROW_ON_ERROR);
            return 1;
        });
    }

    private function machine(): ServerMachine
    {
        return ServerMachine::create([
            'name' => '节点开关测试服务器',
            'token' => bin2hex(random_bytes(24)),
            'is_active' => true,
        ]);
    }

    private function node(ServerMachine $machine, int $port, array $overrides = []): Server
    {
        return Server::create(array_replace([
            'name' => '开关测试节点 ' . $port,
            'machine_id' => $machine->id,
            'type' => Server::TYPE_VLESS,
            'kernel_type' => 'xray',
            'host' => 'switch.example.invalid',
            'port' => (string) $port,
            'server_port' => $port,
            'protocol_settings' => ['tls' => 0, 'network' => 'tcp'],
            'rate' => 1,
            'enabled' => true,
            'show' => true,
        ], $overrides));
    }

    private function assertDiscovered(ServerMachine $machine, array $nodes): void
    {
        $response = $this->postJson('/api/v2/server/machine/nodes', [
            'machine_id' => $machine->id,
            'token' => $machine->token,
        ])->assertOk();
        $this->assertSame(
            array_map(fn (Server $node) => $node->id, $nodes),
            array_column($response->json('nodes'), 'id')
        );
        $this->assertTrue($machine->fresh()->is_active);
    }

    public function test_disabling_and_reenabling_one_node_preserves_its_peers_and_machine(): void
    {
        $machine = $this->machine();
        $otherMachine = $this->machine();
        $target = $this->node($machine, 24443);
        $peer = $this->node($machine, 24444, ['kernel_type' => 'singbox']);
        $other = $this->node($otherMachine, 24443);
        $peerAttributes = $peer->fresh()->getAttributes();
        $otherAttributes = $other->fresh()->getAttributes();
        $this->pushes = [];

        foreach ([false, false, true, true] as $enabled) {
            $this->postJson('/_tests/server-switch', ['id' => $target->id, 'enabled' => $enabled])
                ->assertOk()->assertJsonPath('data', true);

            $this->assertSame($enabled, $target->fresh()->enabled);
            $this->assertTrue($target->fresh()->show);
            $this->assertSame($machine->id, $target->fresh()->machine_id);
            $this->assertSame($peerAttributes, $peer->fresh()->getAttributes());
            $this->assertSame($otherAttributes, $other->fresh()->getAttributes());
            $this->assertDiscovered($machine, $enabled ? [$target, $peer] : [$peer]);
            $this->assertDiscovered($otherMachine, [$other]);
        }

        // 重复设置相同状态不产生额外通知，通知始终保留同机器上的其他节点。
        $this->assertCount(2, $this->pushes);
        foreach ($this->pushes as $push) {
            $this->assertSame($machine->id, $push['machine_id']);
            $this->assertSame('sync.nodes', $push['event']);
            $this->assertArrayNotHasKey('node_id', $push);
        }
        $this->assertSame([$peer->id], array_column($this->pushes[0]['data']['nodes'], 'id'));
        $this->assertSame([$target->id, $peer->id], array_column($this->pushes[1]['data']['nodes'], 'id'));
        $this->assertSame('singbox', $this->pushes[0]['data']['nodes'][0]['kernel_type']);
    }

    public function test_last_node_can_be_stopped_and_started_without_disabling_its_machine(): void
    {
        $machine = $this->machine();
        $node = $this->node($machine, 24443);
        $this->pushes = [];

        $this->postJson('/_tests/server-switch', ['id' => $node->id, 'enabled' => false])
            ->assertOk()->assertJsonPath('data', true);
        $this->assertDiscovered($machine, []);
        $this->postJson('/api/v2/server/handshake', [
            'machine_id' => $machine->id, 'token' => $machine->token,
        ])->assertOk();

        $this->postJson('/_tests/server-switch', ['id' => $node->id, 'enabled' => true])
            ->assertOk()->assertJsonPath('data', true);
        $this->assertDiscovered($machine, [$node]);
        $this->assertSame([], $this->pushes[0]['data']['nodes']);
        $this->assertSame([$node->id], array_column($this->pushes[1]['data']['nodes'], 'id'));
    }

    public function test_conflicting_restart_is_rejected_without_changing_other_nodes_or_sending_a_stop(): void
    {
        $machine = $this->machine();
        $peer = $this->node($machine, 24443);
        $target = $this->node($machine, 24443, ['enabled' => false]);
        $this->pushes = [];

        $this->postJson('/_tests/server-switch', ['id' => $target->id, 'enabled' => true])
            ->assertStatus(422)->assertJsonValidationErrors('server_port');
        $this->assertFalse($target->fresh()->enabled);
        $this->assertTrue($peer->fresh()->enabled);
        $this->assertDiscovered($machine, [$peer]);
        $this->assertSame([], $this->pushes);
    }

    public function test_invalid_switch_requests_do_not_change_any_node(): void
    {
        $machine = $this->machine();
        $node = $this->node($machine, 24443);
        $this->pushes = [];

        foreach ([['enabled' => false], ['id' => $node->id, 'enabled' => 'stop']] as $payload) {
            $this->postJson('/_tests/server-switch', $payload)->assertStatus(422);
        }
        $response = $this->postJson('/_tests/server-switch', ['id' => $node->id + 1000, 'enabled' => false]);
        $this->assertNotEquals(true, $response->json('data'));
        $this->assertTrue($node->fresh()->enabled);
        $this->assertDiscovered($machine, [$node]);
        $this->assertSame([], $this->pushes);
    }

    public function test_rediscovery_preserves_peers_when_the_realtime_notification_fails(): void
    {
        $machine = $this->machine();
        $target = $this->node($machine, 24443);
        $peer = $this->node($machine, 24444);
        Redis::shouldReceive('publish')->once()->andThrow(new \RuntimeException('测试推送不可用'));

        $this->postJson('/_tests/server-switch', ['id' => $target->id, 'enabled' => false])
            ->assertOk()->assertJsonPath('data', true);
        $this->assertFalse($target->fresh()->enabled);
        $this->assertTrue($peer->fresh()->enabled);
        $this->assertDiscovered($machine, [$peer]);
    }
}
