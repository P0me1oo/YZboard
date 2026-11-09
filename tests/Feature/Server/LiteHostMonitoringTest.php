<?php

namespace Tests\Feature\Server;

use App\Models\NodeReportBatch;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\ServerMachineLoadHistory;
use App\Models\User;
use App\Services\HostMonitoring;
use App\Services\LiteClient;
use App\Services\LiteMonitor;
use App\Services\MachineStateService;
use App\Services\RealtimeStateStore;
use App\Support\Setting;
use App\Utils\CacheKey;
use App\WebSocket\NodeEventHandlers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;
use Workerman\Connection\TcpConnection;

class LiteHostMonitoringTest extends TestCase
{
    use RefreshDatabase;

    private ServerMachine $machine;
    private Server $node;
    private const UUID = '11111111-1111-4111-8111-111111111111';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->mock(Setting::class, fn ($mock) => $mock->shouldReceive('get')->andReturnUsing(fn ($key) =>
            ['server_ws_enable' => 0, 'server_token' => 'lite-host-test-only'][$key] ?? null));
        $this->machine = ServerMachine::create(['name' => 'Lite 监控测试', 'token' => 'lite-host-test-only', 'is_active' => true]);
        $this->node = Server::create(['machine_id' => $this->machine->id, 'name' => '业务节点', 'type' => 'vmess',
            'host' => '127.0.0.1', 'port' => 443, 'server_port' => 443, 'rate' => '1', 'group_ids' => [1], 'enabled' => true]);
        $this->mock(LiteClient::class, function ($mock): void {
            $mock->shouldReceive('normalizeUrl')->andReturn('https://lite.example.test');
            $mock->shouldReceive('read')->andReturnUsing(fn ($connection, $method) => match ($method) {
                'admin:listClients' => [['uuid' => self::UUID, 'name' => 'Lite', 'ipv4' => '8.8.8.8']],
                'common:getNodes' => [],
                default => throw new \RuntimeException('策略和状态接收不得读取 Lite'),
            });
        });
    }

    private function configure(bool $enabled = true): void
    {
        app(LiteMonitor::class)->save(['url' => 'https://lite.example.test', 'api_key' => 'test-only-key', 'enabled' => $enabled]);
    }

    private function bind(): void
    {
        app(LiteMonitor::class)->bind(['action' => 'bind', 'machine_id' => $this->machine->id, 'uuid' => self::UUID]);
    }

    private function auth(bool $node = false): array
    {
        return ['machine_id' => $this->machine->id, 'token' => $this->machine->token]
            + ($node ? ['node_id' => $this->node->id] : []);
    }

    private function load(): array
    {
        return ['cpu' => 23, 'mem' => ['total' => 1024, 'used' => 256], 'disk' => ['total' => 2000, 'used' => 300],
            'net' => ['in_speed' => 10, 'out_speed' => 20], 'kernel_status' => true];
    }

    public function test_binding_disable_unbind_and_reenable_change_all_policy_endpoints(): void
    {
        $assertPolicy = function (bool $enabled): void {
            $this->postJson('/api/v2/server/machine/nodes', $this->auth())->assertOk()->assertJsonPath('host_monitoring', $enabled);
            $this->postJson('/api/v2/server/handshake', $this->auth(true))->assertOk()->assertJsonPath('host_monitoring', $enabled);
            $this->postJson('/api/v2/server/machine/control', $this->auth() +
                ['version' => 'v2.35.0', 'boot_id' => 'lite-test', 'manageable' => false])
                ->assertOk()->assertJsonPath('host_monitoring', $enabled);
            $this->assertNotNull($this->machine->fresh()->last_seen_at);
        };
        $assertPolicy(true);
        $this->configure();
        $assertPolicy(true);
        $this->bind();
        $assertPolicy(false);
        $this->postJson('/api/v2/server/handshake', $this->auth())->assertOk()->assertJsonPath('host_monitoring', false);
        $this->postJson('/api/v2/server/handshake', ['node_id' => $this->node->id, 'token' => 'lite-host-test-only'])
            ->assertOk()->assertJsonPath('host_monitoring', false);
        $other = ServerMachine::create(['name' => '未绑定', 'token' => 'other-test-only', 'is_active' => true]);
        $this->assertTrue(HostMonitoring::enabled($other));
        $this->configure(false);
        $assertPolicy(true);
        $this->assertDatabaseCount('v2_lite_machine', 1);
        $this->configure();
        $assertPolicy(false);
        app(LiteMonitor::class)->bind(['action' => 'unbind', 'machine_id' => $this->machine->id]);
        $assertPolicy(true);
    }

    public function test_legacy_machine_reports_stop_history_but_keep_heartbeat_and_resume(): void
    {
        $this->postJson('/api/v2/server/machine/status', $this->auth() + $this->load())->assertOk();
        $this->assertDatabaseCount('v2_server_machine_load_history', 1);
        $before = $this->machine->fresh()->load_status;
        $this->configure();
        $this->bind();
        $this->travel(2)->seconds();
        for ($i = 0; $i < 2; $i++) {
            $this->postJson('/api/v2/server/machine/status', $this->auth() + ['cpu' => 80] + $this->load())->assertOk();
        }
        $this->assertDatabaseCount('v2_server_machine_load_history', 1);
        $this->assertSame($before, $this->machine->fresh()->load_status);
        $this->assertSame(now()->timestamp, $this->machine->fresh()->last_seen_at);
        $this->configure(false);
        $this->postJson('/api/v2/server/machine/status', $this->auth() + ['cpu' => 80] + $this->load())->assertOk();
        $this->assertDatabaseCount('v2_server_machine_load_history', 2);
        $this->assertEquals(80, $this->machine->fresh()->load_status['cpu']);
    }

    public function test_auto_bind_and_import_use_the_same_monitoring_policy(): void
    {
        $this->configure();
        $this->machine->update(['agent_runtime' => ['public_ipv4' => '8.8.8.8']]);
        $monitor = app(LiteMonitor::class);
        $this->assertSame(1, $monitor->bind(['action' => 'auto'])['count']);
        $this->assertFalse(HostMonitoring::enabled($this->machine));
        $this->assertSame(0, $monitor->bind(['action' => 'auto'])['count']);
        $monitor->bind(['action' => 'unbind', 'machine_id' => $this->machine->id]);
        $this->assertTrue(HostMonitoring::enabled($this->machine));
        $result = $monitor->bind(['action' => 'import', 'uuids' => [self::UUID]]);
        $imported = ServerMachine::findOrFail($result['created_ids'][0]);
        $this->assertFalse(HostMonitoring::enabled($imported));
        $this->configure(false);
        $this->assertTrue(HostMonitoring::enabled($imported));
    }

    public function test_realtime_machine_state_ignores_old_samples_and_recovers_after_unbinding(): void
    {
        $session = $this->postJson('/api/v2/server/machine/realtime/begin', $this->auth() + ['run' => str_repeat('a', 32)])->assertOk()->json('data');
        $this->configure();
        $this->bind();
        $body = ['epoch' => $session['epoch'], 'sequence' => 1, 'telemetry_mode' => 1, 'state' => ['status' => $this->load()]];
        $this->postJson('/api/v2/server/machine/realtime/state', $this->auth() + $body)->assertOk()->assertJsonPath('data.host_monitoring', false);
        // WebSocket 机器回传共用此服务；重复、乱序和空心跳均不能落历史。
        $service = app(MachineStateService::class);
        $this->assertFalse($service->accept($this->machine, $body)['accepted']);
        $body['sequence'] = 3;
        $body['state'] = [];
        $service->accept($this->machine, $body);
        $body['sequence'] = 2;
        $body['state'] = ['status' => $this->load()];
        $this->assertFalse($service->accept($this->machine, $body)['accepted']);
        $snapshot = app(RealtimeStateStore::class)->read('machine:' . $this->machine->id);
        $this->assertTrue($snapshot['fresh']);
        $this->assertSame([], $snapshot['data']);
        $this->assertSame(0, ServerMachineLoadHistory::count());
        app(LiteMonitor::class)->bind(['action' => 'unbind', 'machine_id' => $this->machine->id]);
        $body['sequence'] = 4;
        $service->accept($this->machine, $body);
        $this->assertSame(1, ServerMachineLoadHistory::count());
        $this->assertEquals(23, $this->machine->fresh()->load_status['cpu']);
    }

    public function test_node_business_state_and_traffic_survive_lite_over_http_and_websocket(): void
    {
        $this->mock(\App\Services\DeviceStateService::class, fn ($mock) => $mock
            ->shouldReceive('replaceNodeDevices')->times(4)->with($this->node->id, [1 => ['8.8.8.8']]));
        Redis::shouldReceive('sadd')->andReturn(1);
        $this->configure();
        $this->bind();
        $user = User::forceCreate(['id' => 1, 'email' => 'lite-host@example.invalid', 'password' => 'unused',
            'uuid' => self::UUID, 'token' => 'user-test-only', 'group_id' => 1, 'transfer_enable' => 100000]);
        $session = $this->postJson('/api/v2/server/realtime/begin', $this->auth(true) + ['run' => str_repeat('b', 32)])->assertOk()->json('data');
        $state = ['status' => $this->load(), 'metrics' => ['cpu_per_core' => [20], 'load' => ['load1' => 1],
            'active_connections' => 3, 'kernel_status' => true, 'uptime' => 99],
            'connection_counts' => [1 => 3], 'ip_connection_counts' => [1 => ['8.8.8.8' => 3]],
            'online' => [1 => 1], 'alive' => [1 => ['8.8.8.8']], 'user_speeds' => [1 => [100, 200]]];
        $body = ['epoch' => $session['epoch'], 'sequence' => 1, 'state' => $state];
        $this->postJson('/api/v2/server/realtime/state', $this->auth(true) + $body)->assertOk()->assertJsonPath('data.host_monitoring', false);
        $connection = \Mockery::mock(TcpConnection::class);
        $connection->realtime = true;
        $connection->shouldReceive('send')->once()->withArgs(fn ($data) => json_decode($data, true)['data']['host_monitoring'] === false);
        $body['sequence'] = 2;
        NodeEventHandlers::handleRuntimeState($connection, $this->node->id, $body);
        $snapshot = app(RealtimeStateStore::class)->read('node:' . $this->node->id)['data'];
        $this->assertSame(['kernel_status' => true], $snapshot['status']);
        $this->assertArrayNotHasKey('load', $snapshot['metrics']);
        $this->assertArrayNotHasKey('cpu_per_core', $snapshot['metrics']);
        foreach (['connection_counts', 'ip_connection_counts', 'online', 'alive', 'user_speeds'] as $key) {
            $this->assertEquals($state[$key], $snapshot[$key]);
        }
        $this->postJson('/api/v2/server/report', $this->auth(true) + $state)->assertOk();
        $metrics = Cache::get(CacheKey::get('SERVER_VMESS_METRICS', $this->node->id));
        $this->assertSame(3, $metrics['active_connections']);
        $this->assertTrue($metrics['kernel_status']);
        $this->assertArrayNotHasKey('load', $metrics);
        $this->assertNull(Cache::get(CacheKey::get('SERVER_VMESS_LOAD_STATUS', $this->node->id)));
        $view = app(\App\Services\RealtimeSnapshotService::class)->snapshot([
            'users' => [], 'nodes' => [$this->node->id], 'machines' => [$this->machine->id], 'devices' => [], 'system' => false,
        ]);
        $this->assertNull($view['nodes'][$this->node->id]['load_status']);
        $this->assertTrue($view['nodes'][$this->node->id]['metrics']['kernel_status']);
        Bus::fake();
        $traffic = ['realtime' => true, 'report_id' => 'lite-host-batch', 'traffic' => [$user->id => [10, 20]]];
        $this->postJson('/api/v2/server/report', $this->auth(true) + $traffic)->assertOk()->assertJsonPath('receipt.accepted', true);
        $this->postJson('/api/v2/server/report', $this->auth(true) + $traffic)->assertOk();
        $this->assertSame(1, NodeReportBatch::count());
        (new \App\Jobs\ProcessNodeReportBatch(NodeReportBatch::first()->id))->handle();
        $this->assertSame(10, (int) $user->fresh()->u);
        $this->assertSame(20, (int) $user->fresh()->d);
        $this->assertDatabaseCount('v2_server_machine_load_history', 0);
        $this->configure(false);
        $body['sequence'] = 3;
        $this->postJson('/api/v2/server/realtime/state', $this->auth(true) + $body)->assertOk()->assertJsonPath('data.host_monitoring', true);
        $this->assertEquals(23, Cache::get(CacheKey::get('SERVER_VMESS_LOAD_STATUS', $this->node->id))['cpu']);
    }
}
