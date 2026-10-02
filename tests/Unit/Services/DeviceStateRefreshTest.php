<?php

namespace Tests\Unit\Services;

use App\Services\DeviceStateService;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class DeviceStateRefreshTest extends TestCase
{
    private function unchangedSource(): void
    {
        Redis::shouldReceive('exists')->once()->with('node_devices_seen:1')->andReturn(1);
        Redis::shouldReceive('smembers')->once()->with('node_devices:1')->andReturn([15]);
        Redis::shouldReceive('hgetall')->once()->with('user_devices:15')->andReturn([
            '1:8.8.8.8' => time() - 20, '2:1.1.1.1' => time(),
        ]);
        Redis::shouldNotReceive('hkeys', 'hdel', 'srem');
    }

    public function test_same_devices_refresh_source_time_and_ttl_without_deleting_other_node_records(): void
    {
        $this->unchangedSource();
        Redis::shouldReceive('pipeline')->once()->andReturnUsing(function ($callback): array {
            $pipe = \Mockery::mock();
            $pipe->shouldReceive('hMset')->once()->withArgs(fn ($key, $fields) =>
                $key === 'user_devices:15' && array_keys($fields) === ['1:8.8.8.8']
                && abs($fields['1:8.8.8.8'] - time()) <= 1);
            $pipe->shouldReceive('expire')->once()->with('user_devices:15', 300);
            $pipe->shouldReceive('sadd')->once()->with('node_devices:1', 15);
            $pipe->shouldReceive('expire')->once()->with('node_devices:1', 600);
            $callback($pipe);
            return [true, true, 0, true];
        });
        Redis::shouldReceive('setnx')->once()->with('device:db_throttle:15', 1)->andReturn(false);
        Redis::shouldReceive('setex')->once()->with('node_devices_seen:1', 600, 1);
        Redis::shouldNotReceive('sadd');
        (new DeviceStateService())->replaceNodeDevices(1, [15 => ['8.8.8.8', '8.8.8.8']]);
    }

    public function test_failed_refresh_is_not_reported_as_success_or_empty_devices(): void
    {
        $this->unchangedSource();
        Redis::shouldReceive('pipeline')->once()->andReturn([true, false, 0, true]);
        Redis::shouldNotReceive('setnx', 'setex', 'sadd');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('设备有效期更新失败');
        (new DeviceStateService())->replaceNodeDevices(1, [15 => ['8.8.8.8']]);
    }
}
