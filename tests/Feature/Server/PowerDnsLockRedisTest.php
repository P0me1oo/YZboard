<?php

namespace Tests\Feature\Server;

use Tests\Support\DeviceRedisCache;

class PowerDnsLockRedisTest extends PowerDnsLockTest
{
    private ?DeviceRedisCache $redis = null;

    protected function initializeCache(): void
    {
        $port = getenv('YZ_TEST_DEVICE_REDIS_PORT');
        if (!$port || !extension_loaded('redis')) $this->markTestSkipped('需要专用本机 Redis 和 phpredis');
        if (!ctype_digit($port)) throw new \InvalidArgumentException('测试 Redis 端口无效');
        $this->redis = new DeviceRedisCache($this->app, (int) $port);
        $this->redis->install($this->app);
    }

    protected function tearDown(): void
    {
        $this->redis?->close();
        parent::tearDown();
    }
}
