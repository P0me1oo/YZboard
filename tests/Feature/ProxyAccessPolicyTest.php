<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Models\ServerMachine;
use App\Services\LogSettings;
use App\WebSocket\NodeEventHandlers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;
use Workerman\Connection\TcpConnection;

class ProxyAccessPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_switch_reaches_handshake_machine_control_and_both_runtime_receipts(): void
    {
        $machine = ServerMachine::create(['name' => '访问策略测试', 'token' => Str::random(32), 'is_active' => true]);
        $node = Server::create(['name' => '访问策略节点', 'machine_id' => $machine->id, 'type' => 'vless',
            'host' => '127.0.0.1', 'port' => 443, 'server_port' => 24443, 'rate' => 1, 'group_ids' => [], 'enabled' => true]);
        $auth = ['machine_id' => $machine->id, 'token' => $machine->token];
        $nodeAuth = $auth + ['node_id' => $node->id, 'device_handover' => 1];
        $nodeEpoch = $this->postJson('/api/v2/server/realtime/begin', $nodeAuth + ['run' => str_repeat('a', 32)])
            ->assertOk()->json('data.epoch');
        $machineEpoch = $this->postJson('/api/v2/server/machine/realtime/begin', $auth + ['run' => str_repeat('b', 32)])
            ->assertOk()->json('data.epoch');
        foreach ([true, false, true] as $index => $enabled) {
            $settings = LogSettings::view();
            $settings['access'] = ['enabled' => $enabled, 'retention_days' => 30];
            LogSettings::save($settings);
            $generation = \App\Services\ProxyAccessLog::policy()['generation'];
            foreach ([$auth, $nodeAuth] as $handshakeAuth) {
                $this->postJson('/api/v2/server/handshake', $handshakeAuth)->assertOk()
                    ->assertJsonPath('access_log.version', 2)->assertJsonPath('access_log.enabled', $enabled)
                    ->assertJsonPath('access_log.generation', $generation)
                    ->assertJsonPath('access_log.retention_days', 30)->assertJsonPath('block_log.version', 1);
            }
            $this->postJson('/api/v2/server/machine/nodes', $auth)->assertOk()->assertJsonPath('access_log.enabled', $enabled)
                ->assertJsonPath('access_log.generation', $generation);
            $this->postJson('/api/v2/server/machine/control', $auth + ['version' => 'v2.39.0', 'boot_id' => 'access-policy-test', 'manageable' => false])
                ->assertOk()->assertJsonPath('access_log.enabled', $enabled)->assertJsonPath('access_log.generation', $generation);
            foreach ([['/api/v2/server/realtime/state', $nodeAuth, $nodeEpoch],
                ['/api/v2/server/machine/realtime/state', $auth, $machineEpoch]] as [$url, $identity, $epoch]) {
                $this->postJson($url, $identity + ['epoch' => $epoch, 'sequence' => $index + 1, 'telemetry_mode' => 1, 'state' => []])
                    ->assertOk()->assertJsonPath('data.access_log.version', 2)->assertJsonPath('data.access_log.enabled', $enabled)
                    ->assertJsonPath('data.access_log.generation', $generation);
            }
            $connection = \Mockery::mock(TcpConnection::class);
            $connection->realtime = true;
            $connection->shouldReceive('send')->once()->withArgs(function ($raw) use ($enabled, $generation): bool {
                $message = json_decode($raw, true);
                $this->assertSame('state.ack', $message['event']);
                $this->assertSame(2, $message['data']['access_log']['version']);
                $this->assertSame($enabled, $message['data']['access_log']['enabled']);
                $this->assertSame($generation, $message['data']['access_log']['generation']);
                return true;
            });
            NodeEventHandlers::handleRuntimeState($connection, $node->id,
                ['epoch' => $nodeEpoch, 'sequence' => $index + 1, 'state' => []]);
        }
    }
}
