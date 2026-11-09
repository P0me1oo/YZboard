<?php

namespace Tests\Feature\Admin;

use App\Http\Controllers\V2\Admin\UserController;
use App\Http\Controllers\V2\Server\ServerController;
use App\Models\Server;
use App\Models\User;
use App\Services\DeviceIpLocationService;
use App\Services\DeviceStateService;
use App\Services\NodeStateValidator;
use App\Services\RealtimeSnapshotService;
use App\Services\RealtimeStateStore;
use App\Services\UserIpConnectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class UserIpConnectionTest extends TestCase
{
    use RefreshDatabase;

    private function node(array $overrides = []): Server
    {
        return Server::create(array_replace([
            'name' => 'IP 连接测试', 'type' => 'vless', 'host' => 'example.invalid',
            'port' => 443, 'server_port' => 443, 'group_ids' => [1], 'enabled' => true, 'rate' => 1,
        ], $overrides));
    }

    private function user(): User
    {
        return User::create(['email' => 'ip-connections@example.invalid', 'password' => 'test-only',
            'uuid' => \App\Utils\Helper::guid(true), 'token' => \App\Utils\Helper::guid(), 'group_id' => 1, 'group_ids' => [1]]);
    }

    public function test_entries_sum_per_user_ip_and_do_not_repeat_relay_landings(): void
    {
        $user = $this->user();
        $first = $this->node();
        $second = $this->node();
        $landing = $this->node(['relay_entry_id' => $first->id]);
        $extra = $this->node(['group_ids' => [2]]);
        $service = app(UserIpConnectionService::class);
        $service->replace($first, [$user->id => ['8.8.8.8' => 10, '1.1.1.1' => 20]]);
        $service->replace($second, [$user->id => ['::ffff:8.8.8.8' => 3]]);
        $service->replace($landing, [$user->id => ['8.8.8.8' => 50]]);
        $service->replace($extra, [$user->id => ['8.8.8.8' => 2]]);
        $rows = array_column($service->forUsers([$user], [])[$user->id], null, 'ip');
        $this->assertSame(15, $rows['8.8.8.8']['connection_count']);
        $this->assertSame(20, $rows['1.1.1.1']['connection_count']);
        $this->assertFalse($rows['8.8.8.8']['connection_count_partial']);
        $this->assertCount(2, $rows);
    }

    public function test_repeat_empty_expiry_old_node_and_recovery(): void
    {
        $user = $this->user(); $first = $this->node(); $second = $this->node();
        $service = app(UserIpConnectionService::class);
        $legacy = [$user->id => ['8.8.8.8']];
        $read = fn () => $service->forUsers([$user], $legacy)[$user->id][0];
        $this->assertNull($read()['connection_count']);
        foreach ([4, 4, 2] as $count) {
            $service->replace($first, [$user->id => ['8.8.8.8' => $count]]);
            $this->assertSame($count, $read()['connection_count']);
            $this->assertTrue($read()['connection_count_partial']);
        }
        $service->replace($second, []);
        $this->assertFalse($read()['connection_count_partial']);
        $service->replace($first, []);
        $this->assertSame(0, $read()['connection_count']);
        $this->assertSame([], $service->forUsers([$user], [])[$user->id]);
        $this->travel(181)->seconds();
        $this->assertNull($read()['connection_count']);
        $service->replace($first, [$user->id => ['8.8.8.8' => 3]]);
        $this->assertSame(3, $read()['connection_count']);
        $service->replace($first, null);
        $this->assertNull($read()['connection_count']);
    }

    public function test_full_ipv6_addresses_are_distinct_but_devices_still_merge(): void
    {
        $user = $this->user(); $node = $this->node();
        $a = '2606:4700:1:2::1'; $b = '2606:4700:1:2::2';
        Redis::shouldReceive('hgetall')->with('user_devices:' . $user->id)->andReturn([
            $node->id . ':' . $a => time(), $node->id . ':' . $b => time(),
        ]);
        $devices = app(DeviceStateService::class);
        $this->assertSame(['2606:4700:1:2::'], $devices->getDeviceIPs($user->id));
        $this->assertSame([$a, $b], $devices->getSourceIPs($user->id));
        $service = app(UserIpConnectionService::class);
        $service->replace($node, [$user->id => [$a => 2, $b => 5, '127.0.0.1' => 99]]);
        $rows = $service->forUsers([$user], [])[$user->id];
        $this->assertSame([$a, $b], array_column($rows, 'ip'));
        $this->assertSame([2, 5], array_column($rows, 'connection_count'));
        app(\App\Support\Setting::class)->set('device_ip_exclude', [$a]);
        $this->assertSame([$b], array_column($service->forUsers([$user], [])[$user->id], 'ip'));
    }

    public function test_realtime_precedes_legacy_and_stale_state_cannot_revive_cached_counts(): void
    {
        $user = $this->user(); $node = $this->node();
        $service = app(UserIpConnectionService::class);
        $service->replace($node, [$user->id => ['8.8.8.8' => 99]]);
        $store = app(RealtimeStateStore::class);
        $source = 'node:' . $node->id;
        $epoch = $store->begin($source, str_repeat('c', 32))['epoch'];
        $store->accept($source, $epoch, 2, ['ip_connection_counts' => [$user->id => ['8.8.8.8' => 3]]]);
        $store->accept($source, $epoch, 1, ['ip_connection_counts' => [$user->id => ['8.8.8.8' => 8]]]);
        $legacy = [$user->id => ['8.8.8.8']];
        $this->assertSame(3, $service->forUsers([$user], $legacy)[$user->id][0]['connection_count']);
        $this->travel(36)->seconds();
        $this->assertNull($service->forUsers([$user], $legacy)[$user->id][0]['connection_count']);
        $store->accept($source, $epoch, 3, ['alive' => [$user->id => ['8.8.8.8']]]);
        $this->assertNull($service->forUsers([$user], [])[$user->id][0]['connection_count']);
    }

    public function test_legacy_report_validation_and_snapshot_replacement(): void
    {
        $user = $this->user(); $node = $this->node();
        $report = function (array $data) use ($node) {
            $request = Request::create('/test/report', 'POST', $data);
            $request->attributes->set('node_info', $node);
            return app(ServerController::class)->report($request);
        };
        $service = app(UserIpConnectionService::class);
        $report(['ip_connection_counts' => [$user->id => ['8.8.8.8' => 7]]]);
        $this->assertSame(7, $service->forUsers([$user], [])[$user->id][0]['connection_count']);
        foreach ([['bad' => []], [$user->id => ['bad' => 1]], [$user->id => ['8.8.8.8' => -1]],
            [$user->id => ['8.8.8.8' => true]], [$user->id => ['8.8.8.8' => 1.5]], [$user->id => ['8.8.8.8' => 2147483648]]] as $bad) {
            try { $report(['ip_connection_counts' => $bad]); $this->fail('非法快照应拒绝'); }
            catch (ValidationException) { $this->assertSame(7, $service->forUsers([$user], [])[$user->id][0]['connection_count']); }
        }
        $report(['ip_connection_counts' => []]);
        $this->assertSame([], $service->forUsers([$user], [])[$user->id]);
        $this->assertSame(['ip_connection_counts' => []], NodeStateValidator::validate(['ip_connection_counts' => []]));
    }

    public function test_http_and_realtime_details_use_same_counts_and_ipv6_addresses(): void
    {
        $user = $this->user(); $node = $this->node();
        $ip = '2606:4700:1:2::1';
        $store = app(RealtimeStateStore::class);
        $source = 'node:' . $node->id;
        $epoch = $store->begin($source, str_repeat('d', 32))['epoch'];
        $store->accept($source, $epoch, 1, ['alive' => [$user->id => [$ip]],
            'connection_counts' => [$user->id => 6], 'ip_connection_counts' => [$user->id => [$ip => 6]]]);
        $this->mock(DeviceStateService::class, function ($mock) {
            $mock->shouldReceive('getSourceIPs', 'getDeviceIPs')->andReturn([]);
        });
        $this->mock(DeviceIpLocationService::class, function ($mock) use ($ip) {
            $mock->shouldReceive('lookupMany')->once()->with([$ip])->andReturn([$ip => ['region' => '测试地区']]);
        });
        $response = app(UserController::class)->devices(Request::create('/test/devices', 'GET', ['id' => $user->id]),
            app(DeviceStateService::class), app(DeviceIpLocationService::class))->getData(true)['data'];
        $snapshots = app(RealtimeSnapshotService::class);
        $frame = $snapshots->snapshot($snapshots->validate(['users' => [$user->id], 'devices' => [$user->id]]));
        $this->assertSame($ip, $response[0]['ip']);
        $this->assertSame(6, $response[0]['connection_count']);
        $this->assertSame(1, $frame['users'][$user->id]['online_count']);
        $this->assertSame(6, $frame['devices'][$user->id]['connections'][0]['connection_count']);
        $this->assertSame($ip, $frame['devices'][$user->id]['connections'][0]['ip']);
        $store->accept($source, $epoch, 2, ['alive' => [], 'ip_connection_counts' => []]);
        $this->assertSame([], $snapshots->snapshot($snapshots->validate(['devices' => [$user->id]]))['devices'][$user->id]['connections']);
    }

    public function test_cache_failure_is_unknown_instead_of_zero(): void
    {
        $user = $this->user(); $node = $this->node();
        Cache::shouldReceive('many')->once()->andThrow(new \RuntimeException('缓存不可用'));
        $rows = app(UserIpConnectionService::class)->forUsers([$user], [$user->id => ['8.8.8.8']], [], [$node]);
        $this->assertNull($rows[$user->id][0]['connection_count']);
    }

    public function test_device_service_extension_keeps_its_custom_source_reader(): void
    {
        $service = new class extends DeviceStateService {
            public function getDeviceIPs(int $userId, bool $legacyOnly = false): array
            {
                return $userId === 7 && $legacyOnly ? ['8.8.8.8'] : [];
            }
        };
        $this->assertSame(['8.8.8.8'], $service->getSourceIPs(7, true));
        $this->assertSame([7 => ['8.8.8.8']], $service->readUsersSourceIPs([7], null, true));
    }
}
