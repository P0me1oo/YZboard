<?php

namespace Tests\Feature\Server;

use App\Models\Server;
use App\Models\User;
use App\Services\DeviceStateService;
use App\Services\NodeRegistry;
use App\Services\Plugin\HookManager;
use App\WebSocket\NodeWorker;
use App\Support\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;
use Workerman\Connection\TcpConnection;

class DeviceHandoverTest extends TestCase
{
    use RefreshDatabase;

    private Server $node;
    private User $user;
    private string $run;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->mock(Setting::class, function ($mock): void {
            $mock->shouldReceive('get')->andReturnUsing(fn ($key, $default = null) => [
                'server_token' => 'device-handover-test-only', 'server_ws_enable' => 0,
            ][$key] ?? $default);
        });
        $this->mock(DeviceStateService::class, fn ($mock) => $mock->shouldReceive('getDeviceSources')->andReturn([]));
        $this->node = Server::create([
            'name' => '设备换网测试', 'type' => Server::TYPE_VMESS, 'host' => '127.0.0.1',
            'port' => 443, 'server_port' => 443, 'rate' => '1', 'group_ids' => [1], 'enabled' => true,
        ]);
        $this->user = User::create([
            'email' => 'handover@example.invalid', 'password' => 'unused-handover-test',
            'uuid' => '33333333-3333-3333-3333-333333333333', 'token' => str_repeat('c', 32),
            'group_id' => 1, 'transfer_enable' => 100000, 'expired_at' => time() + 3600, 'device_limit' => 1,
        ]);
        $this->run = str_repeat('a', 32);
    }

    private function auth(): array
    {
        return ['token' => 'device-handover-test-only', 'node_id' => $this->node->id];
    }

    private function deviceRequest(string $action, array $data = [])
    {
        return $this->postJson('/api/v2/server/device-handover/' . $action, $this->auth() + ['run' => $this->run] + $data);
    }

    private function admit(string $ip, int $sequence)
    {
        return $this->deviceRequest('admit', ['sequence' => $sequence, 'user_id' => $this->user->id, 'ip' => $ip]);
    }

    private function source(string $ip, string $lease, int $sequence): array
    {
        return ['user_id' => $this->user->id, 'ip' => $ip, 'lease' => $lease, 'connect_sequence' => $sequence, 'age_ms' => 0];
    }

    public function test_http_renewal_requires_a_matching_full_baseline_and_excludes_source_payloads(): void
    {
        $this->postJson('/api/v2/server/handshake', $this->auth() + ['device_handover' => 1])->assertOk()
            ->assertJsonPath('realtime.device_handover_ws', 1)->assertJsonPath('realtime.device_handover_renewal', 1);
        $this->deviceRequest('begin')->assertOk();
        $this->deviceRequest('sync', ['sequence' => 1, 'pending' => [], 'sources' => []])->assertOk();
        $renewal = ['sequence' => 2, 'unchanged' => true, 'base_sequence' => 1];
        $this->deviceRequest('sync', $renewal + ['sources' => []])->assertStatus(422);
        $this->deviceRequest('sync', $renewal + ['retired' => []])->assertStatus(422);
        $this->deviceRequest('sync', ['sequence' => 2, 'unchanged' => true])->assertStatus(422);
        $this->deviceRequest('sync', ['sequence' => 3, 'unchanged' => true, 'base_sequence' => 2])->assertStatus(412);
        $this->deviceRequest('sync', $renewal)->assertOk()->assertJsonPath('data.sequence', 2);
        $this->deviceRequest('sync', $renewal)->assertOk()->assertJsonPath('data.sequence', 2);
    }

    public function test_websocket_and_http_share_validation_permissions_and_idempotent_grants(): void
    {
        $frames = [];
        $connection = \Mockery::mock(TcpConnection::class);
        $connection->realtime = true;
        $connection->nodeId = (int) $this->node->id;
        $connection->shouldReceive('send')->andReturnUsing(function ($data) use (&$frames) {
            $frames[] = json_decode($data, true);
            return true;
        });
        $worker = (new \ReflectionClass(NodeWorker::class))->newInstanceWithoutConstructor();
        NodeRegistry::add((int) $this->node->id, $connection);
        $send = function (string $action, array $data = []) use ($worker, $connection): void {
            $worker->onMessage($connection, json_encode(['event' => 'device.' . $action,
                'data' => ['run' => $this->run, 'request_id' => 'device-rpc-test', 'node_id' => $this->node->id] + $data]));
        };
        try {
            $send('begin');
            $this->assertSame('device.begin.ack', $frames[0]['event']);
            $this->assertSame($this->run, $frames[0]['data']['result']['run']);
            $send('sync', ['sequence' => 1, 'pending' => []]);
            $this->assertSame('device.sync.error', $frames[1]['event']);
            $this->assertSame(422, $frames[1]['data']['code']);
            $send('admit', ['sequence' => 2, 'user_id' => $this->user->id, 'ip' => '8.8.8.8']);
            $this->assertTrue($frames[2]['data']['accepted']);
            $this->assertSame('device-rpc-test', $frames[2]['data']['request_id']);
            $lease = $frames[2]['data']['result']['lease'];
            $this->admit('8.8.8.8', 2)->assertOk()->assertJsonPath('data.lease', $lease);
            $send('sync', ['sequence' => 3, 'pending' => [], 'sources' => [$this->source('8.8.8.8', $lease, 2)]]);
            $send('sync', ['sequence' => 4, 'unchanged' => true, 'base_sequence' => 3]);
            $this->assertSame(4, $frames[4]['data']['result']['sequence']);
            $this->user->forceFill(['group_id' => 2])->saveQuietly();
            $send('admit', ['sequence' => 5, 'user_id' => $this->user->id, 'ip' => '1.1.1.1']);
            $this->assertSame('device.admit.error', $frames[5]['event']);
            $this->assertSame(403, $frames[5]['data']['code']);
        } finally {
            NodeRegistry::remove((int) $this->node->id, $connection);
        }
    }

    public function test_authenticated_requests_replace_only_after_complete_close_snapshot(): void
    {
        $this->postJson('/api/v2/server/handshake', $this->auth())->assertOk()->assertJsonPath('realtime.device_handover', 1);
        $this->deviceRequest('begin')->assertOk()->assertJsonPath('data.version', 1);
        $lease = $this->admit('8.8.8.8', 1)->assertOk()->assertJsonPath('data.status', 'allowed')->json('data.lease');
        $this->deviceRequest('sync', ['sequence' => 3, 'pending' => [], 'sources' => [$this->source('8.8.8.8', $lease, 2)]])->assertOk();
        $this->admit('1.1.1.1', 4)->assertOk()->assertJsonPath('data.status', 'waiting')
            ->assertJsonPath('data.revoked.0.lease', $lease);
        $this->deviceRequest('sync', ['sequence' => 5, 'pending' => [], 'sources' => [$this->source('8.8.8.8', $lease, 2)]])->assertOk();
        $this->admit('1.1.1.1', 6)->assertOk()->assertJsonPath('data.status', 'waiting');
        $this->deviceRequest('sync', ['sequence' => 7, 'pending' => [], 'sources' => []])->assertOk();
        $this->admit('1.1.1.1', 8)->assertOk()->assertJsonPath('data.status', 'allowed');
        $this->admit('8.8.8.8', 9)->assertOk()->assertJsonPath('data.reason', 'cooldown');
    }

    public function test_missing_or_invalid_snapshot_cannot_free_existing_source(): void
    {
        $this->deviceRequest('begin')->assertOk();
        $lease = $this->admit('8.8.8.8', 1)->assertOk()->json('data.lease');
        $this->deviceRequest('sync', ['sequence' => 3, 'pending' => [], 'sources' => [$this->source('8.8.8.8', $lease, 2)]])->assertOk();
        $this->deviceRequest('sync', ['sequence' => 4, 'pending' => []])->assertStatus(422);
        $invalid = $this->source('8.8.8.8', $lease, 99);
        $this->deviceRequest('sync', ['sequence' => 4, 'pending' => [], 'sources' => [$invalid]])->assertStatus(422);
        $this->deviceRequest('begin')->assertOk();
        $this->admit('1.1.1.1', 5)->assertOk()->assertJsonPath('data.status', 'waiting');
    }

    public function test_node_cannot_admit_an_account_outside_its_permission_group(): void
    {
        $this->deviceRequest('begin')->assertOk();
        $this->user->forceFill(['group_id' => 2])->saveQuietly();
        $this->admit('8.8.8.8', 1)->assertStatus(403);
    }

    public function test_restarted_node_rejects_reports_from_its_previous_run(): void
    {
        $this->deviceRequest('begin')->assertOk();
        $this->postJson('/api/v2/server/device-handover/begin', $this->auth() + ['run' => str_repeat('b', 32)])->assertOk();
        $this->deviceRequest('sync', ['sequence' => 1, 'pending' => [], 'sources' => []])->assertStatus(409);
        $this->admit('8.8.8.8', 2)->assertStatus(409);
    }

    public function test_single_user_admission_preserves_full_user_list_plugin_filter(): void
    {
        User::create([
            'email' => 'second-handover@example.invalid', 'password' => 'unused-handover-test',
            'uuid' => '44444444-4444-4444-4444-444444444444', 'token' => str_repeat('d', 32),
            'group_id' => 1, 'transfer_enable' => 100000, 'expired_at' => time() + 3600,
        ]);
        $seen = [];
        $filter = function ($users) use (&$seen) {
            $seen = $users->pluck('id')->all();
            return $users->reject(fn ($user) => (int) $user->id === $this->user->id)->values();
        };
        HookManager::registerFilter('server.users.get', $filter);
        try {
            $this->deviceRequest('begin')->assertOk();
            $this->admit('8.8.8.8', 1)->assertStatus(403);
            $this->assertCount(2, $seen);
            $this->assertContains($this->user->id, $seen);
        } finally {
            HookManager::remove('server.users.get', $filter);
        }
    }
}
