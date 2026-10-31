<?php

namespace Tests\Feature\Server;

use App\Services\DeviceCoordinationReadContext;
use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class DeviceCoordinationRedisTest extends TestCase
{
    private ?\Redis $client = null;
    private RedisStore $store;
    private array $keys = [];

    protected function setUp(): void
    {
        parent::setUp();
        $port = getenv('YZ_TEST_SUBSCRIPTION_REDIS_PORT');
        if (!$port || !extension_loaded('redis')) $this->markTestSkipped('需要专用本机 Redis 和 phpredis');
        $this->client = new \Redis();
        $this->client->connect('127.0.0.1', (int) $port, 3);
        $this->client->setOption(\Redis::OPT_PREFIX, 'coordination-test:' . bin2hex(random_bytes(8)) . ':');
        $factory = \Mockery::mock(Factory::class);
        $factory->shouldReceive('connection')->andReturn(new PhpRedisConnection($this->client));
        $this->store = new RedisStore($factory, 'cache:');
        Cache::swap(new Repository($this->store));
    }

    protected function tearDown(): void
    {
        if ($this->client !== null) {
            foreach ($this->keys as $key) $this->store->forget($key);
            $this->client->close();
        }
        parent::tearDown();
    }

    private function key(string $suffix): string
    {
        return $this->keys[] = 'device_handover:' . $suffix;
    }

    public function test_unchanged_large_snapshot_is_not_decoded_again(): void
    {
        $key = $this->key('node:1');
        $snapshot = ['run' => str_repeat('a', 32), 'sources' => [str_repeat('x', 128 * 1024)]];
        Cache::forever($key, $snapshot);
        $reader = new class extends DeviceCoordinationReadContext {
            public int $decodes = 0;
            protected function decode(string $raw): array
            {
                $this->decodes++;
                return parent::decode($raw);
            }
        };
        $this->assertSame([$key => $snapshot], $reader->many([$key]));
        $this->assertSame([$key => $snapshot], $reader->many([$key]));
        $this->assertSame(1, $reader->decodes);
        Cache::forever($key, ['sources' => []]);
        $this->assertSame([$key => ['sources' => []]], $reader->many([$key]));
        $this->assertSame(2, $reader->decodes);
    }

    public function test_restart_queue_changes_and_deletion_are_visible_on_each_read(): void
    {
        $node = $this->key('node:1');
        $queue = $this->key('queue:1');
        Cache::forever($node, ['run' => 'old']);
        Cache::forever($queue, [1, 2]);
        $reader = new DeviceCoordinationReadContext();
        $first = $reader->many([$node, $queue]);
        $first[$node]['run'] = 'caller-only';
        $this->assertSame('old', $reader->many([$node])[$node]['run']);
        Cache::forever($node, ['run' => 'new']);
        Cache::forever($queue, [2]);
        $this->assertSame([$node => ['run' => 'new'], $queue => [2]], $reader->many([$node, $queue]));
        Cache::forget($node);
        $this->assertSame([$node => null], $reader->many([$node]));
        Cache::forever($node, ['run' => 'new']);
        $this->assertSame([$node => ['run' => 'new']], $reader->many([$node]));
    }

    public function test_client_serialization_uses_original_store_format(): void
    {
        $this->client->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
        $key = $this->key('queue:1');
        Cache::forever($key, [1, 2]);
        $reader = new DeviceCoordinationReadContext();
        $this->assertSame([$key => [1, 2]], $reader->many([$key]));
        Cache::forever($key, [2]);
        $this->assertSame([$key => [2]], $reader->many([$key]));
    }

    public function test_invalid_current_record_cannot_reuse_last_valid_snapshot(): void
    {
        $key = $this->key('node:1');
        Cache::forever($key, ['run' => 'old']);
        $reader = new DeviceCoordinationReadContext();
        $reader->many([$key]);
        $this->client->set('cache:' . $key, 'invalid-current-record');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('设备协调状态格式无效');
        $reader->many([$key]);
    }

    public function test_large_record_still_reads_latest_when_it_exceeds_memo_limit(): void
    {
        $key = $this->key('node:1');
        $value = ['sources' => [str_repeat('x', 8 * 1024 * 1024)]];
        Cache::forever($key, $value);
        $reader = new DeviceCoordinationReadContext();
        $this->assertSame([$key => $value], $reader->many([$key]));
        Cache::forever($key, ['sources' => []]);
        $this->assertSame([$key => ['sources' => []]], $reader->many([$key]));
    }
}
