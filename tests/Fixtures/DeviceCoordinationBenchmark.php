<?php

namespace Tests\Fixtures;

use App\Models\Server;
use App\Models\User;
use App\Services\DeviceHandoverService;
use App\Services\DeviceStateService;
use Illuminate\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class DeviceCoordinationBenchmark extends TestCase
{
    use RefreshDatabase;

    private \Illuminate\Cache\RedisStore $store;
    private ?\Redis $benchClient = null;
    private DeviceHandoverService $service;
    private array $nodes = [];
    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
        $this->mock(DeviceStateService::class, fn ($mock) => $mock->shouldReceive('getDeviceSources')->andReturn([]));
        $this->service = app(DeviceHandoverService::class);
        foreach (range(1, 128) as $index) {
            $this->nodes[] = Server::create(['name' => '协调测试', 'type' => 'vmess', 'host' => 'test.invalid',
                'port' => '443', 'server_port' => 443, 'rate' => 1, 'group_ids' => [1], 'enabled' => true]);
        }
        foreach (range(1, 40) as $index) {
            $this->users[] = User::create(['email' => 'coordination-' . $index . '@example.invalid', 'password' => 'test-only',
                'uuid' => sprintf('00000000-0000-0000-0000-%012d', $index), 'token' => hash('md5', 'coordination-' . $index),
                'group_id' => 1, 'device_limit' => 1, 'transfer_enable' => 100000, 'u' => 0, 'd' => 0, 'banned' => 0,
                'expired_at' => time() + 86400]);
        }
        $port = getenv('YZ_TEST_SUBSCRIPTION_REDIS_PORT');
        if (!$port || !extension_loaded('redis')) $this->markTestSkipped('需要专用本机 Redis 和 phpredis');
        $this->benchClient = new \Redis();
        $this->benchClient->connect('127.0.0.1', (int) $port);
        $factory = \Mockery::mock(\Illuminate\Contracts\Redis\Factory::class);
        $factory->shouldReceive('connection')->andReturn(new \Illuminate\Redis\Connections\PhpRedisConnection($this->benchClient));
        $this->store = new \Illuminate\Cache\RedisStore($factory, 'benchmark:' . bin2hex(random_bytes(8)) . ':');
        Cache::swap(new Repository($this->store));
        $this->seedCoordination(true);

    }

    private function runID(int $nodeId): string { return str_pad(dechex($nodeId), 32, '0', STR_PAD_LEFT); }

    private function seedCoordination(bool $active): void
    {
        $reported = [];
        foreach ($this->users as $index => $user) {
            $lease = str_pad(dechex($user->id), 32, 'a', STR_PAD_LEFT);
            $ip = '198.51.100.' . ($index + 1);
            $owners = $holders = [];
            foreach ($this->nodes as $node) {
                $owners[$node->id] = ['run' => $this->runID($node->id), 'sequence' => 1, 'connect_sequence' => 1, 'last_new_at' => now()->getTimestampMs()];
                $holders[$node->id . ':' . $this->runID($node->id)] = ['node_id' => $node->id, 'run' => $this->runID($node->id),
                    'sequence' => 1, 'confirmed_at' => now()->getTimestampMs(), 'expires_at' => now()->addHour()->getTimestampMs()];
            }
            $state = ['sources' => $active ? [$ip => ['user_id' => $user->id, 'ip' => $ip, 'lease' => $lease,
                'revoked' => false, 'owners' => $owners, 'holders' => $holders]] : [], 'cooldown' => [], 'pending' => null];
            if ($active) Cache::forever('device_handover:user:' . $user->id, $state);
            else Cache::put('device_handover:user:' . $user->id, $state, 120);
            if ($active) $reported[$lease] = ['user_id' => $user->id, 'ip' => $ip, 'lease' => $lease,
                'connect_sequence' => 1, 'age_ms' => 0, 'last_new_at' => now()->getTimestampMs()];
        }
        foreach ($this->nodes as $node) {
            $key = 'device_handover:node:' . $node->id;
            Cache::forever($key, ['run' => $this->runID($node->id), 'sequence' => 1, 'pending' => [], 'sources' => $reported,
                'version' => 1, 'seen_at' => now()->getTimestampMs(), 'lease_cache' => true]);
            Cache::forever($key . ':progress', ['run' => $this->runID($node->id), 'sequence' => 1, 'base_sequence' => 1, 'seen_at' => now()->getTimestampMs()]);
            Cache::forever('device_handover:queue:' . $node->id, $active ? array_map(fn ($user) => $user->id, $this->users) : []);
        }

    }

    private function sync(int $sequence = 2): array
    {
        return $this->service->sync($this->nodes[0]->id, ['run' => $this->runID($this->nodes[0]->id),
            'lease_cache' => 1, 'sequence' => $sequence, 'unchanged' => true, 'base_sequence' => 1]);
    }

    protected function tearDown(): void
    {
        if ($this->benchClient === null) { parent::tearDown(); return; }
        $keys = $this->benchClient->keys($this->store->getPrefix() . '*');
        if ($keys) $this->benchClient->del($keys);
        $this->benchClient->close();
        parent::tearDown();
    }

    public function test_benchmark(): void
    {
        $stats = $this->benchClient->info('stats');
        $redisCpu = $this->benchClient->info('cpu');
        $cpu = getrusage();
        $start = hrtime(true);
        for ($sequence = 2; $sequence <= 6; $sequence++) $this->sync($sequence);
        $elapsed = (hrtime(true) - $start) / 1e6;
        $endCpu = getrusage();
        $endRedisCpu = $this->benchClient->info('cpu');
        $cpuMs = ($endCpu['ru_utime.tv_sec'] - $cpu['ru_utime.tv_sec'] + $endCpu['ru_stime.tv_sec'] - $cpu['ru_stime.tv_sec']) * 1000
            + ($endCpu['ru_utime.tv_usec'] - $cpu['ru_utime.tv_usec'] + $endCpu['ru_stime.tv_usec'] - $cpu['ru_stime.tv_usec']) / 1000;
        $metrics = ['milliseconds' => $elapsed, 'rounds' => 5, 'users' => 40, 'nodes' => 128, 'php_cpu_ms' => $cpuMs,
            'redis_cpu_ms' => ($endRedisCpu['used_cpu_sys'] + $endRedisCpu['used_cpu_user'] - $redisCpu['used_cpu_sys'] - $redisCpu['used_cpu_user']) * 1000,
            'redis_output_bytes' => $this->benchClient->info('stats')['total_net_output_bytes'] - $stats['total_net_output_bytes']];
        fwrite(STDERR, 'COORDINATION_BENCHMARK ' . json_encode($metrics) . "\n");
        $this->assertSame(40, count($this->users));
    }
}
