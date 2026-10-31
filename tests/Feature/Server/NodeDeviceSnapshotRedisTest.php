<?php

namespace Tests\Feature\Server;

use App\Services\DeviceStateService;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class NodeDeviceSnapshotRedisTest extends TestCase
{
    private ?\Redis $client = null;
    private array $keys = [];

    protected function setUp(): void
    {
        parent::setUp();
        $port = getenv('YZ_TEST_SUBSCRIPTION_REDIS_PORT');
        if (!$port || !extension_loaded('redis')) $this->markTestSkipped('需要专用本机 Redis 和 phpredis');
        if ((int) $port < 1 || (int) $port > 65535) throw new \InvalidArgumentException('测试 Redis 端口无效');
        $manager = new RedisManager($this->app, 'phpredis', [
            'options' => ['prefix' => 'node-snapshot-test:' . bin2hex(random_bytes(12)) . ':'],
            'default' => ['host' => '127.0.0.1', 'port' => (int) $port, 'database' => 0,
                'password' => null, 'timeout' => 3],
        ]);
        $this->client = $manager->connection()->client();
        Redis::swap($manager);
    }

    protected function tearDown(): void
    {
        if ($this->client !== null) {
            foreach (array_unique($this->keys) as $key) $this->client->del($key);
            $this->client->close();
        }
        parent::tearDown();
    }

    private function key(string $key): string
    {
        $this->keys[] = $key;
        return $key;
    }

    private function index(): void
    {
        $this->client->setex($this->key('node_devices_seen:1'), 600, 1);
        $this->client->sadd($this->key('node_devices:1'), 15, 16);
    }

    public function test_real_pipeline_preserves_node_isolation_and_sees_deletion_on_the_next_read(): void
    {
        $this->index();
        $now = time();
        $this->client->hMset($this->key('user_devices:15'), [
            '1:8.8.8.8' => $now, '2:1.1.1.1' => $now, '1:8.8.4.4' => $now - 301,
        ]);
        $this->client->hMset($this->key('user_devices:16'), ['1:2001:4860:4860::8888' => $now]);
        $service = new DeviceStateService();
        $snapshot = $service->getNodeDevices(1);
        $this->assertSame(['8.8.8.8'], $snapshot[15]);
        $this->assertSame(['2001:4860:4860::8888'], $snapshot[16]);
        $this->client->del('user_devices:15');
        $this->assertSame([16 => ['2001:4860:4860::8888']], $service->getNodeDevices(1));
    }

    public function test_real_pipeline_wrong_type_is_not_reported_as_an_empty_snapshot(): void
    {
        $this->index();
        $this->client->set($this->key('user_devices:16'), 'wrong-type');
        try {
            (new DeviceStateService())->getNodeDevices(1);
            $this->fail('Redis 错误不能伪装为成功的空设备快照');
        } catch (\RuntimeException | \RedisException $error) {
            $this->assertNotSame('', $error->getMessage());
        }
    }
}
