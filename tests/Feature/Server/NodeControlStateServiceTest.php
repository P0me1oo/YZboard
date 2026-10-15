<?php

namespace Tests\Feature\Server;

use App\Models\Server;
use App\Models\User;
use App\Services\DeviceSyncScheduler;
use App\Services\NodeControlStateService;
use App\Services\RealtimeStateStore;
use App\Support\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NodeControlStateServiceTest extends TestCase
{
    use RefreshDatabase;

    private Server $node;
    private NodeControlStateService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->mock(Setting::class, fn ($mock) => $mock->shouldReceive('get')->andReturnNull());
        $this->node = Server::create([
            'name' => '配置版本回归测试', 'type' => Server::TYPE_VMESS,
            'host' => '127.0.0.1', 'port' => 443, 'server_port' => 443,
            'rate' => '1', 'group_ids' => [], 'enabled' => true,
        ]);
        $this->service = new NodeControlStateService();
    }

    public function test_wrong_cache_contents_are_replaced_without_changing_settings_or_other_nodes(): void
    {
        $settings = ['app_name' => '缓存隔离测试', 'stop_register' => 0];
        Cache::forever('admin_settings', $settings);
        $otherKey = 'realtime:control:control:999999';
        $other = ['epoch' => str_repeat('b', 32), 'sequence' => 8, 'hash' => str_repeat('c', 64)];
        Cache::forever($otherKey, $other);

        foreach (['control' => 'snapshot', 'devices' => 'devices'] as $part => $method) {
            $before = $this->service->$method($this->node);
            $key = 'realtime:control:' . $part . ':' . $this->node->id;
            $metadata = Cache::get($key);
            // 保持内容摘要不变，验证损坏记录仍会完整重建并持久保存。
            Cache::forever($key, $settings + ['sequence' => $metadata['sequence'], 'hash' => $metadata['hash']]);

            $after = $this->service->$method($this->node);
            $this->assertNotSame($before['epoch'], $after['epoch']);
            $this->assertSame(1, $after['sequence']);
            $this->assertEqualsCanonicalizing(['epoch', 'sequence', 'hash'], array_keys(Cache::get($key)));
            $this->assertSame($after, (new NodeControlStateService())->$method($this->node));
        }

        $this->assertSame($settings, Cache::get('admin_settings'));
        $this->assertSame($other, Cache::get($otherKey));
    }

    #[DataProvider('invalidMetadata')]
    public function test_invalid_version_metadata_gets_a_new_stable_version(string $field, mixed $value): void
    {
        $before = $this->service->snapshot($this->node);
        $key = 'realtime:control:control:' . $this->node->id;
        $metadata = Cache::get($key);
        $metadata[$field] = $value;
        Cache::forever($key, $metadata);

        $after = $this->service->snapshot($this->node);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/', $after['epoch']);
        $this->assertNotSame($before['epoch'], $after['epoch']);
        $this->assertSame(1, $after['sequence']);
        $this->assertSame($after, $this->service->snapshot($this->node));
    }

    public static function invalidMetadata(): array
    {
        return [
            '代次缺失' => ['epoch', null],
            '代次类型错误' => ['epoch', []],
            '代次格式错误' => ['epoch', 'wrong-generation'],
            '序号缺失' => ['sequence', null],
            '序号为字符串' => ['sequence', '1'],
            '序号为零' => ['sequence', 0],
            '序号为负数' => ['sequence', -1],
            '序号超过精确整数范围' => ['sequence', PHP_INT_MAX],
            '摘要缺失' => ['hash', null],
            '摘要类型错误' => ['hash', []],
            '摘要格式错误' => ['hash', 'wrong-hash'],
            '混入其它缓存内容' => ['app_name', '不属于版本记录'],
        ];
    }

    public function test_repeated_reads_and_config_changes_preserve_version_order(): void
    {
        $first = $this->service->snapshot($this->node);
        $this->assertSame($first, $this->service->snapshot($this->node));
        $this->node->update(['server_port' => 8443]);

        $changed = $this->service->snapshot($this->node);
        $this->assertSame($first['epoch'], $changed['epoch']);
        $this->assertSame($first['sequence'] + 1, $changed['sequence']);
        $this->assertSame(8443, $changed['config']['server_port']);
        $this->assertSame($changed, (new NodeControlStateService())->snapshot($this->node));
    }

    public function test_missing_cache_starts_a_new_version_without_changing_the_payload(): void
    {
        $before = $this->service->snapshot($this->node);
        Cache::forget('realtime:control:control:' . $this->node->id);

        $after = $this->service->snapshot($this->node);
        $this->assertNotSame($before['epoch'], $after['epoch']);
        $this->assertSame(1, $after['sequence']);
        $this->assertSame($before['config'], $after['config']);
        $this->assertSame($before['users'], $after['users']);
        $this->assertSame($after, $this->service->snapshot($this->node));
    }

    public function test_failed_collection_does_not_overwrite_the_last_valid_version(): void
    {
        $this->service->snapshot($this->node);
        $key = 'realtime:control:control:' . $this->node->id;
        $before = Cache::get($key);
        $broken = \Mockery::mock(Server::class);
        $broken->shouldReceive('getAttribute')->with('id')->andReturn($this->node->id);
        $broken->shouldReceive('fresh')->once()->andThrow(new \RuntimeException('模拟读取配置失败'));

        try {
            $this->service->snapshot($broken);
            $this->fail('读取失败必须向调用方返回异常');
        } catch (\RuntimeException $exception) {
            $this->assertSame('模拟读取配置失败', $exception->getMessage());
        }
        $this->assertSame($before, Cache::get($key));
        $this->assertSame($before['epoch'], $this->service->snapshot($this->node)['epoch']);
    }

    public function test_periodic_reconciliation_clears_stale_sources_and_rechecks_user_eligibility(): void
    {
        $this->node->update(['group_ids' => [1]]);
        $user = User::withoutEvents(fn () => User::create([
            'email' => 'devices@performance.example.invalid', 'password' => 'test-only',
            'uuid' => \App\Utils\Helper::guid(true), 'token' => \App\Utils\Helper::guid(),
            'group_id' => 1, 'group_ids' => [1], 'u' => 0, 'd' => 0,
            'transfer_enable' => 1000, 'banned' => false, 'expired_at' => null,
        ]));
        Redis::shouldReceive('hgetall')->with('user_devices:' . $user->id)->andReturn([
            $this->node->id . ':8.8.8.8' => time(),
        ]);
        $store = app(RealtimeStateStore::class);
        $source = 'node:' . $this->node->id;
        $epoch = $store->begin($source, str_repeat('d', 32))['epoch'];
        $store->accept($source, $epoch, 1, ['alive' => [$user->id => ['8.8.8.8']]]);
        $scheduler = new DeviceSyncScheduler();
        $scheduler->targets([], [$this->node->id]);
        $first = $this->service->devices($this->node);
        $this->assertSame([$user->id => ['8.8.8.8']], $first['users']);

        // 即使没有变化通知，周期检查仍读取最新有效期并生成清空快照。
        $this->travel(36)->seconds();
        $this->assertSame([$this->node->id], $scheduler->targets([], [$this->node->id]));
        $stale = $this->service->devices($this->node);
        $this->assertSame([], $stale['users']);
        $this->assertSame($first['sequence'] + 1, $stale['sequence']);
        $store->accept($source, $epoch, 2, ['alive' => [$user->id => ['8.8.8.8']]]);
        $this->assertSame($first['users'], $this->service->devices($this->node)['users']);

        foreach ([['banned' => true], ['banned' => false, 'expired_at' => time() - 1],
            ['expired_at' => null, 'u' => 1000], ['u' => 0, 'group_id' => 2, 'group_ids' => [2]]] as $attributes) {
            User::withoutEvents(fn () => $user->forceFill($attributes)->save());
            $this->assertSame([], $this->service->devices($this->node)['users']);
        }
    }

    public function test_full_sync_reuses_eligibility_but_next_sync_rechecks_restrictions(): void
    {
        $this->node->update(['group_ids' => [1]]);
        $user = User::withoutEvents(fn () => User::create([
            'email' => 'full-sync@example.invalid', 'password' => 'test-only',
            'uuid' => \App\Utils\Helper::guid(true), 'token' => \App\Utils\Helper::guid(),
            'group_id' => 1, 'group_ids' => [1], 'u' => 0, 'd' => 0,
            'transfer_enable' => 1000, 'banned' => false, 'expired_at' => null,
        ]));
        Redis::shouldReceive('hgetall')->with('user_devices:' . $user->id)->andReturn([$this->node->id . ':8.8.8.8' => time()]);
        $queries = 0;
        \Illuminate\Support\Facades\DB::listen(function ($event) use (&$queries) {
            if (str_contains($event->sql, 'u + d < transfer_enable')) $queries++;
        });
        $first = $this->service->fullSnapshot($this->node);
        $this->assertSame(1, $queries);
        $this->assertSame([$user->id], array_column($first['control']['users'], 'id'));
        $this->assertSame([$user->id => ['8.8.8.8']], $first['devices']['users']);
        foreach ([['banned' => true], ['banned' => false, 'expired_at' => time() - 1],
            ['expired_at' => null, 'u' => 1000], ['u' => 0, 'group_id' => 2, 'group_ids' => [2]]] as $attributes) {
            User::withoutEvents(fn () => $user->forceFill($attributes)->save());
            $snapshot = $this->service->fullSnapshot($this->node);
            $this->assertSame([], $snapshot['control']['users']);
            $this->assertSame([], $snapshot['devices']['users']);
        }
        User::withoutEvents(fn () => $user->forceFill(['group_id' => 1, 'group_ids' => [1]])->save());
        $restored = (new NodeControlStateService())->fullSnapshot($this->node);
        $this->assertSame($first['devices']['users'], $restored['devices']['users']);
        $this->assertGreaterThan($first['devices']['sequence'], $restored['devices']['sequence']);
    }

    public function test_full_sync_keeps_plugin_filter_invocations_and_node_specific_results(): void
    {
        $this->node->update(['group_ids' => [1]]);
        $calls = 0;
        $filter = function ($users, $node) use (&$calls) {
            $calls++;
            $this->assertSame($this->node->id, $node->id);
            return $calls % 2 === 1 ? collect([(object) ['id' => 900001]]) : collect();
        };
        \App\Services\Plugin\HookManager::registerFilter('server.users.get', $filter);
        try {
            $snapshot = $this->service->fullSnapshot($this->node);
            $this->assertSame(2, $calls);
            $this->assertSame([900001], array_column($snapshot['control']['users'], 'id'));
            $this->assertSame([], $snapshot['devices']['users']);
        } finally {
            \App\Services\Plugin\HookManager::remove('server.users.get', $filter);
        }
    }

    public function test_full_sync_holds_device_version_lock_before_reading_shared_eligibility(): void
    {
        $this->node->update(['group_ids' => [1]]);
        $key = 'realtime:control:devices:' . $this->node->id . ':lock';
        $checked = false;
        \Illuminate\Support\Facades\DB::listen(function ($event) use ($key, &$checked) {
            if (str_contains($event->sql, 'u + d < transfer_enable')) {
                $checked = true;
                $this->assertFalse(Cache::lock($key, 30)->get(), '并发设备捕获不能越过共享名单的读取');
            }
        });
        $sent = [];
        $snapshot = $this->service->fullSnapshot($this->node, function ($control) use ($key, &$sent) {
            $this->assertFalse(Cache::lock($key, 30)->get());
            $sent[] = $control;
        });
        $this->assertTrue($checked);
        $this->assertSame([$snapshot['control']], $sent);
        $lock = Cache::lock($key, 30);
        $this->assertTrue($lock->get());
        $lock->release();
    }

    public function test_full_sync_device_failure_keeps_version_and_releases_lock_for_retry(): void
    {
        $before = $this->service->devices($this->node);
        $key = 'realtime:control:devices:' . $this->node->id;
        $metadata = Cache::get($key);
        $this->mock(\App\Services\DeviceStateService::class, function ($mock) {
            $mock->shouldReceive('getUsersDevices')->once()->andThrow(new \RuntimeException('设备读取失败'));
            $mock->shouldReceive('getUsersDevices')->once()->andReturn([]);
        });
        $sent = 0;
        try {
            $this->service->fullSnapshot($this->node, function () use (&$sent) { $sent++; });
            $this->fail('Must fail');
        } catch (\RuntimeException $exception) {
            $this->assertSame('设备读取失败', $exception->getMessage());
        }
        $this->assertSame(1, $sent, '保留先发送配置、再发送设备的次序');
        $this->assertSame($metadata, Cache::get($key));
        $this->assertSame($before, $this->service->fullSnapshot($this->node)['devices']);
    }
}
