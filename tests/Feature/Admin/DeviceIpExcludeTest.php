<?php

namespace Tests\Feature\Admin;

use App\Http\Controllers\V2\Admin\ConfigController;
use App\Models\Server;
use App\Models\Setting as SettingModel;
use App\Services\DeviceIpExclusion;
use App\Services\DeviceStateService;
use App\Services\ServerService;
use App\Support\Setting;
use App\WebSocket\NodeWorker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * 不计入设备数的来源名单：保存校验、下发节点、面板计数过滤。
 */
class DeviceIpExcludeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Route::post('/_tests/config/save', [ConfigController::class, 'save']);
        Route::get('/_tests/config/fetch', [ConfigController::class, 'fetch']);
    }

    public function test_parse_normalizes_entries(): void
    {
        [$entries, $errors] = DeviceIpExclusion::parse([
            ' 8.8.8.8 ', '1.1.1.9/24', '::ffff:9.9.9.9', '::ffff:4.4.4.0/120',
            '2001:4860::1/48', '5.5.5.5/32', '8.8.8.8', '', null,
        ]);

        $this->assertSame([], $errors);
        $this->assertSame(
            ['8.8.8.8', '1.1.1.0/24', '9.9.9.9', '4.4.4.0/24', '2001:4860::/48', '5.5.5.5'],
            $entries
        );
    }

    public function test_parse_reports_invalid_and_too_broad_entries(): void
    {
        [, $errors] = DeviceIpExclusion::parse(['bad', '1.2.3.4/33', '1.0.0.0/8', '2001::/32', 'fe80::1%eth0', '1.2.3.4/']);

        $this->assertCount(6, $errors);
        $this->assertStringContainsString('第 1 项“bad”', $errors[0]);
        $this->assertStringContainsString('网段过宽', $errors[2]);
        $this->assertStringContainsString('网段过宽', $errors[3]);

        $tooMany = array_map(fn (int $i) => '8.8.' . intdiv($i, 256) . '.' . ($i % 256), range(0, DeviceIpExclusion::MAX_ENTRIES));
        $this->assertSame(['最多填写 256 项'], DeviceIpExclusion::parse($tooMany)[1]);
    }

    public function test_save_normalizes_list_and_pushes_nodes_only_when_changed(): void
    {
        $node = $this->makeServer();
        Cache::put("node_ws_alive:{$node->id}", true);
        $messages = [];
        Redis::shouldReceive('publish')->andReturnUsing(function ($channel, $payload) use (&$messages) {
            $messages[] = json_decode($payload, true);
            return 1;
        });

        $this->postJson('/_tests/config/save', [
            'server_pull_interval' => 60,
            'device_ip_exclude' => ['203.0.114.7', '203.0.115.9/24', '203.0.114.7'],
        ])->assertOk();

        $this->assertSame(['203.0.114.7', '203.0.115.0/24'], admin_setting('device_ip_exclude'));
        $this->assertCount(1, $messages);
        $this->assertSame('sync.config', $messages[0]['event']);
        $this->assertSame($node->id, $messages[0]['node_id']);
        $this->assertSame(['203.0.114.7', '203.0.115.0/24'], $messages[0]['data']['config']['device_ip_exclude']);

        // 设置页整组自动保存；名单等价时不重复推送。
        $messages = [];
        $this->postJson('/_tests/config/save', [
            'server_pull_interval' => 30,
            'device_ip_exclude' => ['203.0.115.0/24', '203.0.114.7'],
        ])->assertOk();
        $this->postJson('/_tests/config/save', ['server_pull_interval' => 60])->assertOk();
        $this->assertSame([], $messages);

        $this->postJson('/_tests/config/save', ['device_ip_exclude' => []])->assertOk();
        $this->assertCount(1, $messages);
        $this->assertArrayNotHasKey('device_ip_exclude', $messages[0]['data']['config']);
    }

    public function test_invalid_list_is_rejected_and_keeps_saved_value(): void
    {
        admin_setting(['device_ip_exclude' => ['203.0.114.7']]);

        $this->postJson('/_tests/config/save', ['device_ip_exclude' => ['203.0.114.8', '0.0.0.0/0']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('device_ip_exclude');

        $this->assertSame(['203.0.114.7'], admin_setting('device_ip_exclude'));
    }

    public function test_fetch_and_node_config_expose_list_only_when_set(): void
    {
        $node = $this->makeServer();
        $this->getJson('/_tests/config/fetch?key=server')->assertOk()->assertJsonPath('data.server.device_ip_exclude', []);
        $this->assertArrayNotHasKey('device_ip_exclude', ServerService::buildNodeConfig($node));

        admin_setting(['device_ip_exclude' => ['203.0.114.7']]);
        $this->getJson('/_tests/config/fetch?key=server')->assertJsonPath('data.server.device_ip_exclude', ['203.0.114.7']);
        $this->assertSame(['203.0.114.7'], ServerService::buildNodeConfig($node)['device_ip_exclude']);
    }

    public function test_device_count_skips_excluded_sources(): void
    {
        admin_setting(['device_ip_exclude' => ['203.0.114.0/24', '2001:4860:4860::/48']]);
        Redis::shouldReceive('hgetall')->with('user_devices:15')->andReturn([
            '121:203.0.114.7' => time(),
            '122:8.8.8.8' => time(),
            '122:::ffff:203.0.114.8' => time(),
            '123:2001:4860:4860::8888' => time(),
            '123:2606:4700::1111' => time(),
        ]);

        $service = new DeviceStateService();
        $this->assertSame(['2606:4700::', '8.8.8.8'], $service->getDeviceIPs(15));
        $this->assertSame(2, $service->getDeviceCount(15));

        // 名单变化后按新名单重新判断，无需等待记录过期。
        admin_setting(['device_ip_exclude' => []]);
        $this->assertSame(5, $service->getDeviceCount(15));
    }

    public function test_websocket_worker_refresh_reads_settings_saved_by_other_processes(): void
    {
        admin_setting(['device_ip_exclude' => ['203.0.114.7']]);
        $this->assertSame(['203.0.114.7'], DeviceIpExclusion::entries());

        // 模拟管理请求在另一个进程保存：数据库和共享缓存已更新，本进程实例仍是旧值。
        SettingModel::createOrUpdate('device_ip_exclude', ['203.0.114.8']);
        Cache::store('redis')->forget(Setting::CACHE_KEY);
        $this->assertSame(['203.0.114.7'], DeviceIpExclusion::entries());

        NodeWorker::refreshSettings();
        $this->assertSame(['203.0.114.8'], DeviceIpExclusion::entries());
    }

    public function test_ipv6_exclusion_checks_full_address_before_grouping(): void
    {
        admin_setting(['device_ip_exclude' => ['2400:cb00:1:2::53']]);
        $this->assertNull(DeviceIpExclusion::countKey('2400:cb00:1:2::53'));
        $this->assertSame('2400:cb00:1:2::', DeviceIpExclusion::countKey('2400:cb00:1:2::54'));
        $this->assertSame('2400:cb00:1:2::', DeviceIpExclusion::countKey('[2400:cb00:1:2::54]:443'));
        $this->assertSame('8.8.8.8', DeviceIpExclusion::countKey('::ffff:8.8.8.8'));
        admin_setting(['device_ip_exclude' => ['2400:cb00:1:2::/64']]);
        $this->assertNull(DeviceIpExclusion::countKey('2400:cb00:1:2::54'));
    }

    public function test_device_snapshot_keeps_full_ipv6_address_before_exclusion(): void
    {
        admin_setting(['device_ip_exclude' => ['2400:cb00:1:2::53']]);
        Redis::shouldReceive('hkeys')->once()->with('user_devices:15')->andReturn([]);
        Redis::shouldReceive('srem')->once();
        Redis::shouldReceive('hMset')->once()->withArgs(function ($key, $fields): bool {
            return $key === 'user_devices:15'
                && array_keys($fields) === ['121:2400:cb00:1:2::53', '121:2400:cb00:1:2::54'];
        });
        Redis::shouldReceive('expire')->twice();
        Redis::shouldReceive('sadd')->once();
        Redis::shouldReceive('setex')->once();
        Redis::shouldReceive('setnx')->once()->andReturn(false);

        (new DeviceStateService())->setDevices(15, 121, ['2400:cb00:1:2::53', '2400:cb00:1:2::54']);
    }

    private function makeServer(): Server
    {
        return Server::create([
            'name' => 'device-ip-exclude-test',
            'type' => Server::TYPE_VMESS,
            'host' => '127.0.0.1',
            'port' => 443,
            'server_port' => 443,
            'rate' => '1',
            'group_ids' => [1],
            'enabled' => true,
        ]);
    }
}
