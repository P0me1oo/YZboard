<?php

namespace Tests\Feature\Server;

use Tests\Support\DeviceRedisCache;

class DeviceLeaseRedisTest extends DeviceLeaseCacheTest
{
    private ?DeviceRedisCache $redis = null;

    protected function initializeCache(): void
    {
        $port = getenv('YZ_TEST_DEVICE_REDIS_PORT');
        if (!$port) $this->markTestSkipped('需要专用本机 Redis 实例');
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
