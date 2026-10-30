<?php

namespace Tests\Support;

use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository;
use Illuminate\Redis\RedisManager;

/** 仅连接显式指定的本机测试实例，每例只清理自己的随机前缀。 */
class DeviceRedisCache
{
    public readonly Repository $repository;
    private readonly string $prefix;
    private readonly \Redis $client;

    public function __construct($app, int $port, ?string $scope = null)
    {
        if ($port < 1 || $port > 65535) throw new \InvalidArgumentException('测试 Redis 端口无效');
        $this->prefix = 'device-lifecycle-test:' . ($scope === null ? bin2hex(random_bytes(12)) : hash('sha256', $scope)) . ':';
        $manager = new RedisManager($app, 'phpredis', ['options' => ['prefix' => ''], 'device-test' => [
            'host' => '127.0.0.1', 'port' => $port, 'database' => 0, 'password' => null, 'timeout' => 2,
        ]]);
        $store = new RedisStore($manager, $this->prefix, 'device-test');
        $store->setLockConnection('device-test');
        $this->repository = new Repository($store);
        $this->client = $manager->connection('device-test')->client();
        $this->client->ping();
    }

    public function close(): void
    {
        $cursor = null;
        do {
            $keys = $this->client->scan($cursor, $this->prefix . '*', 500);
            if ($keys) $this->client->del($keys);
        } while ($cursor !== 0);
        $this->client->close();
    }

    public function install($app): void
    {
        $app['config']->set(['cache.default' => 'device_lifecycle_test',
            'cache.stores.device_lifecycle_test' => ['driver' => 'device_lifecycle_test']]);
        $repository = $this->repository;
        $app['cache']->extend('device_lifecycle_test', fn () => $repository);
        $app['cache']->forgetDriver('device_lifecycle_test');
    }
}
