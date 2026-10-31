<?php

namespace Tests\Feature\Server;

use App\Services\DeviceCoordinationStateReader;
use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class DeviceCoordinationStateReaderTest extends TestCase
{
    private ?\Redis $client = null;
    private RedisStore $store;
    private array $keys = [];
    private DeviceCoordinationStateReader $reader;

    protected function setUp(): void
    {
        parent::setUp();
        $port = getenv('YZ_TEST_DEVICE_REDIS_PORT');
        if (!$port || !extension_loaded('redis')) $this->markTestSkipped('需要专用本机 Redis 和 phpredis');
        $this->client = new \Redis();
        $this->client->connect('127.0.0.1', (int) $port, 3);
        $this->client->setOption(\Redis::OPT_PREFIX, 'state-reader-test:' . bin2hex(random_bytes(8)) . ':');
        $factory = \Mockery::mock(Factory::class);
        $factory->shouldReceive('connection')->andReturn(new PhpRedisConnection($this->client));
        $this->store = new RedisStore($factory, 'cache:');
        Cache::swap(new Repository($this->store));
        $this->reader = new class extends DeviceCoordinationStateReader {
            public int $decodes = 0;
            protected function decode(string $raw): array
            {
                $this->decodes++;
                return parent::decode($raw);
            }
        };
    }

    protected function tearDown(): void
    {
        if ($this->client !== null) {
            foreach ($this->keys as $key) $this->store->forget($key);
            $this->client->close();
        }
        parent::tearDown();
    }

    private function key(int $id): string { return $this->keys[] = 'device_handover:user:' . $id; }
    private function state(string $run = 'old'): array
    {
        return ['sources' => ['192.0.2.1' => ['holders' => [['run' => $run, 'sequence' => 1]], 'revoked' => false]],
            'padding' => str_repeat('x', 8192), 'pending' => null];
    }

    public function test_unchanged_account_across_reads_reuses_decoding_and_isolates_caller_mutation(): void
    {
        $key = $this->key(1);
        $state = $this->state();
        Cache::forever($key, $state);
        $first = $this->reader->get($key);
        foreach ($first['sources'] as &$source) $source['holders'][0]['run'] = 'caller-only';
        unset($source);
        $this->assertSame($state, $this->reader->get($key));
        $this->assertSame(1, $this->reader->decodes);
    }

    public function test_same_length_changes_deletion_and_recreation_are_read_immediately(): void
    {
        $key = $this->key(1);
        Cache::forever($key, $this->state('old'));
        $this->reader->get($key);
        Cache::forever($key, $this->state('new'));
        $this->assertSame($this->state('new'), $this->reader->get($key));
        Cache::forget($key);
        $this->assertNull($this->reader->get($key));
        Cache::forever($key, $this->state('old'));
        $this->assertSame($this->state('old'), $this->reader->get($key));
        $this->assertSame(3, $this->reader->decodes);
    }

    public function test_corrupt_current_record_does_not_return_a_previous_permission(): void
    {
        $key = $this->key(1);
        Cache::forever($key, $this->state());
        $this->reader->get($key);
        $this->client->set('cache:' . $key, 'invalid');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('设备协调状态格式无效');
        $this->reader->get($key);
    }

    public function test_backend_error_does_not_return_a_previous_permission(): void
    {
        $key = $this->key(1);
        Cache::forever($key, $this->state());
        $this->reader->get($key);
        $failed = $this->createMock(\Redis::class);
        $failed->method('getOption')->willReturn(0);
        $failed->method('get')->willThrowException(new \RedisException('test read failed'));
        $factory = \Mockery::mock(Factory::class);
        $factory->shouldReceive('connection')->andReturn(new PhpRedisConnection($failed));
        Cache::swap(new Repository(new RedisStore($factory, 'cache:')));
        $this->expectException(\RedisException::class);
        $this->reader->get($key);
    }

    public function test_client_serialization_and_custom_store_keep_the_original_read_path(): void
    {
        $key = $this->key(1);
        $this->client->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
        Cache::forever($key, $this->state());
        $this->assertSame($this->state(), $this->reader->get($key));
        $this->assertSame(0, $this->reader->decodes);
        Cache::swap(new Repository(new \Illuminate\Cache\ArrayStore()));
        Cache::forever($key, ['custom' => true]);
        $this->assertSame(['custom' => true], $this->reader->get($key));
    }

    public function test_entry_limit_evicts_oldest_records_and_preserves_recent_ones(): void
    {
        $first = $this->key(1);
        Cache::forever($first, $this->state());
        $this->reader->get($first);
        foreach (range(2, 130) as $id) {
            $key = $this->key($id);
            Cache::forever($key, $this->state());
            $this->reader->get($key);
            $this->reader->get($first);
        }
        $this->assertSame(130, $this->reader->decodes);
        $this->assertSame($this->state(), $this->reader->get('device_handover:user:2'));
        $this->assertSame(131, $this->reader->decodes);
    }

    public function test_byte_limit_and_oversized_records_keep_latest_values_without_unbounded_memo(): void
    {
        $state = ['padding' => str_repeat('x', 2 * 1024 * 1024)];
        $first = $this->key(1);
        $second = $this->key(2);
        foreach ([$first, $second] as $key) {
            Cache::forever($key, $state);
            $this->reader->get($key);
        }
        $this->assertSame($state, $this->reader->get($first));
        $this->assertSame(3, $this->reader->decodes);
        $large = ['padding' => str_repeat('x', 4 * 1024 * 1024)];
        Cache::forever($first, $large);
        $this->assertSame($large, $this->reader->get($first));
        $this->assertSame($large, $this->reader->get($first));
        $this->assertSame(5, $this->reader->decodes);
    }

    public function test_custom_repository_read_is_not_bypassed(): void
    {
        $key = $this->key(1);
        Cache::forever($key, $this->state());
        $this->reader->get($key);
        Cache::swap(new class($this->store) extends Repository {
            public function get($key, $default = null): mixed { return ['extension' => true]; }
        });
        $this->assertSame(['extension' => true], $this->reader->get($key));
    }

    public function test_octane_warms_one_reader_for_successive_request_sandboxes(): void
    {
        Cache::clearResolvedInstance('cache');
        $this->app->forgetInstance('cache');
        $this->app->forgetInstance(DeviceCoordinationStateReader::class);
        (new \Laravel\Octane\ApplicationFactory(base_path()))->warm($this->app);
        $reader = $this->app->make(DeviceCoordinationStateReader::class);
        $first = clone $this->app;
        $second = clone $this->app;
        $first->forgetScopedInstances();
        $second->forgetScopedInstances();
        $this->assertSame($reader, $first->make(DeviceCoordinationStateReader::class));
        $this->assertSame($reader, $second->make(DeviceCoordinationStateReader::class));
    }
}
