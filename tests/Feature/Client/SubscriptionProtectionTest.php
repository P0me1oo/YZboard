<?php

namespace Tests\Feature\Client;

use App\Http\Controllers\V2\Admin\ConfigController;
use App\Http\Controllers\V2\Admin\SubscriptionProtectionController;
use App\Models\User;
use App\Services\SubscriptionClientControl;
use App\Services\SubscriptionProtection;
use App\Support\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SubscriptionProtectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('v2board', []);
        $this->freezeTime();
        Route::middleware('admin')->group(function () {
            Route::get('/_tests/protection/config', [ConfigController::class, 'fetch']);
            Route::post('/_tests/protection/config', [ConfigController::class, 'save']);
            Route::get('/_tests/protection/users', [SubscriptionProtectionController::class, 'users']);
            Route::get('/_tests/protection/restrictions', [SubscriptionProtectionController::class, 'restrictions']);
            Route::post('/_tests/protection/release', [SubscriptionProtectionController::class, 'release']);
        });
        Route::get('/_tests/custom-sub/{token}', [\App\Http\Controllers\V1\Client\ClientController::class, 'subscribe'])
            ->middleware('client')->name('client.subscribe');
    }

    private function user(array $values = []): User
    {
        return User::create($values + [
            'email' => Str::random(12) . '@example.invalid', 'password' => bcrypt(Str::random(24)),
            'uuid' => (string) Str::uuid(), 'token' => Str::random(32),
            'transfer_enable' => 1024, 'u' => 0, 'd' => 0, 'banned' => false, 'expired_at' => null,
        ]);
    }

    private function configure(array $values): array
    {
        $settings = array_replace(SubscriptionProtection::defaults(), $values);
        admin_setting([SubscriptionProtection::SETTING => $settings]);
        return $settings;
    }

    private function download(?User $user = null, string $ip = '198.51.100.10', bool $legacy = false, array $headers = [])
    {
        $token = $user?->token ?? Str::random(32);
        $url = $legacy ? '/api/v1/client/subscribe?' . http_build_query(['token' => $token]) : '/s/' . $token;
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->get($url, $headers + ['User-Agent' => 'Clash/1.0']);
    }

    private function assertSafe($response, int $status, ?User $user = null): void
    {
        $response->assertStatus($status)->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        if ($user) $response->assertDontSee($user->uuid)->assertDontSee($user->token);
    }

    public function test_defaults_and_persisted_configuration_remain_separate_from_client_rules(): void
    {
        $admin = $this->user(['is_admin' => true]);
        $this->actingAs($admin, 'sanctum');
        $this->getJson('/_tests/protection/config?key=subscribe')->assertOk()
            ->assertJsonPath('data.subscribe.subscribe_protection', SubscriptionProtection::defaults());
        $this->download($admin, headers: ['User-Agent' => 'Mozilla/5.0'])->assertOk();
        $settings = array_replace(SubscriptionProtection::defaults(), [
            'block_browser' => 1, 'whitelist_ips' => ['198.51.100.29/24', '198.51.100.0/24', '::ffff:192.0.2.1', '2001:DB8::123/64'],
            'whitelist_user_ids' => [$admin->id],
        ]);
        $this->postJson('/_tests/protection/config', [SubscriptionProtection::SETTING => $settings])->assertOk()->assertJsonPath('data', true);
        $this->app->forgetInstance(Setting::class);
        $this->getJson('/_tests/protection/config?key=subscribe')
            ->assertJsonPath('data.subscribe.subscribe_protection.block_browser', true)
            ->assertJsonPath('data.subscribe.subscribe_protection.whitelist_ips', ['198.51.100.0/24', '192.0.2.1', '2001:db8::/64']);
        $this->postJson('/_tests/protection/config', ['subscribe_path' => 's'])->assertOk();
        $this->assertTrue(SubscriptionProtection::settings()['block_browser']);
        $this->assertSame(SubscriptionClientControl::defaults(), SubscriptionClientControl::settings());
        $this->postJson('/_tests/protection/config', ['admin_sidebar_hidden' => ['subscription-protection']])->assertOk();
    }

    public static function agents(): array
    {
        return [
            ['Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 Chrome/130.0 Safari/537.36', true],
            ['Mozilla/5.0 Firefox/130.0', true], ['Opera/9.80', true],
            ['Clash/1.0', false], ['mihomo/1.19', false], ['sing-box/1.12', false],
            ['Surge/5', false], ['Stash/2', false], ['Shadowrocket/2', false],
            ['UnknownClient/1.0', false], ['', false],
        ];
    }

    #[DataProvider('agents')]
    public function test_browser_detection_on_both_routes(string $agent, bool $blocked): void
    {
        $this->configure(['block_browser' => true]);
        $user = $this->user();
        foreach ([false, true] as $legacy) {
            $response = $this->download($user, legacy: $legacy, headers: ['User-Agent' => $agent]);
            $blocked ? $this->assertSafe($response, 403, $user) : $response->assertOk();
        }
    }

    public function test_navigation_headers_and_format_flags_do_not_bypass_browser_protection(): void
    {
        $this->configure(['block_browser' => true]);
        $user = $this->user();
        foreach ([['Sec-Fetch-Mode' => 'navigate'], ['Sec-Fetch-Dest' => 'document'], ['Sec-Fetch-Dest' => 'iframe']] as $headers) {
            $this->assertSafe($this->download($user, headers: $headers), 403, $user);
        }
        $this->get('/_tests/custom-sub/' . $user->token . '?flag=clash', ['User-Agent' => 'Mozilla/5.0'])->assertForbidden();
    }

    public function test_rate_limit_shares_both_routes_counts_failures_and_recovers_at_window_end(): void
    {
        $this->configure(['rate_limit_enabled' => true, 'rate_limit_max_requests' => 2]);
        $user = $this->user();
        $this->download($user)->assertOk();
        $this->download(legacy: true)->assertForbidden();
        $response = $this->download($user, legacy: true);
        $this->assertSafe($response, 429, $user);
        $response->assertHeader('Retry-After', '60');
        $this->download($user, '198.51.100.11')->assertOk();
        $this->travel(30)->seconds();
        $this->download($user)->assertStatus(429)->assertHeader('Retry-After', '30');
        $this->travel(30)->seconds();
        $this->download($user)->assertOk();
    }

    public function test_brute_force_failure_threshold_and_ban_do_not_slide_on_repeated_requests(): void
    {
        $this->configure(['brute_force_enabled' => true, 'brute_force_max_failures' => 2, 'brute_force_block_minutes' => 1]);
        $user = $this->user();
        $this->download()->assertForbidden();
        $this->download($user, legacy: true)->assertOk();
        $this->download(legacy: true)->assertStatus(429)->assertHeader('Retry-After', '60');
        $this->assertSafe($this->download($user), 429, $user);
        $this->travel(40)->seconds();
        $this->download()->assertStatus(429)->assertHeader('Retry-After', '20');
        $this->travel(20)->seconds();
        $this->download($user)->assertOk();
        $this->download()->assertForbidden();
    }

    public function test_first_failure_can_block_and_expired_failure_window_starts_over(): void
    {
        $this->configure(['brute_force_enabled' => true, 'brute_force_max_failures' => 1]);
        $this->download()->assertStatus(429);
        $this->configure(['brute_force_enabled' => true, 'brute_force_max_failures' => 2, 'brute_force_window_minutes' => 1]);
        $this->download()->assertForbidden();
        $this->travel(60)->seconds();
        $this->download()->assertForbidden();
        $this->download()->assertStatus(429);
    }

    public function test_account_and_browser_denials_are_not_probe_failures(): void
    {
        $this->configure(['block_browser' => true, 'brute_force_enabled' => true, 'brute_force_max_failures' => 1]);
        foreach ([['banned' => true], ['expired_at' => time() - 10], ['transfer_enable' => 0]] as $values) {
            $this->download($this->user($values))->assertForbidden();
        }
        $user = $this->user();
        $this->download($user, headers: ['User-Agent' => 'Mozilla/5.0'])->assertForbidden();
        $this->download($user)->assertOk();
        // 原订阅接口允许已用完流量的有效账号下载，保留此行为。
        $this->download($this->user(['u' => 1024]))->assertOk();
    }

    public static function invalidTokens(): array
    {
        return [[[]], [['token' => ['invalid']]], [['token' => '']], [['token' => str_repeat('x', 256)]], [['token' => ['nested' => ['x']]]]];
    }

    #[DataProvider('invalidTokens')]
    public function test_missing_and_malformed_tokens_count_without_query_errors(array $query): void
    {
        $this->configure(['brute_force_enabled' => true, 'brute_force_max_failures' => 2]);
        $url = '/api/v1/client/subscribe?' . http_build_query($query);
        $this->get($url)->assertForbidden();
        $this->get($url)->assertStatus(429);
    }

    public function test_blocked_ip_does_not_query_users_when_no_user_whitelist_is_configured(): void
    {
        $this->configure(['brute_force_enabled' => true, 'brute_force_max_failures' => 1]);
        $this->download()->assertStatus(429);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->download()->assertStatus(429);
        $this->assertCount(0, array_filter(DB::getQueryLog(), fn ($query) => str_contains($query['query'], 'v2_user')));
        DB::disableQueryLog();
    }

    public function test_whitelisted_user_bypasses_ip_ban_only_with_their_own_valid_token(): void
    {
        $allowed = $this->user();
        $other = $this->user();
        $this->configure(['brute_force_enabled' => true, 'brute_force_max_failures' => 1, 'block_browser' => true,
            'rate_limit_enabled' => true, 'rate_limit_max_requests' => 1, 'whitelist_user_ids' => [$allowed->id]]);
        $this->download()->assertStatus(429);
        foreach ([false, true] as $legacy) {
            $this->download($allowed, legacy: $legacy, headers: ['User-Agent' => 'Mozilla/5.0'])->assertOk();
            $this->download($other, legacy: $legacy)->assertStatus(429);
        }
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.10'])->get('/api/v1/client/subscribe?' . http_build_query([
            'token' => Str::random(32), 'user_id' => $allowed->id,
        ]))->assertStatus(429);
        $allowed->update(['token' => Str::random(32), 'banned' => true]);
        $this->download($allowed)->assertForbidden();
    }

    public static function ips(): array
    {
        return [
            ['198.51.100.10', '198.51.100.10', true], ['198.51.100.0/24', '198.51.100.255', true],
            ['198.51.100.0/24', '198.51.101.1', false], ['2001:db8::/64', '2001:db8::abcd', true],
            ['2001:db8::/64', '2001:db8:0:1::1', false], ['192.0.2.1', '::ffff:192.0.2.1', true],
            ['::1', '::1', true], ['127.0.0.1', '127.0.0.1', true],
        ];
    }

    #[DataProvider('ips')]
    public function test_ip_and_network_whitelist(string $entry, string $ip, bool $allowed): void
    {
        $this->configure(['block_browser' => true, 'brute_force_enabled' => true, 'brute_force_max_failures' => 1,
            'rate_limit_enabled' => true, 'rate_limit_max_requests' => 1, 'whitelist_ips' => [$entry]]);
        $user = $this->user();
        $this->download(ip: $ip)->assertStatus($allowed ? 403 : 429);
        foreach ([false, true] as $legacy) {
            $this->download($user, $ip, $legacy, ['User-Agent' => 'Mozilla/5.0'])->assertStatus($allowed ? 200 : 429);
        }
    }

    public function test_ip_whitelist_applies_immediately_but_never_bypasses_account_or_client_rules(): void
    {
        $settings = $this->configure(['brute_force_enabled' => true, 'brute_force_max_failures' => 1]);
        $this->download()->assertStatus(429);
        $this->configure($settings); // 重复保存不解除封禁。
        $this->download()->assertStatus(429);
        $settings['whitelist_ips'] = ['198.51.100.10'];
        $this->configure($settings);
        $user = $this->user();
        $this->download($user)->assertOk();
        foreach ([['banned' => true], ['expired_at' => time() - 10], ['transfer_enable' => 0]] as $values) {
            $this->download($this->user($values))->assertForbidden();
        }
        admin_setting([SubscriptionClientControl::SETTING => array_replace(SubscriptionClientControl::defaults(), ['mode' => 'blacklist'])]);
        $this->download($user)->assertForbidden();
        $settings['whitelist_ips'] = [];
        $this->configure($settings);
        $this->download($user)->assertStatus(429);
    }

    public function test_forwarding_headers_from_untrusted_sources_cannot_spoof_whitelist_or_counter(): void
    {
        $this->configure(['rate_limit_enabled' => true, 'rate_limit_max_requests' => 1, 'whitelist_ips' => ['192.0.2.1']]);
        $user = $this->user();
        $this->download($user, headers: ['X-Forwarded-For' => '192.0.2.1'])->assertOk();
        $this->download($user, headers: ['X-Forwarded-For' => '192.0.2.2', 'CF-Connecting-IP' => '192.0.2.1'])->assertStatus(429);
    }

    public function test_trusted_proxy_uses_forwarded_source_and_local_sources_are_not_automatically_exempt(): void
    {
        $this->configure(['rate_limit_enabled' => true, 'rate_limit_max_requests' => 1]);
        $user = $this->user();
        foreach (['198.51.100.50', '198.51.100.51'] as $ip) {
            $this->download($user, '127.0.0.1', headers: ['X-Forwarded-For' => $ip])->assertOk();
            $this->download($user, '127.0.0.1', headers: ['X-Forwarded-For' => $ip])->assertStatus(429);
        }
        $this->flushHeaders();
        $this->download($user, '127.0.0.1')->assertOk();
        $this->download($user, '127.0.0.1')->assertStatus(429);
    }

    public function test_limits_cover_config_download_but_not_version_queries_and_can_be_adjusted(): void
    {
        $settings = $this->configure(['rate_limit_enabled' => true, 'rate_limit_max_requests' => 1]);
        $user = $this->user();
        $this->download($user)->assertOk();
        $this->download($user)->assertStatus(429);
        $this->get('/api/v1/client/app/getConfig?' . http_build_query(['token' => $user->token]))->assertStatus(429);
        $this->get('/api/v1/client/app/getVersion?' . http_build_query(['token' => $user->token]))->assertOk();
        $settings['rate_limit_max_requests'] = 2;
        $this->configure($settings);
        $this->download($user)->assertOk();
        $this->download($user)->assertOk();
        $this->download($user)->assertStatus(429);
        $this->configure(['rate_limit_enabled' => false]);
        $this->download($user)->assertOk();
    }

    public function test_legacy_app_configuration_download_cannot_bypass_browser_or_probe_protection(): void
    {
        $user = $this->user();
        $url = '/api/v1/client/app/getConfig?' . http_build_query(['token' => $user->token]);
        $this->configure(['block_browser' => true]);
        $this->get($url, ['User-Agent' => 'Mozilla/5.0'])->assertForbidden();
        $this->get($url, ['User-Agent' => 'Clash/1.0'])->assertOk();
        $this->configure(['brute_force_enabled' => true, 'brute_force_max_failures' => 1]);
        $this->get('/api/v1/client/app/getConfig?token=')->assertStatus(429);
        $this->get($url)->assertStatus(429);
    }

    public function test_cache_unavailable_does_not_silently_allow_download(): void
    {
        $this->configure(['rate_limit_enabled' => true]);
        SubscriptionProtection::settings();
        Cache::shouldReceive('store')->with('redis')->andThrow(new \RuntimeException('测试缓存不可用'));
        $this->assertSafe($this->download(), 503);
    }

    public function test_disabling_then_reenabling_brute_protection_does_not_clear_unexpired_ban(): void
    {
        $settings = $this->configure(['brute_force_enabled' => true, 'brute_force_max_failures' => 1, 'rate_limit_enabled' => true]);
        $this->download()->assertStatus(429);
        $settings['brute_force_enabled'] = false;
        $this->configure($settings);
        $user = $this->user();
        $this->download($user)->assertOk();
        $settings['brute_force_enabled'] = true;
        $this->configure($settings);
        $this->download($user)->assertStatus(429);
    }

    public function test_configuration_read_failure_cannot_fall_back_to_disabled_protection(): void
    {
        $this->configure(['block_browser' => true]);
        $this->app->forgetInstance(Setting::class);
        $cache = \Mockery::mock(\Illuminate\Contracts\Cache\Repository::class);
        $cache->shouldReceive('rememberForever')->andThrow(new \RuntimeException('测试设置读取失败'));
        Cache::shouldReceive('store')->with('redis')->andReturn($cache);
        // 其他页面可以使用原有默认值，但订阅防护必须识别这次读取失败。
        $this->assertSame('fallback', admin_setting('app_name', 'fallback'));
        $this->assertSafe($this->download(), 503);
    }

    public function test_contended_lock_does_not_bypass_limits(): void
    {
        $this->travelBack(); // 锁等待超时使用真实时钟。
        $this->configure(['rate_limit_enabled' => true]);
        $key = 'subscription-protection:' . hash('sha256', '198.51.100.10') . ':lock';
        $lock = Cache::store('redis')->lock($key, 10);
        $this->assertTrue($lock->get());
        try {
            $this->assertSafe($this->download(), 503);
        } finally {
            $lock->release();
        }
        $this->download($this->user())->assertOk();
    }

    public static function invalidSettings(): array
    {
        return [
            ['block_browser', null], ['block_browser', 'yes'], ['brute_force_max_failures', 0],
            ['brute_force_window_minutes', 10081], ['brute_force_block_minutes', -1],
            ['rate_limit_max_requests', 1.2], ['rate_limit_window_minutes', ''],
            ['user_rate_limit_enabled', null], ['user_rate_limit_max_requests', 0],
            ['user_rate_limit_window_minutes', 10081], ['notify_telegram', 'yes'],
            ['whitelist_ips', ['example.com']], ['whitelist_ips', ['192.0.2.1:443']],
            ['whitelist_ips', ['192.0.2.0/33']], ['whitelist_ips', ['2001:db8::/129']],
            ['whitelist_ips', ['*']], ['whitelist_ips', [['invalid']]],
            ['whitelist_ips', array_fill(0, 257, '192.0.2.1')],
            ['whitelist_user_ids', [999999]], ['whitelist_user_ids', ['bad']],
        ];
    }

    #[DataProvider('invalidSettings')]
    public function test_invalid_settings_are_rejected_atomically(string $key, mixed $value): void
    {
        $this->actingAs($this->user(['is_admin' => true]), 'sanctum');
        $original = $this->configure(['block_browser' => true]);
        $invalid = array_replace($original, ['block_browser' => false, $key => $value]);
        $this->postJson('/_tests/protection/config', [SubscriptionProtection::SETTING => $invalid])->assertUnprocessable();
        $this->assertSame($original, SubscriptionProtection::settings());
    }

    public function test_partial_or_extra_fields_are_rejected_and_regular_users_cannot_manage_protection(): void
    {
        $this->getJson('/_tests/protection/config')->assertForbidden();
        $this->getJson('/_tests/protection/users?search=test')->assertForbidden();
        $this->actingAs($this->user(), 'sanctum');
        $this->postJson('/_tests/protection/config', [SubscriptionProtection::SETTING => SubscriptionProtection::defaults()])->assertForbidden();
        $this->getJson('/_tests/protection/users?search=test')->assertForbidden();
        $this->actingAs($this->user(['is_admin' => true]), 'sanctum');
        $this->postJson('/_tests/protection/config', [SubscriptionProtection::SETTING => ['block_browser' => true]])->assertUnprocessable();
        $this->postJson('/_tests/protection/config', [SubscriptionProtection::SETTING => SubscriptionProtection::defaults() + ['extra' => true]])->assertUnprocessable();
    }

    public function test_user_search_and_hydration_return_only_identifiers_and_emails(): void
    {
        $admin = $this->user(['is_admin' => true, 'email' => 'admin@example.invalid']);
        $member = $this->user(['email' => 'subscription-member@example.invalid']);
        $this->actingAs($admin, 'sanctum');
        foreach (['subscription-member', (string) $member->id] as $search) {
            $response = $this->getJson('/_tests/protection/users?' . http_build_query(['search' => $search]));
            $response->assertOk()->assertExactJson(['status' => 'success', 'message' => '操作成功', 'error' => null, 'data' => [['id' => $member->id, 'email' => $member->email]]]);
        }
        $this->getJson('/_tests/protection/users?' . http_build_query(['ids' => [$member->id, 999999]]))
            ->assertOk()->assertJsonPath('data', [['id' => $member->id, 'email' => $member->email]]);
        $this->getJson('/_tests/protection/users?search=%25')->assertJsonPath('data', []);
        $this->getJson('/_tests/protection/users')->assertJsonPath('data', []);
        $this->getJson('/_tests/protection/users?search%5B%5D=x')->assertUnprocessable();
    }

    public function test_account_limit_combines_ips_and_all_download_routes_but_isolates_other_accounts(): void
    {
        $this->configure(['user_rate_limit_enabled' => true, 'user_rate_limit_max_requests' => 2]);
        $user = $this->user();
        $this->download($user)->assertOk();
        $this->download($user, '198.51.100.11', true)->assertOk();
        $this->get('/api/v1/client/app/getConfig?' . http_build_query(['token' => $user->token]))
            ->assertStatus(429)->assertHeader('Retry-After', '60');
        $this->download($user, '2001:db8::1')->assertStatus(429);
        $this->download($this->user())->assertOk();
        $this->app->forgetInstance(Setting::class);
        $this->travel(30)->seconds();
        $this->download($user)->assertStatus(429)->assertHeader('Retry-After', '30');
        $this->travel(30)->seconds();
        $this->download($user)->assertOk();
    }

    public function test_account_limit_whitelists_and_invalid_tokens_do_not_consume_account_quota(): void
    {
        $user = $this->user();
        $settings = $this->configure(['user_rate_limit_enabled' => true, 'user_rate_limit_max_requests' => 1,
            'whitelist_ips' => ['192.0.2.0/24']]);
        $this->download()->assertForbidden();
        $this->download($user, '192.0.2.1')->assertOk();
        $this->download($user, '192.0.2.1')->assertOk();
        $this->download($user)->assertOk();
        $this->download($user)->assertStatus(429);
        $this->configure($settings + []);
        $this->download($user)->assertStatus(429);
        $settings['whitelist_user_ids'] = [$user->id];
        $this->configure($settings);
        $this->download($user)->assertOk();
        $user->update(['banned' => true]);
        $this->download($user)->assertForbidden();
    }

    public function test_account_rule_changes_reset_only_that_rule_and_switch_retains_counter(): void
    {
        $settings = $this->configure(['user_rate_limit_enabled' => true, 'user_rate_limit_max_requests' => 1]);
        $user = $this->user();
        $this->download($user)->assertOk();
        $this->download($user)->assertStatus(429);
        $settings['user_rate_limit_enabled'] = false;
        $this->configure($settings);
        $this->download($user)->assertOk();
        $settings['user_rate_limit_enabled'] = true;
        $this->configure($settings);
        $this->download($user)->assertStatus(429);
        $settings['user_rate_limit_max_requests'] = 2;
        $this->configure($settings);
        $this->download($user)->assertOk();
        $this->download($user)->assertOk();
        $this->download($user)->assertStatus(429);
    }

    public function test_account_limit_counts_browser_denials_and_cache_errors_fail_closed(): void
    {
        $this->configure(['block_browser' => true, 'user_rate_limit_enabled' => true, 'user_rate_limit_max_requests' => 1]);
        $user = $this->user();
        $this->download($user, headers: ['User-Agent' => 'Mozilla/5.0'])->assertForbidden();
        $this->download($user)->assertStatus(429);
        Cache::shouldReceive('store')->with('redis')->andThrow(new \RuntimeException('测试缓存不可用'));
        $this->download($user)->assertStatus(503);
    }

    private function restrictions(): array
    {
        $this->actingAs(User::where('is_admin', true)->firstOrFail(), 'sanctum');
        return $this->getJson('/_tests/protection/restrictions')->assertOk()->json('data.items');
    }

    private function release(array $row, bool $whitelist = false)
    {
        $this->actingAs(User::where('is_admin', true)->firstOrFail(), 'sanctum');
        return $this->postJson('/_tests/protection/release', ['id' => $row['id'], 'event_id' => $row['event_id'], 'whitelist' => $whitelist]);
    }

    public function test_restriction_list_and_temporary_release_isolate_both_ip_rules_and_other_ips(): void
    {
        $this->actingAs($this->user(['is_admin' => true]), 'sanctum');
        $this->configure(['brute_force_enabled' => true, 'brute_force_max_failures' => 1,
            'rate_limit_enabled' => true, 'rate_limit_max_requests' => 1]);
        $this->download()->assertStatus(429);
        $this->download(ip: '198.51.100.11')->assertStatus(429);
        $rows = $this->restrictions();
        $this->assertCount(2, $rows);
        $this->release($rows[0])->assertOk();
        // 解探测封禁不清除此 IP 的请求次数。
        $user = $this->user();
        $this->download($user)->assertStatus(429);
        $rows = $this->restrictions();
        $rate = collect($rows)->firstWhere('kind', 'ip_rate');
        $this->release($rate)->assertOk();
        $this->download($user)->assertOk();
        $this->download($user, '198.51.100.11')->assertStatus(429);
    }

    public function test_user_release_does_not_reset_ip_or_other_user_limits_and_stale_release_is_rejected(): void
    {
        $this->actingAs($this->user(['is_admin' => true]), 'sanctum');
        $this->configure(['user_rate_limit_enabled' => true, 'user_rate_limit_max_requests' => 1]);
        $user = $this->user();
        $other = $this->user();
        foreach ([$user, $other] as $member) {
            $this->download($member)->assertOk();
            $this->download($member)->assertStatus(429);
        }
        $row = $this->restrictions()[0];
        $this->assertSame($user->email, $row['email']);
        $this->release($row)->assertOk();
        $this->release($row)->assertStatus(409);
        $this->download($user)->assertOk();
        $this->download($user)->assertStatus(429);
        $this->release($row)->assertStatus(409);
        $this->download($other, '198.51.100.20')->assertStatus(429);
        $this->assertCount(2, $this->restrictions());
    }

    public function test_permanent_user_whitelist_resets_account_only_and_preserves_other_settings(): void
    {
        $this->actingAs($this->user(['is_admin' => true]), 'sanctum');
        $settings = $this->configure(['user_rate_limit_enabled' => true, 'user_rate_limit_max_requests' => 1,
            'rate_limit_enabled' => true, 'rate_limit_max_requests' => 3]);
        $user = $this->user();
        $this->download($user)->assertOk();
        $this->download($user)->assertStatus(429);
        $this->release($this->restrictions()[0], true)->assertOk();
        $this->assertSame([$user->id], SubscriptionProtection::settings()['whitelist_user_ids']);
        $this->download($user)->assertOk();
        $other = $this->user();
        $this->download($other)->assertOk();
        $this->download($other)->assertStatus(429);
        $this->configure($settings);
        $this->download($user, '198.51.100.99')->assertOk();
        $this->assertNull($this->restrictions()[0]['email']);
    }

    public function test_permanent_ip_whitelist_resets_that_ip_and_keeps_other_ips_blocked(): void
    {
        $this->actingAs($this->user(['is_admin' => true]), 'sanctum');
        $settings = $this->configure(['brute_force_enabled' => true, 'brute_force_max_failures' => 1]);
        foreach (['198.51.100.10', '198.51.100.11'] as $ip) $this->download(ip: $ip)->assertStatus(429);
        $this->release($this->restrictions()[0], true)->assertOk();
        $this->assertSame(['198.51.100.10'], SubscriptionProtection::settings()['whitelist_ips']);
        $this->download($this->user())->assertOk();
        $this->download($this->user(), '198.51.100.11')->assertStatus(429);
        $this->configure($settings);
        $this->download($this->user())->assertOk();
        $this->assertCount(1, $this->restrictions());
    }

    public function test_list_ignores_expiry_cache_loss_disabled_rules_and_whitelisted_targets(): void
    {
        $this->actingAs($this->user(['is_admin' => true]), 'sanctum');
        $settings = $this->configure(['rate_limit_enabled' => true, 'rate_limit_max_requests' => 1]);
        $user = $this->user();
        $this->download($user)->assertOk();
        $this->download($user)->assertStatus(429);
        $row = $this->restrictions()[0];
        $this->configure(array_replace($settings, ['rate_limit_enabled' => false]));
        $this->assertSame([], $this->restrictions());
        $this->configure($settings);
        $this->assertCount(1, $this->restrictions());
        $this->configure(array_replace($settings, ['whitelist_ips' => ['198.51.100.0/24']]));
        $this->assertSame([], $this->restrictions());
        $this->configure($settings);
        Cache::store('redis')->forget(\App\Services\SubscriptionRestrictionService::key('ip_rate', '198.51.100.10'));
        $this->assertSame([], $this->restrictions());
        $this->release($row)->assertStatus(409);
        $this->download($user)->assertOk();
        $this->download($user)->assertStatus(429);
        $this->release($row)->assertStatus(409);
        $this->travel(60)->seconds();
        $this->assertSame([], $this->restrictions());
    }

    public function test_list_and_release_require_administrator_and_validate_input(): void
    {
        $this->getJson('/_tests/protection/restrictions')->assertForbidden();
        $this->postJson('/_tests/protection/release', [])->assertForbidden();
        $this->actingAs($this->user(), 'sanctum');
        $this->getJson('/_tests/protection/restrictions')->assertForbidden();
        $this->postJson('/_tests/protection/release', [])->assertForbidden();
        $this->actingAs($this->user(['is_admin' => true]), 'sanctum');
        $this->postJson('/_tests/protection/release', [])->assertUnprocessable();
        $this->getJson('/_tests/protection/restrictions?after=-1')->assertUnprocessable();
    }

    public function test_full_whitelist_does_not_clear_limit_when_action_is_rejected(): void
    {
        $this->actingAs($this->user(['is_admin' => true]), 'sanctum');
        $this->configure(['brute_force_enabled' => true, 'brute_force_max_failures' => 1,
            'whitelist_ips' => array_map(fn ($index) => '192.0.2.' . $index, range(0, 255))]);
        $this->download()->assertStatus(429);
        $this->release($this->restrictions()[0], true)->assertStatus(422);
        $this->download($this->user())->assertStatus(429);
        $this->assertCount(1, $this->restrictions());
    }

    public function test_releasing_user_limit_keeps_an_active_ip_limit_on_the_same_request(): void
    {
        $this->actingAs($this->user(['is_admin' => true]), 'sanctum');
        $this->configure(['user_rate_limit_enabled' => true, 'user_rate_limit_max_requests' => 1,
            'rate_limit_enabled' => true, 'rate_limit_max_requests' => 2]);
        $user = $this->user();
        $this->download($user)->assertOk();
        $this->download($user)->assertStatus(429);
        $this->download($user)->assertStatus(429);
        $rows = collect($this->restrictions());
        $this->assertCount(2, $rows);
        $this->release($rows->firstWhere('kind', 'user_rate'))->assertOk();
        $this->download($user)->assertStatus(429);
        $this->release($rows->firstWhere('kind', 'ip_rate'))->assertOk();
        $this->download($user)->assertOk();
    }

    public function test_user_limit_survives_subscription_reset_and_record_failure_does_not_open_download(): void
    {
        $this->configure(['user_rate_limit_enabled' => true, 'user_rate_limit_max_requests' => 1]);
        $user = $this->user();
        $this->download($user)->assertOk();
        $user->update(['token' => Str::random(32)]);
        $this->failNextQueryMatching('/insert into ["`]?v2_subscription_restrictions/i');
        $this->download($user)->assertStatus(503);
        $this->download($user)->assertStatus(429);
        $this->assertSame(1, \App\Models\SubscriptionRestriction::count());
    }
}
