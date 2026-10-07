<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Server;
use App\Services\InboundIpRecorder;
use App\Services\InboundIpLocation;
use App\Services\DeviceStateService;
use App\Services\NodeStateService;
use App\Services\RealtimeStateStore;
use App\Services\QqwryLocation;
use App\Services\ServerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InboundIpStatisticsTest extends TestCase
{
    use RefreshDatabase;
    private bool $networkFails = false;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'Asia/Shanghai']);
        $this->travelTo(\Carbon\Carbon::parse('2026-10-07 15:00:00', 'Asia/Shanghai'));
        Sanctum::actingAs($this->user(true));
        $this->mock(QqwryLocation::class, function ($mock): void {
            $mock->shouldReceive('lookup')->andReturnUsing(fn ($ip) => [
                'region' => $ip === '8.8.8.8' ? '广东省广州市' : '浙江省杭州市',
                'province' => $ip === '8.8.8.8' ? '广东省' : '浙江省',
            ]);
        });
        Http::fake(fn ($request) => $this->networkFails ? Http::response([], 429) : Http::response(['ip' => $request->data()['ip'], 'asn' => 4134, 'as' => '测试网络',
            'country_code' => 'CN', 'country_name' => 'China', 'region_name' => 'Guangdong', 'city_name' => 'Guangzhou']));
    }

    private function user(bool $admin = false): User
    {
        return User::create(['email' => Str::random(12) . '@example.test', 'password' => Str::random(32),
            'uuid' => (string) Str::uuid(), 'token' => Str::random(32), 'is_admin' => $admin]);
    }

    private function path(string $endpoint, array $params = []): string
    {
        return '/api/v2/' . hash('crc32b', config('app.key')) . '/statistics/' . $endpoint . '?' . http_build_query($params);
    }

    private function record(int $id, array $ips, ?int $at = null): void
    {
        app(InboundIpRecorder::class)->record([$id => $ips], $at);
    }

    public function test_repeated_out_of_order_and_cross_day_reports_preserve_observed_times(): void
    {
        $user = $this->user();
        $now = now()->timestamp;
        $this->record($user->id, ['8.8.8.8', '::ffff:8.8.8.8'], $now - 10);
        $this->record($user->id, ['8.8.8.8'], $now);
        $this->record($user->id, ['8.8.8.8'], $now - 20);
        $this->record($user->id, ['8.8.8.8'], $now - 20);
        $this->record($user->id, ['8.8.8.8'], now()->subDay()->timestamp);
        $this->record($user->id, []);
        $this->assertDatabaseCount('v2_stat_user_inbound_ip', 2);
        $this->assertDatabaseHas('v2_stat_user_inbound_ip', ['user_id' => $user->id, 'record_at' => now()->startOfDay()->timestamp,
            'first_seen_at' => $now - 20, 'last_seen_at' => $now]);
        $this->getJson($this->path('inboundUsers'))->assertOk()->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.list.0.ip_count', 1)->assertJsonPath('data.meta.start_date', '2026-10-07');
        $this->getJson($this->path('inboundUser', ['user_id' => $user->id, 'period' => '7d']))->assertOk()
            ->assertJsonPath('data.list.0.first_seen_at', now()->subDay()->toIso8601String())
            ->assertJsonPath('data.list.0.last_seen_at', now()->toIso8601String());
    }

    public function test_exclusions_apply_before_storage_and_before_counts_groups_and_pagination(): void
    {
        $user = $this->user();
        admin_setting(['device_ip_exclude' => ['8.8.8.8']]);
        $this->record($user->id, ['8.8.8.8', '1.1.1.1', '10.0.0.1', 'invalid', '2400:cb00:1:2::1', '2400:cb00:1:2::2']);
        $this->assertDatabaseCount('v2_stat_user_inbound_ip', 3);
        admin_setting(['device_ip_exclude' => ['1.1.1.0/24', '2400:cb00:1:2::1']]);
        $result = $this->getJson($this->path('inboundUser', ['user_id' => $user->id, 'page_size' => 1]));
        $result->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.ip_count', 1)
            ->assertJsonPath('data.list.0.ip', '2400:cb00:1:2::2')->assertJsonPath('data.list.0.province', '广东省')
            ->assertJsonPath('data.provinces.0.count', 1);
        $this->getJson($this->path('inboundUsers'))->assertOk()->assertJsonPath('data.list.0.ip_count', 1);
        admin_setting(['device_ip_exclude' => ['2400:cb00:1::/48', '1.1.1.0/24']]);
        $this->getJson($this->path('inboundUsers'))->assertOk()->assertJsonPath('data.total', 0);
        admin_setting(['device_ip_exclude' => []]);
        $this->getJson($this->path('inboundUsers'))->assertOk()->assertJsonPath('data.list.0.ip_count', 3);
    }

    public function test_location_sources_province_filter_and_thirty_day_cache(): void
    {
        $user = $this->user();
        $this->record($user->id, ['8.8.8.8', '1.1.1.1', '2400:cb00:1:2::1']);
        $this->getJson($this->path('inboundUser', ['user_id' => $user->id, 'province' => '广东省']))->assertOk()
            ->assertJsonPath('data.total', 2)->assertJsonPath('data.ip_count', 3);
        $this->assertDatabaseHas('v2_inbound_ip', ['ip' => '8.8.8.8', 'region' => '广东省广州市', 'asn' => 'AS4134']);
        $this->assertDatabaseHas('v2_inbound_ip', ['ip' => '2400:cb00:1:2::1', 'region' => 'China Guangdong Guangzhou', 'province' => '广东省']);
        Http::assertSentCount(3);
        $this->travel(29)->days();
        app(InboundIpLocation::class)->refresh(['8.8.8.8', '1.1.1.1', '2400:cb00:1:2::1']);
        Http::assertSentCount(3);
        $this->travel(1)->days();
        app(InboundIpLocation::class)->refresh(['8.8.8.8']);
        Http::assertSentCount(4);
    }

    public function test_lookup_failure_keeps_ipv4_local_and_expired_ipv6_becomes_unknown(): void
    {
        $user = $this->user();
        $this->record($user->id, ['8.8.8.8', '2400:cb00:1:2::1']);
        $this->networkFails = true;
        $this->getJson($this->path('inboundUser', ['user_id' => $user->id]))->assertOk()->assertJsonPath('data.total', 2);
        $this->assertDatabaseHas('v2_inbound_ip', ['ip' => '8.8.8.8', 'region' => '广东省广州市', 'asn' => null]);
        $this->assertDatabaseHas('v2_inbound_ip', ['ip' => '2400:cb00:1:2::1', 'region' => '未知', 'asn' => null]);
        $sent = count(Http::recorded());
        $this->getJson($this->path('inboundUser', ['user_id' => $user->id]))->assertOk();
        Http::assertSentCount($sent);
    }

    public function test_retention_cleanup_validation_deleted_users_and_literal_search(): void
    {
        $user = $this->user();
        $user->update(['email' => 'test_percent_%@example.test']);
        $this->record($user->id, ['8.8.8.8'], now()->startOfDay()->subDays(29)->timestamp);
        $this->record($user->id, ['1.1.1.1'], now()->startOfDay()->subDays(30)->timestamp);
        $this->assertDatabaseCount('v2_stat_user_inbound_ip', 1);
        $this->getJson($this->path('inboundUsers'))->assertOk()->assertJsonPath('data.total', 0);
        $this->getJson($this->path('inboundUsers', ['period' => '30d', 'search' => '%']))->assertOk()->assertJsonPath('data.total', 1);
        foreach ([['period' => 'custom', 'start_date' => '2026-09-07', 'end_date' => '2026-10-07'],
            ['page_size' => 101], ['period' => '24h'], ['start_time' => '12:00']] as $params) {
            $this->getJson($this->path('inboundUsers', $params))->assertUnprocessable();
        }
        $user->delete();
        $this->getJson($this->path('inboundUsers', ['period' => '30d']))->assertOk()->assertJsonPath('data.list.0.name', '#' . $user->id);
        $this->travel(1)->days();
        $this->artisan('reset:log')->assertSuccessful();
        $this->assertDatabaseCount('v2_stat_user_inbound_ip', 0);
        $this->assertDatabaseCount('v2_inbound_ip', 0);
    }

    public function test_database_failure_rolls_back_addresses_and_retry_recovers(): void
    {
        $user = $this->user();
        DB::statement("CREATE TRIGGER fail_inbound BEFORE INSERT ON v2_stat_user_inbound_ip BEGIN SELECT RAISE(ABORT, 'test failure'); END");
        try {
            $this->record($user->id, ['8.8.8.8']);
            $this->fail('应当报告写入失败');
        } catch (\Illuminate\Database\QueryException) {
            $this->assertDatabaseCount('v2_inbound_ip', 0);
        }
        DB::statement('DROP TRIGGER fail_inbound');
        $this->record($user->id, ['8.8.8.8']);
        $this->assertDatabaseCount('v2_stat_user_inbound_ip', 1);
    }

    public function test_http_alive_and_realtime_projection_record_original_receipt_time_once(): void
    {
        $user = $this->user();
        $this->mock(DeviceStateService::class, fn ($mock) => $mock->shouldReceive('replaceNodeDevices')->twice());
        ServerService::processAlive(1, [$user->id => ['1.1.1.1']]);
        $node = Server::create(['name' => '测试入口', 'type' => 'vless', 'host' => 'example.test', 'port' => 443,
            'server_port' => 443, 'rate' => 1, 'group_ids' => [], 'enabled' => true]);
        $store = app(RealtimeStateStore::class);
        $session = $store->begin('node:' . $node->id, bin2hex(random_bytes(16)));
        $store->accept('node:' . $node->id, $session['epoch'], 1, ['alive' => [$user->id => ['8.8.8.8']]]);
        $received = now()->timestamp;
        $this->travel(10)->seconds();
        app(NodeStateService::class)->projectLatest($node);
        $this->travel(5)->seconds();
        app(NodeStateService::class)->projectLatest($node);
        $this->assertDatabaseHas('v2_stat_user_inbound_ip', ['ip' => '8.8.8.8', 'first_seen_at' => $received, 'last_seen_at' => $received]);
        $this->assertDatabaseCount('v2_stat_user_inbound_ip', 2);
    }

    public function test_non_admin_cannot_read_inbound_history(): void
    {
        Sanctum::actingAs($this->user());
        $this->getJson($this->path('inboundUsers'))->assertForbidden();
        $this->getJson($this->path('inboundUser', ['user_id' => 1]))->assertForbidden();
    }

    public function test_province_filter_classifies_all_local_batches_before_pagination(): void
    {
        $user = $this->user();
        $ips = array_map(fn ($index) => '11.0.' . intdiv($index, 256) . '.' . ($index % 256), range(1, 270));
        $this->record($user->id, $ips);
        $this->getJson($this->path('inboundUser', ['user_id' => $user->id, 'province' => '浙江省', 'page_size' => 100, 'page' => 3]))
            ->assertOk()->assertJsonPath('data.total', 270)->assertJsonCount(70, 'data.list')->assertJsonPath('data.last_page', 3);
        $this->assertSame(270, DB::table('v2_inbound_ip')->where('province', '浙江省')->count());
        Http::assertSentCount(32);
    }

    public function test_background_lookup_excludes_front_addresses_and_shares_cache_after_reload(): void
    {
        $user = $this->user();
        $this->record($user->id, ['8.8.8.8', '1.1.1.1']);
        admin_setting(['device_ip_exclude' => ['1.1.1.1']]);
        $this->artisan('inbound-ip:refresh')->assertSuccessful();
        $this->assertDatabaseHas('v2_inbound_ip', ['ip' => '8.8.8.8', 'asn' => 'AS4134']);
        $this->assertDatabaseHas('v2_inbound_ip', ['ip' => '1.1.1.1', 'asn' => null]);
        Http::assertSentCount(1);
        DB::table('v2_inbound_ip')->where('ip', '8.8.8.8')->update(['external_expires_at' => 0, 'lookup_after' => 0]);
        $this->artisan('inbound-ip:refresh')->assertSuccessful();
        Http::assertSentCount(1);
        $this->assertSame(now()->addDays(30)->timestamp, (int) DB::table('v2_inbound_ip')->where('ip', '8.8.8.8')->value('external_expires_at'));
    }

    public function test_expired_ipv6_is_not_grouped_under_an_old_province_during_backoff(): void
    {
        $user = $this->user();
        $this->record($user->id, ['2400:cb00:1:2::1']);
        DB::table('v2_inbound_ip')->update(['region' => '旧地址', 'province' => '广东省', 'asn' => 'AS4134',
            'external_expires_at' => now()->subSecond()->timestamp, 'lookup_after' => now()->addMinutes(5)->timestamp]);
        $this->getJson($this->path('inboundUser', ['user_id' => $user->id]))->assertOk()
            ->assertJsonPath('data.list.0.region', '未知')->assertJsonPath('data.list.0.asn', null)
            ->assertJsonPath('data.provinces.0.name', '未知');
        $this->getJson($this->path('inboundUser', ['user_id' => $user->id, 'province' => '广东省']))->assertOk()
            ->assertJsonPath('data.total', 0);
        Http::assertNothingSent();
    }
}
