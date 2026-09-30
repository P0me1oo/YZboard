<?php

namespace Tests\Unit\Services;

use App\Services\DeviceStateService;
use App\Services\RealtimeStateStore;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class DeviceStateServiceTest extends TestCase
{
    public function test_custom_single_user_reader_is_preserved_for_multiple_users(): void
    {
        $service = new class extends DeviceStateService {
            public function getDeviceIPs(int $userId, bool $legacyOnly = false): array
            {
                return $userId === 15 ? ['8.8.8.8'] : [];
            }
        };
        $this->assertSame([15 => ['8.8.8.8']], $service->getUsersDevices([15, 16]));
    }

    public function test_batch_read_shares_node_states_and_preserves_device_filtering(): void
    {
        $now = time();
        $records = [
            ['1:8.8.8.8' => $now, '2:1.1.1.1' => $now, '1:10.0.0.1' => $now],
            ['1:8.8.4.4' => $now, '3:9.9.9.9' => $now, '1:1.0.0.1' => $now - 301],
        ];
        Redis::shouldReceive('pipeline')->once()->andReturnUsing(function ($callback) use ($records) {
            $pipe = \Mockery::mock();
            $pipe->shouldReceive('hgetall')->once()->with('user_devices:15');
            $pipe->shouldReceive('hgetall')->once()->with('user_devices:16');
            $callback($pipe);
            return $records;
        });
        $this->mock(RealtimeStateStore::class, function ($mock) {
            $mock->shouldReceive('readMany')->once()->with(['node:1', 'node:2', 'node:3'])->andReturn([
                'node:1' => ['fresh' => true], 'node:2' => ['fresh' => false], 'node:3' => null,
            ]);
        });
        $this->assertSame([15 => ['8.8.8.8'], 16 => ['8.8.4.4', '9.9.9.9']],
            (new DeviceStateService())->getUsersDevices([15, 16, 15]));
    }

    public function test_next_batch_rereads_freshness_and_clears_expired_node_devices(): void
    {
        $records = [['1:8.8.8.8' => time()], ['1:1.1.1.1' => time()]];
        Redis::shouldReceive('pipeline')->twice()->andReturn($records);
        $this->mock(RealtimeStateStore::class, function ($mock) {
            $mock->shouldReceive('readMany')->twice()->with(['node:1'])->andReturn(
                ['node:1' => ['fresh' => true]], ['node:1' => ['fresh' => false]]
            );
        });
        $service = new DeviceStateService();
        $this->assertCount(2, $service->getUsersDevices([15, 16]));
        $this->assertSame([], $service->getUsersDevices([15, 16]));
    }

    public function test_failed_batch_is_not_interpreted_as_an_empty_device_list(): void
    {
        Redis::shouldReceive('pipeline')->once()->andReturn([[], false]);
        $this->expectException(\RuntimeException::class);
        (new DeviceStateService())->getUsersDevices([15, 16]);
    }

    public function test_device_changes_notify_other_nodes_but_repeated_snapshot_does_not(): void
    {
        $service = \Mockery::mock(DeviceStateService::class)->makePartial();
        $service->shouldReceive('getNodeDevices')->andReturn([15 => ['8.8.8.8']]);
        $service->shouldReceive('setDevices')->twice();
        $service->shouldReceive('removeNodeDevices')->once()->with(1, 15);
        $service->shouldReceive('notifyUpdate')->once()->with(15, true);
        Redis::shouldReceive('setex')->times(3)->with('node_devices_seen:1', 600, 1);
        Redis::shouldReceive('del')->once()->with('node_devices:1');
        // 换 IP 和最后一个设备下线各通知一次；重复的相同快照不广播。
        Redis::shouldReceive('sadd')->twice()->with('device:push_pending_nodes', 0)->andReturn(1);
        $service->replaceNodeDevices(1, [15 => ['8.8.8.8']]);
        $service->replaceNodeDevices(1, [15 => ['1.1.1.1']]);
        $service->replaceNodeDevices(1, []);
    }

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
