<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\InboundIpRecorder;
use App\Services\IpProperties;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InboundIpWebLookupTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
        config(['app.timezone' => 'Asia/Shanghai']);
        $this->travelTo(\Carbon\Carbon::parse('2026-10-14 15:00:00', 'Asia/Shanghai'));
        $this->user = User::create(['email' => 'web-lookup@example.test', 'password' => Str::random(32),
            'uuid' => (string) Str::uuid(), 'token' => Str::random(32), 'is_admin' => true]);
        Sanctum::actingAs($this->user);
    }

    private function page(string $ip): string
    {
        return str_replace('8.8.8.8', $ip, file_get_contents(base_path('tests/Fixtures/ip2location-web.html')));
    }

    private function record(array $ips, bool $apiReady = true): void
    {
        app(InboundIpRecorder::class)->record([$this->user->id => $ips], now()->timestamp);
        DB::table('v2_inbound_ip')->whereIn('ip', $ips)->update([
            'region' => '广东省广州市', 'province' => '广东省', 'local_expires_at' => now()->addDays(30)->timestamp,
        ]);
        if ($apiReady) DB::table('v2_inbound_ip')->whereIn('ip', $ips)->update([
            'asn' => 'AS64501', 'as_name' => 'API Network',
            'external_expires_at' => now()->addDays(30)->timestamp, 'lookup_after' => now()->addDays(30)->timestamp,
            'ip_properties' => json_encode(IpProperties::fromResponse(['isp' => '本地运营商', 'is_proxy' => false,
                'proxy' => ['is_vpn' => false]])),
        ]);
    }

    private function details(): \Illuminate\Testing\TestResponse
    {
        return $this->getJson('/api/v2/' . hash('crc32b', config('app.key')) . '/statistics/inboundUser?user_id=' . $this->user->id);
    }

    public function test_background_supplements_existing_data_without_requerying_asn_or_changing_history(): void
    {
        $this->record(['8.8.8.8', '1.1.1.1']);
        $before = DB::table('v2_stat_user_inbound_ip')->orderBy('ip')->get()->toArray();
        Http::fake(['www.ip2location.com/*' => fn ($request) => Http::response($this->page(rawurldecode(basename($request->url()))), 200)]);
        $this->artisan('inbound-ip:refresh')->assertSuccessful();
        Http::assertSentCount(2);
        $this->details()->assertOk()->assertJsonPath('data.list.0.ip_properties.usage_type', 'DCH')
            ->assertJsonPath('data.list.0.ip_properties.isp', 'Test Carrier & Transit')
            ->assertJsonPath('data.list.0.ip_properties.is_proxy', false)
            ->assertJsonPath('data.list.0.ip_properties.fraud_score', 0)
            ->assertJsonPath('data.list.0.ip_properties.as_domain', 'network.example')
            ->assertJsonPath('data.list.0.ip_properties.net_speed', 'DSL')
            ->assertJsonPath('data.list.0.ip_properties.mobile_brand', 'Test Mobile')
            ->assertJsonPath('data.list.0.ip_properties.district', 'Test District')
            ->assertJsonPath('data.list.0.ip_properties.proxy.is_vpn', false)
            ->assertJsonPath('data.list.0.ip_properties.proxy.is_tor', false)
            ->assertJsonPath('data.list.0.asn', 'AS64501')->assertJsonPath('data.list.0.region', '广东省广州市');
        Cache::flush();
        $this->artisan('inbound-ip:refresh')->assertSuccessful();
        $this->details()->assertOk()->assertJsonPath('data.list.0.ip_properties.usage_type', 'DCH')
            ->assertJsonPath('data.list.0.ip_properties.fraud_score', 0)
            ->assertJsonPath('data.list.0.ip_properties.as_domain', 'network.example');
        Http::assertSentCount(2);
        $this->assertEquals($before, DB::table('v2_stat_user_inbound_ip')->orderBy('ip')->get()->toArray());
        $this->assertSame(now()->addDays(30)->timestamp, (int) DB::table('v2_inbound_ip')->where('ip', '8.8.8.8')->value('external_expires_at'));
    }

    public function test_upgrade_requeues_incomplete_web_data_without_reusing_legacy_cache_or_changing_history(): void
    {
        $this->record(['8.8.8.8', '1.1.1.1']);
        $expires = now()->addDays(30)->timestamp;
        $incomplete = ['asn' => 'AS64500', 'as_name' => 'Web Network',
            'ip_properties' => IpProperties::fromResponse(['isp' => 'Web Carrier', 'is_proxy' => false])];
        $complete = $incomplete;
        $complete['source'] = 'ip2location.com';
        $complete['ip_properties']['usage_type'] = 'DCH';
        DB::table('v2_inbound_ip')->where('ip', '8.8.8.8')->update([
            'web_data' => json_encode($incomplete), 'web_expires_at' => $expires, 'web_lookup_after' => $expires,
        ]);
        DB::table('v2_inbound_ip')->where('ip', '1.1.1.1')->update([
            'web_data' => json_encode($complete), 'web_expires_at' => $expires, 'web_lookup_after' => $expires,
        ]);
        $before = DB::table('v2_inbound_ip')->orderBy('ip')->get()->keyBy('ip');
        $history = DB::table('v2_stat_user_inbound_ip')->orderBy('ip')->get()->toArray();
        $legacyKey = 'inbound_ip_web:v1:' . hash('sha256', '8.8.8.8');
        Cache::put($legacyKey, ['data' => $incomplete, 'expires_at' => $expires, 'retry_at' => $expires], $expires);
        $quotaKey = 'inbound_ip_web:quota:' . now('UTC')->toDateString();
        Cache::put($quotaKey, 7, 86400);

        $migration = require database_path('migrations/2026_10_14_000003_refresh_inbound_ip_web_details.php');
        $migration->up();
        $migration->up();
        $expected = (array) $before['8.8.8.8'];
        $expected['web_expires_at'] = $expected['web_lookup_after'] = 0;
        $this->assertEquals($expected, (array) DB::table('v2_inbound_ip')->where('ip', '8.8.8.8')->first());
        $this->assertEquals($before['1.1.1.1'], DB::table('v2_inbound_ip')->where('ip', '1.1.1.1')->first());
        $this->assertSame(7, Cache::get($quotaKey));

        Http::fake(['www.ip2location.com/*' => Http::response(str_replace('"usage_type": "DCH"',
            '"usage_type": "ISP/MOB"', $this->page('8.8.8.8')), 200)]);
        $this->artisan('inbound-ip:refresh')->assertSuccessful();
        Http::assertSentCount(1);
        $this->assertSame(8, Cache::get($quotaKey));
        $this->assertNull(Cache::get($legacyKey)['data']['ip_properties']['usage_type']);
        $list = collect($this->details()->assertOk()->json('data.list'))->keyBy('ip');
        $this->assertSame('ISP/MOB', $list['8.8.8.8']['ip_properties']['usage_type']);
        $this->assertSame('DCH', $list['1.1.1.1']['ip_properties']['usage_type']);
        $this->assertSame('AS64501', $list['8.8.8.8']['asn']);
        $this->assertFalse($list['8.8.8.8']['ip_properties']['proxy']['is_vpn']);
        $this->assertFalse($list['8.8.8.8']['ip_properties']['proxy']['is_tor']);
        $this->assertTrue($list['8.8.8.8']['ip_properties']['proxy']['is_data_center']);
        foreach (['is_ai_crawler', 'is_web_crawler', 'is_spammer', 'is_scanner', 'is_botnet', 'is_bogon'] as $field) {
            $this->assertFalse($list['8.8.8.8']['ip_properties']['proxy'][$field]);
        }
        $this->assertEquals($history, DB::table('v2_stat_user_inbound_ip')->orderBy('ip')->get()->toArray());

        $refreshed = DB::table('v2_inbound_ip')->orderBy('ip')->get()->toArray();
        $migration->down();
        $migration->up();
        Cache::flush();
        $this->artisan('inbound-ip:refresh')->assertSuccessful();
        Http::assertSentCount(1);
        $this->assertEquals($refreshed, DB::table('v2_inbound_ip')->orderBy('ip')->get()->toArray());
    }

    public function test_opening_details_does_not_fetch_pending_web_data(): void
    {
        $this->record(['8.8.8.8']);
        $this->details()->assertOk()->assertJsonPath('data.list.0.asn', 'AS64501')
            ->assertJsonPath('data.list.0.ip_properties.usage_type', null);
        Http::assertNothingSent();
        $this->assertSame(0, (int) DB::table('v2_inbound_ip')->value('web_lookup_after'));
    }

    public function test_extended_upgrade_bypasses_old_cache_and_retries_without_changing_history_or_quota(): void
    {
        $this->record(['8.8.8.8']);
        $expires = now()->addDays(30)->timestamp;
        $old = ['source' => 'ip2location.com', 'asn' => 'AS64500', 'ip_properties' => ['usage_type' => 'ISP']];
        DB::table('v2_inbound_ip')->update(['web_data' => json_encode($old), 'web_expires_at' => $expires, 'web_lookup_after' => $expires]);
        $before = (array) DB::table('v2_inbound_ip')->first();
        $history = DB::table('v2_stat_user_inbound_ip')->get()->toArray();
        $oldKey = 'inbound_ip_web:v2:' . hash('sha256', '8.8.8.8');
        Cache::put($oldKey, ['data' => $old, 'expires_at' => $expires, 'retry_at' => $expires], 86400);
        $quota = 'inbound_ip_web:quota:' . now('UTC')->toDateString();
        Cache::put($quota, 7, 86400);
        $migration = require database_path('migrations/2026_10_15_000001_refresh_inbound_ip_extended_properties.php');
        $migration->up();
        $migration->up();
        $expected = $before;
        $expected['web_expires_at'] = $expected['web_lookup_after'] = 0;
        $this->assertEquals($expected, (array) DB::table('v2_inbound_ip')->first());
        Http::assertNothingSent();
        $this->assertSame(7, Cache::get($quota));
        Http::fake(['www.ip2location.com/*' => Http::sequence()->push('', 500)->push($this->page('8.8.8.8'), 200)]);
        $this->artisan('inbound-ip:refresh')->assertSuccessful();
        $this->details()->assertOk()->assertJsonPath('data.list.0.ip_properties.fraud_score', null);
        $this->artisan('inbound-ip:refresh')->assertSuccessful();
        Http::assertSentCount(1);
        $this->travel(301)->seconds();
        $this->artisan('inbound-ip:refresh')->assertSuccessful();
        $this->details()->assertOk()->assertJsonPath('data.list.0.ip_properties.fraud_score', 0);
        $this->assertSame(9, Cache::get($quota));
        $this->assertSame($old, Cache::get($oldKey)['data']);
        $saved = DB::table('v2_inbound_ip')->get()->toArray();
        $migration->down();
        $migration->up();
        Cache::flush();
        $this->artisan('inbound-ip:refresh')->assertSuccessful();
        $this->assertEquals($saved, DB::table('v2_inbound_ip')->get()->toArray());
        $this->assertEquals($history, DB::table('v2_stat_user_inbound_ip')->get()->toArray());
        Http::assertSentCount(2);
    }

    public function test_web_failure_retries_without_erasing_api_data_and_expired_web_properties_are_hidden(): void
    {
        $this->record(['8.8.8.8']);
        $before = DB::table('v2_stat_user_inbound_ip')->first();
        Http::fake(['www.ip2location.com/*' => Http::sequence()->push('', 500)->push($this->page('8.8.8.8'), 200)]);
        $this->artisan('inbound-ip:refresh')->assertSuccessful();
        $this->details()->assertOk()->assertJsonPath('data.list.0.asn', 'AS64501');
        Cache::flush();
        $this->artisan('inbound-ip:refresh')->assertSuccessful();
        Http::assertSentCount(1);
        $this->travel(301)->seconds();
        $this->artisan('inbound-ip:refresh')->assertSuccessful();
        $this->details()->assertOk()->assertJsonPath('data.list.0.ip_properties.usage_type', 'DCH');
        Http::assertSentCount(2);
        DB::table('v2_inbound_ip')->update(['web_expires_at' => now()->subSecond()->timestamp]);
        $this->details()->assertOk()->assertJsonPath('data.list.0.ip_properties.usage_type', null)
            ->assertJsonPath('data.list.0.ip_properties.fraud_score', null)
            ->assertJsonPath('data.list.0.ip_properties.as_domain', null)
            ->assertJsonPath('data.list.0.asn', 'AS64501');
        Http::assertSentCount(2);
        $this->assertEquals($before, DB::table('v2_stat_user_inbound_ip')->first());
    }

    public function test_web_results_remain_available_when_api_lookup_is_throttled(): void
    {
        $this->record(['8.8.8.8'], false);
        Http::fake(['api.ip2location.io/*' => Http::response([], 429),
            'www.ip2location.com/*' => Http::response($this->page('8.8.8.8'), 200)]);
        $this->artisan('inbound-ip:refresh')->assertSuccessful();
        $this->details()->assertOk()->assertJsonPath('data.list.0.asn', 'AS64500')
            ->assertJsonPath('data.list.0.as_name', 'Test Network')
            ->assertJsonPath('data.list.0.ip_properties.usage_type', 'DCH');
        Http::assertSentCount(2);
        Cache::flush();
        $this->details()->assertOk()->assertJsonPath('data.list.0.asn', 'AS64500');
        Http::assertSentCount(2);
    }

    public function test_background_batch_is_bounded_and_respects_current_exclusions(): void
    {
        $ips = array_map(fn ($n) => '1.1.1.' . $n, range(1, 40));
        $this->record($ips);
        admin_setting(['device_ip_exclude' => ['1.1.1.1']]);
        Http::fake(['www.ip2location.com/*' => fn ($request) => Http::response($this->page(rawurldecode(basename($request->url()))), 200)]);
        $this->artisan('inbound-ip:refresh')->assertSuccessful();
        Http::assertSentCount(32);
        $this->assertSame(32, DB::table('v2_inbound_ip')->where('web_expires_at', '>', now()->timestamp)->count());
        $this->artisan('inbound-ip:refresh')->assertSuccessful();
        Http::assertSentCount(39);
        $this->assertDatabaseHas('v2_inbound_ip', ['ip' => '1.1.1.1', 'web_expires_at' => 0]);
    }
}
