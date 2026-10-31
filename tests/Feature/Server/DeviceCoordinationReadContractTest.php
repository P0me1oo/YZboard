<?php

namespace Tests\Feature\Server;

use App\Http\Middleware\MeasureHttpPerformance;
use App\Services\DeviceCoordinationReadContext;
use App\Services\DeviceCoordinationStateReader;
use App\Services\HttpPerformanceSample;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\RetrievingKey;
use Illuminate\Cache\Events\RetrievingManyKeys;
use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Http\Request;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** 不连接外部服务，核对优化读取与框架、扩展和诊断的接口约定。 */
class DeviceCoordinationReadContractTest extends TestCase
{
    private function repository(\Redis $client): Repository
    {
        $connection = new PhpRedisConnection($client);
        $connection->setEventDispatcher($this->app['events']);
        $factory = \Mockery::mock(Factory::class);
        $factory->shouldReceive('connection')->andReturn($connection);
        $repository = new Repository(new RedisStore($factory, 'cache:'), ['store' => 'contract-test']);
        $repository->setEventDispatcher($this->app['events']);
        return $repository;
    }

    private function client(): \Redis
    {
        if (!extension_loaded('redis')) $this->markTestSkipped('需要 phpredis 扩展');
        $client = $this->createMock(\Redis::class);
        $client->method('getOption')->willReturn(0);
        return $client;
    }

    public function test_batch_reader_preserves_custom_repository_behavior(): void
    {
        $client = $this->client();
        $client->expects($this->never())->method('mget');
        $base = $this->repository($client);
        Cache::swap(new class($base->getStore()) extends Repository {
            public function many(array $keys)
            {
                return array_fill_keys($keys, ['extension' => true]);
            }
        });
        $key = 'device_handover:node:1';
        $this->assertSame([$key => ['extension' => true]], (new DeviceCoordinationReadContext())->many([$key]));
    }

    public function test_default_cache_manager_also_preserves_custom_repository_behavior(): void
    {
        $client = $this->client();
        $client->expects($this->never())->method('mget');
        $base = $this->repository($client);
        $repository = new class($base->getStore()) extends Repository {
            public function many(array $keys) { return array_fill_keys($keys, ['extension' => true]); }
        };
        config(['cache.default' => 'contract-test', 'cache.stores.contract-test' => ['driver' => 'contract-test']]);
        $manager = new CacheManager($this->app);
        $manager->extend('contract-test', fn () => $repository);
        Cache::swap($manager);
        $key = 'device_handover:queue:1';
        $this->assertSame([$key => ['extension' => true]], (new DeviceCoordinationReadContext())->many([$key]));
    }

    public function test_single_reads_keep_event_order_values_and_decode_reuse(): void
    {
        $value = ['sources' => [], 'padding' => str_repeat('x', 8192)];
        $client = $this->client();
        $client->expects($this->exactly(3))->method('get')->willReturnOnConsecutiveCalls(serialize($value), serialize($value), false);
        Cache::swap($this->repository($client));
        $events = [];
        $this->app['events']->listen([RetrievingKey::class, CacheHit::class, CacheMissed::class], function ($event) use (&$events): void {
            $events[] = $event;
        });
        $reader = new class extends DeviceCoordinationStateReader {
            public int $decodes = 0;
            protected function decode(string $raw): array { $this->decodes++; return parent::decode($raw); }
        };
        $key = 'device_handover:user:1';
        $this->assertSame($value, $reader->get($key));
        $this->assertSame($value, $reader->get($key));
        $this->assertNull($reader->get($key));
        $this->assertSame(1, $reader->decodes);
        $this->assertSame([RetrievingKey::class, CacheHit::class, RetrievingKey::class, CacheHit::class,
            RetrievingKey::class, CacheMissed::class], array_map(fn ($event) => get_class($event), $events));
        foreach ($events as $event) {
            $this->assertSame('contract-test', $event->storeName);
            $this->assertSame($key, $event->key);
        }
        $this->assertSame($value, $events[1]->value);
    }

    public function test_batch_reads_count_unique_results_with_hit_and_miss_events(): void
    {
        $key = 'device_handover:node:1';
        $missing = 'device_handover:node:2';
        $value = ['run' => 'test-run'];
        $client = $this->client();
        $client->expects($this->once())->method('mget')->with(['cache:' . $key, 'cache:' . $key, 'cache:' . $missing])
            ->willReturn([serialize($value), serialize($value), false]);
        Cache::swap($this->repository($client));
        $events = [];
        $this->app['events']->listen([RetrievingManyKeys::class, CacheHit::class, CacheMissed::class], function ($event) use (&$events): void {
            $events[] = $event;
        });
        $this->assertSame([$key => $value, $missing => null], (new DeviceCoordinationReadContext())->many([$key, $key, $missing]));
        $this->assertSame([RetrievingManyKeys::class, CacheHit::class, CacheMissed::class], array_map(fn ($event) => get_class($event), $events));
        $this->assertSame([$key, $key, $missing], $events[0]->keys);
        $this->assertSame($value, $events[1]->value);
        $this->assertSame($missing, $events[2]->key);
    }

    public function test_failed_backend_read_does_not_emit_success_or_missing_events(): void
    {
        $client = $this->client();
        $client->method('get')->willThrowException(new \RedisException('contract-test-read-failure'));
        Cache::swap($this->repository($client));
        $events = [];
        $this->app['events']->listen([RetrievingKey::class, CacheHit::class, CacheMissed::class], function ($event) use (&$events): void {
            $events[] = get_class($event);
        });
        try {
            (new DeviceCoordinationStateReader())->get('device_handover:user:1');
            $this->fail('读取失败必须传播');
        } catch (\RedisException $error) {
            $this->assertSame('contract-test-read-failure', $error->getMessage());
        }
        $this->assertSame([RetrievingKey::class], $events);
    }

    public function test_http_performance_sampling_counts_optimized_cache_and_redis_reads(): void
    {
        $value = ['sources' => [], 'padding' => str_repeat('x', 8192)];
        $client = $this->client();
        $client->expects($this->once())->method('get')->willReturn(serialize($value));
        $client->expects($this->once())->method('mget')->willReturn([serialize($value)]);
        Cache::swap($this->repository($client));
        config(['http_performance.enabled' => true, 'http_performance.until' => time() + 60, 'http_performance.sample_rate' => 1]);
        $request = Request::create('/_test/performance-contract');
        $this->app->instance('request', $request);
        $middleware = new class extends MeasureHttpPerformance {
            public ?HttpPerformanceSample $sample = null;
            protected function aggregate(Request $request, HttpPerformanceSample $sample, float $ms, int $status, int $until, float $rate): void
            {
                $this->sample = $sample;
            }
        };
        $response = $middleware->handle($request, function () {
            (new DeviceCoordinationStateReader())->get('device_handover:user:1');
            (new DeviceCoordinationReadContext())->many(['device_handover:node:1']);
            return response('ok');
        });
        $this->assertSame(200, $response->getStatusCode());
        $this->assertNotNull($middleware->sample);
        $this->assertSame(2, $middleware->sample->cacheReads);
        $this->assertSame(2, $middleware->sample->redisCount);
        $this->assertFalse($request->attributes->has('_http_performance_sample'));
    }
}
