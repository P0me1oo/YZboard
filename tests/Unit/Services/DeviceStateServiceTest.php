<?php

namespace Tests\Unit\Services;

use App\Services\DeviceStateService;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class DeviceStateServiceTest extends TestCase
{
    public function test_device_count_ignores_private_relay_and_deduplicates_public_ips_across_nodes(): void
    {
        Redis::shouldReceive('hgetall')
            ->once()
            ->with('user_devices:15')
            ->andReturn([
                '121:8.8.8.8' => time(),
                '122:8.8.8.8' => time(),
                '122:10.0.0.2' => time(),
                '123:127.0.0.1' => time(),
                '123:100.64.0.1' => time(),
                '123:2001:4860:4860::8888' => time(),
                '124:1.1.1.1' => time() - 301,
            ]);

        $this->assertSame(2, (new DeviceStateService())->getDeviceCount(15));
    }

    public function test_get_users_devices_returns_json_list_after_deduplication(): void
    {
        Redis::shouldReceive('hgetall')
            ->once()
            ->with('user_devices:15')
            ->andReturn([
                '121:8.8.8.8' => time(),
                '122:8.8.8.8' => time(),
                '122:10.0.0.2' => time(),
                '122:192.0.2.1' => time(),
                '122:2001:4860:4860::8888' => time(),
            ]);

        $devices = (new DeviceStateService())->getUsersDevices([15]);

        $this->assertSame(
            ['2001:4860:4860::', '8.8.8.8'],
            $devices[15]
        );
        $this->assertTrue(array_is_list($devices[15]));
    }

    public function test_ipv6_temporary_addresses_and_old_node_records_share_one_prefix(): void
    {
        Redis::shouldReceive('hgetall')->once()->with('user_devices:15')->andReturn([
            '121:2400:cb00:1:2::10' => time(),
            '122:2400:cb00:1:2::abcd' => time(),
            '123:2400:cb00:1:2::' => time(),
            '124:2400:cb00:1:3::1' => time(),
            '125:::ffff:8.8.8.8' => time(),
            '126:8.8.8.8' => time(),
            '127:2400:cb00:1:4::1' => time() - 301,
        ]);

        $this->assertSame(
            ['2400:cb00:1:2::', '2400:cb00:1:3::', '8.8.8.8'],
            (new DeviceStateService())->getDeviceIPs(15)
        );
    }

}
