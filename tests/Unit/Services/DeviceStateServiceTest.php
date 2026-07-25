<?php

namespace Tests\Unit\Services;

use App\Services\DeviceStateService;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class DeviceStateServiceTest extends TestCase
{
    public function test_get_users_devices_returns_json_list_after_deduplication(): void
    {
        Redis::shouldReceive('hgetall')
            ->once()
            ->with('user_devices:15')
            ->andReturn([
                '121:192.0.2.1' => time(),
                '122:192.0.2.1' => time(),
                '122:2001:db8::1' => time(),
            ]);

        $devices = (new DeviceStateService())->getUsersDevices([15]);

        $this->assertSame(
            ['192.0.2.1', '2001:db8::1'],
            $devices[15]
        );
        $this->assertTrue(array_is_list($devices[15]));
    }
}
