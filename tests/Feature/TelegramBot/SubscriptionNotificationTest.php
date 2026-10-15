<?php

namespace Tests\Feature\TelegramBot;

use App\Models\SubscriptionRestriction;
use App\Models\TelegramAdminNotification;
use App\Models\TelegramBotConfig;
use App\Models\User;
use App\Services\SubscriptionProtection;
use App\Services\SubscriptionRestrictionService;
use App\Services\TelegramBot\AdminNotificationService;
use App\Services\TelegramBot\ConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class SubscriptionNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('v2board', []);
        $this->freezeTime();
        Queue::fake();
        Http::preventStrayRequests();
        TelegramBotConfig::findOrFail(1)->update([
            'enabled' => true, 'token' => '123456789:' . Str::random(35),
            'notify_admin_recipients' => ['900000001', '900000002'], 'notify_admin_interval_minutes' => 5,
        ]);
        $this->configure();
    }

    private function configure(array $values = []): void
    {
        admin_setting([SubscriptionProtection::SETTING => array_replace(SubscriptionProtection::defaults(), [
            'notify_telegram' => true, 'brute_force_enabled' => true, 'brute_force_max_failures' => 1,
        ], $values)]);
    }

    private function hit(string $ip = '198.51.100.20'): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => $ip])->get('/api/v1/client/subscribe')->assertStatus(429);
    }

    private function fakeSuccess(): void
    {
        Http::fake(fn ($request) => Http::response(['ok' => true, 'result' => [
            'message_id' => 1, 'chat' => ['id' => $request['chat_id'], 'type' => 'private'],
        ]]));
    }

    public function test_only_new_limits_record_notifications_and_subscription_never_sends_http(): void
    {
        $this->hit();
        $this->hit();
        $this->assertSame(2, TelegramAdminNotification::count());
        $this->assertSame(2, TelegramAdminNotification::whereNull('user_id')->count());
        Http::assertNothingSent();
        $this->fakeSuccess();
        $service = app(AdminNotificationService::class);
        $service->schedule();
        Queue::assertPushed(\App\Jobs\SendTelegramAdminNotification::class, 2);
        foreach (TelegramAdminNotification::pluck('id') as $id) {
            $service->send($id);
            $service->send($id);
        }
        Http::assertSentCount(2);
        $this->assertSame(2, TelegramAdminNotification::whereNull('details')->count());
        Http::assertSent(fn ($request) => str_contains($request['text'], 'IP 探测封禁')
            && str_contains($request['text'], '198.51.100.20') && !str_contains($request['text'], '/api/'));
    }

    public function test_manual_release_keeps_cooldown_and_repeated_blocked_requests_never_rearm_it(): void
    {
        $this->hit();
        $this->fakeSuccess();
        foreach (TelegramAdminNotification::pluck('id') as $id) app(AdminNotificationService::class)->send($id);
        $row = SubscriptionRestriction::first();
        app(SubscriptionRestrictionService::class)->release($row->id, $row->event_id, false);
        $this->hit();
        $this->assertSame(0, TelegramAdminNotification::where('status', 'pending')->count());
        $this->travel(300)->seconds();
        $this->hit();
        $this->assertSame(0, TelegramAdminNotification::where('status', 'pending')->count());
        $row = SubscriptionRestriction::first();
        app(SubscriptionRestrictionService::class)->release($row->id, $row->event_id, false);
        $this->hit();
        $this->assertSame(2, TelegramAdminNotification::where('status', 'pending')->count());
    }

    public function test_disabled_switch_and_missing_recipients_do_not_record_and_pending_jobs_recheck_switch(): void
    {
        $this->configure(['notify_telegram' => false]);
        $this->hit();
        $this->assertSame(0, TelegramAdminNotification::count());
        $this->configure();
        $this->hit('198.51.100.21');
        $this->configure(['notify_telegram' => false]);
        foreach (TelegramAdminNotification::pluck('id') as $id) app(AdminNotificationService::class)->send($id);
        $this->assertSame(2, TelegramAdminNotification::where('status', 'cancelled')->whereNull('details')->count());
        Http::assertNothingSent();
        TelegramBotConfig::findOrFail(1)->update(['notify_admin_recipients' => []]);
        $this->configure();
        $this->hit('198.51.100.22');
        $this->assertSame(2, TelegramAdminNotification::count());
    }

    public function test_notification_registration_failure_does_not_change_limit_response(): void
    {
        $this->mock(AdminNotificationService::class)->shouldReceive('recordSubscription')->once()->andThrow(new \RuntimeException('测试登记失败'));
        $this->hit();
        $this->hit();
        $this->assertSame(1, SubscriptionRestriction::count());
        Http::assertNothingSent();
    }

    public function test_saving_disabled_switch_cancels_pending_events_even_if_immediately_reenabled(): void
    {
        $this->hit();
        $admin = User::create(['email' => 'admin@example.invalid', 'password' => Str::random(32), 'is_admin' => true,
            'uuid' => (string) Str::uuid(), 'token' => Str::random(32)]);
        $this->actingAs($admin, 'sanctum');
        $path = '/api/v2/' . hash('crc32b', config('app.key')) . '/config/save';
        $settings = SubscriptionProtection::settings();
        $settings['notify_telegram'] = false;
        $this->postJson($path, [SubscriptionProtection::SETTING => $settings])->assertOk();
        $settings['notify_telegram'] = true;
        $this->postJson($path, [SubscriptionProtection::SETTING => $settings])->assertOk();
        foreach (TelegramAdminNotification::pluck('id') as $id) app(AdminNotificationService::class)->send($id);
        $this->assertSame(2, TelegramAdminNotification::where('status', 'cancelled')->whereNull('details')->count());
        Http::assertNothingSent();
    }

    public function test_cleanup_keeps_current_restrictions_pending_notifications_and_cooldown(): void
    {
        $this->hit();
        $this->fakeSuccess();
        foreach (TelegramAdminNotification::pluck('id') as $id) app(AdminNotificationService::class)->send($id);
        app(SubscriptionRestrictionService::class)->prune();
        $this->assertSame(2, TelegramAdminNotification::count());
        $this->assertSame(1, SubscriptionRestriction::count());
        $this->travel(8)->days();
        $this->hit('198.51.100.21');
        app(SubscriptionRestrictionService::class)->prune();
        $this->assertSame(2, TelegramAdminNotification::where('status', 'pending')->count());
        $this->assertSame(2, TelegramAdminNotification::count());
        $this->assertSame(1, SubscriptionRestriction::count());
    }

    public function test_recipient_failures_retry_independently_and_removed_recipient_cannot_receive(): void
    {
        $this->hit();
        Http::fake(fn ($request) => $request['chat_id'] === 900000001
            ? Http::response(['ok' => false, 'error_code' => 500], 500)
            : Http::response(['ok' => true, 'result' => ['message_id' => 1, 'chat' => ['id' => $request['chat_id'], 'type' => 'private']]]));
        foreach (TelegramAdminNotification::pluck('id') as $id) app(AdminNotificationService::class)->send($id);
        $this->assertSame(1, TelegramAdminNotification::where('status', 'sent')->count());
        $this->assertSame(1, TelegramAdminNotification::where('status', 'pending')->where('attempts', 1)->count());
        $this->hit();
        app(ConfigService::class)->saveAdminNotifications(['notify_admin_device' => false, 'notify_admin_connection' => false,
            'notify_admin_interval_minutes' => 5, 'notify_admin_recipients' => ['900000002']]);
        $this->assertSame(1, TelegramAdminNotification::count());
    }

    public function test_ip_and_account_rate_limits_have_separate_notifications_and_no_subscription_secrets(): void
    {
        $user = User::create(['email' => 'notify@example.invalid', 'password' => Str::random(32),
            'uuid' => (string) Str::uuid(), 'token' => Str::random(32), 'transfer_enable' => 1024, 'banned' => false, 'expired_at' => null]);
        $this->configure(['brute_force_enabled' => false, 'rate_limit_enabled' => true, 'rate_limit_max_requests' => 2,
            'user_rate_limit_enabled' => true, 'user_rate_limit_max_requests' => 1]);
        $url = '/s/' . $user->token;
        $this->get($url, ['User-Agent' => 'Clash/1.0'])->assertOk();
        $this->get($url)->assertStatus(429);
        $this->get($url)->assertStatus(429);
        $this->assertSame(2, TelegramAdminNotification::where('kind', 'sub_user_rate')->count());
        $this->assertSame(2, TelegramAdminNotification::where('kind', 'sub_ip_rate')->count());
        $this->fakeSuccess();
        foreach (TelegramAdminNotification::pluck('id') as $id) {
            app(AdminNotificationService::class)->send($id);
            $this->travel(2)->seconds();
        }
        Http::assertSentCount(4);
        Http::assertNotSent(fn ($request) => str_contains($request['text'], $user->token) || str_contains($request['text'], $user->uuid));
    }
}
