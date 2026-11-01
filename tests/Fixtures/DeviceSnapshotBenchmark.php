<?php

namespace Tests\Fixtures;

use App\Services\DeviceSnapshotReader;
use App\Services\DeviceStateService;
use App\Services\RealtimeStateStore;
use App\Support\Setting;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** 仅测设备读取与过滤；数据库资格查询、真实状态读取不计入本局部基准。 */
class DeviceSnapshotBenchmark extends TestCase
{
    public function test_snapshot_read_cost(): void
    {
        $port = getenv('YZ_TEST_SUBSCRIPTION_REDIS_PORT');
        if (!$port || !extension_loaded('redis')) $this->markTestSkipped('需要专用本机 Redis');
        $prefix = 'device-snapshot-bench:' . bin2hex(random_bytes(12)) . ':';
        $manager = new RedisManager($this->app, 'phpredis', ['options' => ['prefix' => $prefix], 'default' => [
            'host' => '127.0.0.1', 'port' => (int) $port, 'database' => 0, 'password' => null, 'timeout' => 3,
        ]]);
        Redis::swap($manager);
        $client = $manager->connection()->client();
        Cache::store('redis')->forever(Setting::CACHE_KEY, []);
        $this->app->instance(Setting::class, new Setting());
        // 不使用会记录每次调用的 Mockery，避免把测试替身开销算成优化收益。
        $this->app->instance(RealtimeStateStore::class, new class extends RealtimeStateStore {
            public function readMany(array $sources): array { return array_fill_keys($sources, ['fresh' => true]); }
        });
        $ids = range(1, 48);
        $baseline = new class extends DeviceStateService {};
        $candidate = new DeviceStateService();
        try {
            foreach ([0, 4, 64] as $nodes) {
                foreach ($ids as $id) {
                    $records = [];
                    for ($node = 1; $node <= $nodes; $node++) $records[$node . ':8.8.' . $id . '.' . $node] = time();
                    $client->del('user_devices:' . $id);
                    if ($records !== []) $client->hMset('user_devices:' . $id, $records);
                }
                $expected = $baseline->getUsersDevices($ids);
                $this->assertSame($expected, $candidate->getUsersDevices($ids));
                // 交替执行，分别汇总四轮，每轮 100 次。
                $results = [];
                for ($round = 0; $round < 4; $round++) {
                    foreach (($round % 2 === 0 ? ['baseline', 'candidate'] : ['candidate', 'baseline']) as $name) {
                        $service = $name === 'baseline' ? $baseline : $candidate;
                        $before = $client->info();
                        $cpu = getrusage();
                        $start = hrtime(true);
                        for ($i = 0; $i < 100; $i++) $actual = $service->getUsersDevices($ids);
                        $ms = (hrtime(true) - $start) / 1e6;
                        $afterCpu = getrusage();
                        $after = $client->info();
                        $this->assertSame($expected, $actual);
                        $results[$name][] = [
                            'ms' => $ms,
                            'php_cpu_ms' => (($afterCpu['ru_utime.tv_sec'] + $afterCpu['ru_stime.tv_sec']
                                - $cpu['ru_utime.tv_sec'] - $cpu['ru_stime.tv_sec']) * 1e6
                                + $afterCpu['ru_utime.tv_usec'] + $afterCpu['ru_stime.tv_usec']
                                - $cpu['ru_utime.tv_usec'] - $cpu['ru_stime.tv_usec']) / 1000,
                            'redis_cpu_ms' => (($after['used_cpu_sys'] + $after['used_cpu_user'])
                                - ($before['used_cpu_sys'] + $before['used_cpu_user'])) * 1000,
                            'redis_output_bytes' => $after['total_net_output_bytes'] - $before['total_net_output_bytes'],
                            'redis_commands' => $after['total_commands_processed'] - $before['total_commands_processed'],
                        ];
                    }
                }
                foreach ($results as $name => $rounds) {
                    $sum = [];
                    foreach (array_keys($rounds[0]) as $key) $sum[$key] = round(array_sum(array_column($rounds, $key)), 3);
                    fwrite(STDERR, json_encode(['implementation' => $name, 'users' => 48, 'sources_per_user' => $nodes,
                        'iterations' => 400, 'totals' => $sum], JSON_UNESCAPED_SLASHES) . "\n");
                }
            }
        } finally {
            foreach ($ids as $id) $client->del('user_devices:' . $id);
            $client->close();
        }
    }
}
