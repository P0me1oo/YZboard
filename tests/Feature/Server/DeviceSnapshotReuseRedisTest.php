<?php

namespace Tests\Feature\Server;

use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\User;
use App\Services\DeviceIpExclusion;
use App\Services\DeviceSnapshotReader;
use App\Services\DeviceStateService;
use App\Services\NodeControlStateService;
use App\Services\RealtimeStateStore;
use App\Support\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class DeviceSnapshotReuseRedisTest extends TestCase
{
    use RefreshDatabase;

    private ?\Redis $client = null;
    private RedisManager $manager;
    private string $prefix;
    private array $fresh = [];
    private array $exclusions = [];

    protected function setUp(): void
    {
        parent::setUp();
        $port = getenv('YZ_TEST_SUBSCRIPTION_REDIS_PORT');
        if (!$port || !extension_loaded('redis')) $this->markTestSkipped('需要专用本机 Redis 和 phpredis');
        $this->prefix = 'device-snapshot-reuse:' . bin2hex(random_bytes(12)) . ':';
        $this->manager = new RedisManager($this->app, 'phpredis', [
            'options' => ['prefix' => $this->prefix],
            'default' => ['host' => '127.0.0.1', 'port' => (int) $port, 'database' => 0,
                'password' => null, 'timeout' => 2],
        ]);
        $this->client = $this->manager->connection()->client();
        Redis::swap($this->manager);
        $this->mock(Setting::class, function ($mock) {
            $mock->shouldReceive('get')->andReturnUsing(fn ($key, $default = null) =>
                $key === DeviceIpExclusion::SETTING ? $this->exclusions : $default);
        });
        $this->mock(RealtimeStateStore::class, function ($mock) {
            $mock->shouldReceive('readMany')->andReturnUsing(function ($sources): array {
                $result = [];
                foreach ($sources as $source) $result[$source] = $this->fresh[$source] ?? null;
                return $result;
            });
        });
    }

    protected function tearDown(): void
    {
        if ($this->client !== null) {
            $cursor = null;
            do {
                $keys = $this->client->scan($cursor, $this->prefix . '*', 100);
                if ($keys) {
                    $this->client->setOption(\Redis::OPT_PREFIX, '');
                    $this->client->del($keys);
                    $this->client->setOption(\Redis::OPT_PREFIX, $this->prefix);
                }
            } while ($cursor !== 0);
            $this->client->close();
        }
        parent::tearDown();
    }

    public function test_unchanged_fields_reuse_filtering_but_changes_expiry_and_deletion_are_seen_immediately(): void
    {
        $reader = app(DeviceSnapshotReader::class);
        $now = time();
        $this->client->hMset('user_devices:15', ['1:8.8.8.8' => $now, '2:1.1.1.1' => $now - 300]);
        $first = $reader->read([15], $now, 300);
        $this->assertSame([15 => ['1:8.8.8.8', '2:1.1.1.1']], $first);
        $count = 0;
        $collect = function () use (&$count): array { $count++; return ['8.8.8.8']; };
        $this->assertSame(['8.8.8.8'], $reader->filtered(15, $first[15], [], [], $collect));
        // 心跳时间改变，设备内容相同，过滤结果仍可复用。
        $this->client->hset('user_devices:15', '1:8.8.8.8', $now + 5);
        $same = $reader->read([15], $now, 300);
        $this->assertSame($first, $same);
        $this->assertSame(['8.8.8.8'], $reader->filtered(15, $same[15], [], [], $collect));
        $this->assertSame(1, $count);
        $this->assertSame([15 => ['1:8.8.8.8']], $reader->read([15], $now + 1, 300));
        // 无通知的旧客户端、运维直接改写也不能被版本缓存遮蔽。
        $this->client->hdel('user_devices:15', '1:8.8.8.8');
        $this->client->hset('user_devices:15', '3:9.9.9.9', $now);
        $this->assertSame([15 => ['3:9.9.9.9']], $reader->read([15], $now + 1, 300));
        $this->client->del('user_devices:15');
        $this->assertSame([15 => []], $reader->read([15], $now + 1, 300));
        $this->client->hset('user_devices:15', '1:8.8.4.4', $now);
        $this->assertSame([15 => ['1:8.8.4.4']], $reader->read([15], $now + 1, 300));
    }

    public function test_multiple_nodes_share_devices_but_freshness_and_exclusions_are_rechecked(): void
    {
        $now = time();
        $this->client->hMset('user_devices:15', [
            '1:8.8.8.8' => $now, '2:8.8.8.8' => $now, '3:9.9.9.9' => $now,
            '1:10.0.0.1' => $now, '1:2001:4860:4860::8888' => $now,
            '2:2001:4860:4860::8844' => $now, '1:1.1.1.1' => $now - 301,
        ]);
        $this->fresh = ['node:1' => ['fresh' => true], 'node:2' => ['fresh' => true], 'node:3' => ['fresh' => false]];
        $service = new DeviceStateService();
        $expected = [15 => ['2001:4860:4860::', '8.8.8.8']];
        $this->assertSame($expected, $service->getUsersDevices([15, 16]));
        $this->assertSame($expected, $service->getUsersDevices([16, 15]));
        $this->fresh['node:1']['fresh'] = $this->fresh['node:2']['fresh'] = false;
        $this->assertSame([], $service->getUsersDevices([15]));
        $this->fresh['node:1']['fresh'] = true;
        $this->exclusions = ['8.8.8.8'];
        $this->assertSame([15 => ['2001:4860:4860::']], $service->getUsersDevices([15]));
        // 运行状态被删除时沿用已有旧协议语义，不能保留“过期”的缓存结论。
        $this->fresh = [];
        $this->assertSame([15 => ['2001:4860:4860::', '9.9.9.9']], $service->getUsersDevices([15]));
    }

    public function test_fallback_for_large_or_nonstandard_records_preserves_original_results(): void
    {
        $now = time();
        $records = [];
        for ($i = 1; $i <= 1025; $i++) $records[$i . ':8.8.8.8'] = $now;
        $this->client->hMset('user_devices:15', $records);
        $reader = app(DeviceSnapshotReader::class);
        $this->assertNull($reader->read([15], $now, 300));
        $this->assertSame([15 => ['8.8.8.8']], (new DeviceStateService())->getUsersDevices([15]));
        $this->client->del('user_devices:15');
        $this->client->hset('user_devices:15', '1:1.1.1.1', $now . 'suffix');
        $this->assertNull($reader->read([15], $now, 300));
        $this->assertSame([15 => ['1.1.1.1']], (new DeviceStateService())->getUsersDevices([15]));
    }

    public function test_wrong_type_never_returns_a_previous_success(): void
    {
        $reader = app(DeviceSnapshotReader::class);
        $this->client->hset('user_devices:15', '1:8.8.8.8', time());
        $reader->read([15], time(), 300);
        $this->client->del('user_devices:15');
        $this->client->set('user_devices:15', 'wrong-type');
        try {
            $reader->read([15], time(), 300);
            $this->fail('错误类型不能返回旧快照');
        } catch (\RedisException | \RuntimeException $error) {
            $this->assertNotSame('', $error->getMessage());
        }
        $this->client->del('user_devices:15');
        $this->assertSame([15 => []], $reader->read([15], time(), 300));
    }

    public function test_real_runtime_expiry_and_recovery_do_not_reuse_old_freshness(): void
    {
        $this->app->forgetInstance(RealtimeStateStore::class);
        $this->freezeTime();
        $store = app(RealtimeStateStore::class);
        $epoch = $store->begin('node:1', str_repeat('a', 32))['epoch'];
        $store->accept('node:1', $epoch, 1, []);
        $this->client->hset('user_devices:15', '1:8.8.8.8', time());
        $service = new DeviceStateService();
        $this->assertSame([15 => ['8.8.8.8']], $service->getUsersDevices([15]));
        $this->travel(35)->seconds();
        $this->assertSame([15 => ['8.8.8.8']], $service->getUsersDevices([15]));
        $this->travel(1)->seconds();
        $this->assertSame([], $service->getUsersDevices([15]));
        $store->accept('node:1', $epoch, 2, []);
        $this->assertSame([15 => ['8.8.8.8']], $service->getUsersDevices([15]));
    }

    public function test_redis_key_expiry_invalidates_the_memo(): void
    {
        $reader = app(DeviceSnapshotReader::class);
        $this->client->hset('user_devices:15', '1:8.8.8.8', time());
        $this->assertSame([15 => ['1:8.8.8.8']], $reader->read([15], time(), 300));
        $this->client->pExpire('user_devices:15', 1);
        usleep(10000);
        $this->assertSame([15 => []], $reader->read([15], time(), 300));
    }

    public function test_many_users_are_batched_and_evicted_entries_can_be_reloaded(): void
    {
        $reader = app(DeviceSnapshotReader::class);
        $ids = range(1, 520);
        foreach ($ids as $id) $this->client->hset('user_devices:' . $id, '1:8.8.8.8', time());
        $first = $reader->read($ids, time(), 300);
        $this->assertCount(520, $first);
        $this->assertSame($first, $reader->read($ids, time(), 300));
        $this->client->del('user_devices:1');
        $this->assertSame([1 => [], 520 => ['1:8.8.8.8']], $reader->read([1, 520], time(), 300));
    }

    public function test_later_batch_failure_is_not_returned_as_partial_success(): void
    {
        $service = new DeviceStateService();
        $ids = range(1, 65);
        foreach ($ids as $id) $this->client->hset('user_devices:' . $id, '1:8.8.8.8', time());
        $this->assertCount(65, $service->getUsersDevices($ids));
        $this->client->del('user_devices:1', 'user_devices:65');
        $this->client->hset('user_devices:1', '1:1.1.1.1', time());
        $this->client->set('user_devices:65', 'wrong-type');
        try {
            $service->getUsersDevices($ids);
            $this->fail('后续批次失败不能返回部分成功');
        } catch (\RedisException | \RuntimeException $error) {
            $this->assertNotSame('', $error->getMessage());
        }
        $this->client->del('user_devices:65');
        $this->client->hset('user_devices:65', '1:9.9.9.9', time());
        $snapshot = $service->getUsersDevices($ids);
        $this->assertSame(['1.1.1.1'], $snapshot[1]);
        $this->assertSame(['9.9.9.9'], $snapshot[65]);
        $snapshot[1][0] = 'modified';
        $this->assertSame(['1.1.1.1'], $service->getUsersDevices([1])[1]);
    }

    public function test_machine_protection_is_checked_before_reusing_devices(): void
    {
        $machine = ServerMachine::withoutEvents(fn () => ServerMachine::create([
            'name' => '设备快照机器保护', 'token' => bin2hex(random_bytes(32)), 'is_active' => true,
        ]));
        $node = Server::withoutEvents(fn () => Server::create([
            'name' => '机器保护节点', 'type' => Server::TYPE_VMESS, 'host' => '127.0.0.1',
            'port' => 443, 'server_port' => 443, 'rate' => '1', 'group_ids' => [1],
            'enabled' => true, 'machine_id' => $machine->id,
        ]));
        $user = User::withoutEvents(fn () => User::create([
            'email' => 'machine-snapshot@example.invalid', 'password' => 'test-only',
            'uuid' => \App\Utils\Helper::guid(true), 'token' => \App\Utils\Helper::guid(),
            'group_id' => 1, 'group_ids' => [1], 'u' => 0, 'd' => 0,
            'transfer_enable' => 1000, 'banned' => false, 'expired_at' => null,
        ]));
        $this->client->hset('user_devices:' . $user->id, '1:8.8.8.8', time());
        $service = new NodeControlStateService();
        $first = $service->devices($node);
        $this->assertNotSame([], $first['users']);
        $machine->forceFill(['is_active' => false])->saveQuietly();
        $this->assertSame([], $service->devices($node)['users']);
        $machine->forceFill(['is_active' => true])->saveQuietly();
        $this->assertSame($first['users'], $service->devices($node)['users']);
        $machine->forceFill(['traffic_limit' => 1000, 'traffic_state' => ['initialized' => false]])->saveQuietly();
        $this->assertSame([], $service->devices($node)['users']);
        foreach ([['reported_at' => time() - 181, 'tx' => 0], ['reported_at' => time(), 'tx' => 1000]] as $state) {
            $machine->forceFill(['traffic_state' => $state + ['initialized' => true]])->saveQuietly();
            $this->assertSame([], $service->devices($node)['users']);
        }
        $machine->forceFill(['traffic_state' => ['initialized' => true, 'reported_at' => time(), 'tx' => 0]])->saveQuietly();
        $this->assertSame($first['users'], $service->devices($node)['users']);
    }

    public function test_full_snapshot_keeps_fresh_permission_queries_and_shared_version_order(): void
    {
        $node = Server::withoutEvents(fn () => Server::create([
            'name' => '快照复用测试', 'type' => Server::TYPE_VMESS, 'host' => '127.0.0.1',
            'port' => 443, 'server_port' => 443, 'rate' => '1', 'group_ids' => [1], 'enabled' => true,
        ]));
        $user = User::withoutEvents(fn () => User::create([
            'email' => 'snapshot@example.invalid', 'password' => 'test-only',
            'uuid' => \App\Utils\Helper::guid(true), 'token' => \App\Utils\Helper::guid(),
            'group_id' => 1, 'group_ids' => [1], 'u' => 0, 'd' => 0,
            'transfer_enable' => 1000, 'banned' => false, 'expired_at' => null,
        ]));
        $this->client->hset('user_devices:' . $user->id, '1:8.8.8.8', time());
        $queries = 0;
        DB::listen(function ($event) use (&$queries) {
            if (str_contains($event->sql, 'u + d < transfer_enable')) $queries++;
        });
        $service = new NodeControlStateService();
        $first = $service->devices($node);
        $this->assertSame($first, $service->devices($node));
        $this->assertSame(2, $queries, '快照复用不能跳过权限查询');
        foreach ([['banned' => true], ['banned' => false, 'expired_at' => time() - 1],
            ['expired_at' => null, 'u' => 1000], ['u' => 0, 'group_id' => 2, 'group_ids' => [2]]] as $attributes) {
            User::withoutEvents(fn () => $user->forceFill($attributes)->save());
            $snapshot = $service->devices($node);
            $this->assertSame([], $snapshot['users']);
        }
        User::withoutEvents(fn () => $user->forceFill(['group_id' => 1, 'group_ids' => [1]])->save());
        $restored = $service->devices($node);
        $this->assertSame($first['users'], $restored['users']);
        $this->assertGreaterThan($first['sequence'], $restored['sequence']);
        Cache::forget('realtime:control:devices:' . $node->id);
        $new = $service->devices($node);
        $this->assertNotSame($restored['epoch'], $new['epoch']);
        $this->assertSame($restored['users'], $new['users']);
    }

    public function test_client_serialization_and_overridden_reader_keep_the_original_path(): void
    {
        $reader = app(DeviceSnapshotReader::class);
        $this->client->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
        $this->assertNull($reader->read([15], time(), 300));
        $this->client->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_NONE);
        $service = new class extends DeviceStateService {
            public function getDeviceIPs(int $userId, bool $legacyOnly = false): array { return ['8.8.4.4']; }
        };
        $this->assertSame([15 => ['8.8.4.4']], $service->getUsersDevices([15]));
    }
}
