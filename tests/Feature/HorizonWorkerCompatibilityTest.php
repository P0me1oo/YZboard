<?php

namespace Tests\Feature;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Mockery;
use Tests\TestCase;

class HorizonWorkerCompatibilityTest extends TestCase
{
    public function test_worker_starts_with_default_options(): void
    {
        $this->expectWorkerOptions(0);

        $this->assertSame(0, Artisan::call('horizon:work', [
            'connection' => 'sync', '--once' => true, '--queue' => 'traffic_fetch',
            '--tries' => 3, '--timeout' => 30, '--max-jobs' => 1000,
        ]));
    }

    public function test_worker_accepts_an_explicit_idle_timeout(): void
    {
        $this->expectWorkerOptions(15);

        $this->assertSame(0, Artisan::call('horizon:work', [
            'connection' => 'sync', '--once' => true, '--queue' => 'traffic_fetch',
            '--tries' => 3, '--timeout' => 30, '--max-jobs' => 1000,
            '--stop-when-empty-for' => 15,
        ]));
    }

    public function test_daemon_worker_starts_with_existing_supervisor_options(): void
    {
        $this->expectWorkerOptions(0, 'daemon');

        $this->assertSame(0, Artisan::call('horizon:work', [
            'connection' => 'sync', '--queue' => 'traffic_fetch',
            '--tries' => 3, '--timeout' => 30, '--max-jobs' => 1000,
        ]));
    }

    private function expectWorkerOptions(int $idleTimeout, string $method = 'runNextJob'): void
    {
        $worker = Mockery::mock(Worker::class);
        $worker->shouldReceive('setName')->once()->with('default')->andReturnSelf();
        $worker->shouldReceive('setCache')->once()->andReturnSelf();
        $worker->shouldReceive($method)->once()->with('sync', 'traffic_fetch',
            Mockery::on(fn (WorkerOptions $options) => (int) $options->stopWhenEmptyFor === $idleTimeout
                && (int) $options->maxTries === 3 && (int) $options->timeout === 30
                && (int) $options->maxJobs === 1000))->andReturn(0);
        $this->app->instance('queue.worker', $worker);
    }

    public function test_worker_consumes_a_real_redis_job_and_can_poll_an_empty_queue(): void
    {
        $port = getenv('YZ_TEST_HORIZON_REDIS_PORT') ?: getenv('YZ_TEST_DEVICE_REDIS_PORT');
        if (!$port) $this->markTestSkipped('需要专用本机 Redis 实例');
        $prefix = 'horizon-worker-test:' . bin2hex(random_bytes(8)) . ':';
        $connection = ['host' => '127.0.0.1', 'port' => (int) $port, 'database' => 0];
        config([
            'database.redis.client' => 'phpredis',
            'database.redis.options.prefix' => $prefix,
            'database.redis.default' => $connection,
            'database.redis.horizon' => $connection,
            'horizon.prefix' => $prefix . 'horizon:',
            'queue.failed.driver' => null,
        ]);
        Redis::purge('default');
        Redis::purge('horizon');
        $this->app->forgetInstance('redis');
        Redis::clearResolvedInstance('redis');

        try {
            Queue::connection('redis')->push(new HorizonCompatibilityJob($prefix), '', 'compatibility');
            $this->assertSame(1, Queue::connection('redis')->size('compatibility'));
            $options = ['connection' => 'redis', '--queue' => 'compatibility', '--once' => true, '--sleep' => 0];
            $this->assertSame(0, Artisan::call('horizon:work', $options));
            $this->assertSame(1, Cache::get($prefix));
            $this->assertSame(0, Queue::connection('redis')->size('compatibility'));
            $this->assertSame(0, Artisan::call('horizon:work', $options));
            $this->assertSame(1, Cache::get($prefix));
        } finally {
            $redis = new \Redis();
            $redis->connect('127.0.0.1', (int) $port, 2);
            $cursor = null;
            do {
                $keys = $redis->scan($cursor, $prefix . '*', 100);
                if ($keys) $redis->del($keys);
            } while ($cursor !== 0);
            $redis->close();
        }
    }
}

class HorizonCompatibilityJob implements ShouldQueue
{
    public function __construct(public string $marker) {}

    public function handle(): void
    {
        Cache::increment($this->marker);
    }
}
