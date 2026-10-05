<?php

namespace Tests\Feature\TelegramBot;

use App\Jobs\SendTelegramBotReminder;
use App\Models\Server;
use App\Models\TelegramBotBinding;
use App\Models\TelegramBotConfig;
use App\Models\TelegramBotReminder;
use App\Models\User;
use App\Services\ServerService;
use App\Services\TelegramBot\ConfigService;
use App\Services\TelegramBot\ReminderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TelegramBotReminderTest extends TestCase
{
    use RefreshDatabase;

    private int $responseStatus = 200;
    private bool $invalidResponse = false;
    private int $telegramId = 900000000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            if ($this->responseStatus !== 200) {
                return Http::response(['ok' => false, 'error_code' => $this->responseStatus], $this->responseStatus);
            }
            return Http::response(['ok' => true, 'result' => $this->invalidResponse ? true : [
                'message_id' => 1, 'chat' => ['id' => $request['chat_id'], 'type' => 'private'],
            ]]);
        });
        TelegramBotConfig::findOrFail(1)->update([
            'token' => '123456789:' . Str::random(35), 'enabled' => true,
        ]);
    }

    private function settings(array $changes = []): array
    {
        return array_merge([
            'remind_expiring' => true, 'remind_expired' => true,
            'remind_device' => true, 'remind_connection' => true,
            'remind_days' => 3, 'remind_interval_minutes' => 60,
        ], $changes);
    }

    private function enable(array $changes = []): void
    {
        app(ConfigService::class)->saveReminders($this->settings($changes));
    }

    private function user(?int $expires, bool $bound = true): User
    {
        $user = User::create([
            'email' => Str::random(12) . '@example.test', 'password' => Str::random(32),
            'uuid' => (string) Str::uuid(), 'token' => Str::random(32),
            'transfer_enable' => 1024 ** 3, 'expired_at' => $expires,
        ]);
        if ($bound) {
            TelegramBotBinding::create(['user_id' => $user->id, 'telegram_id' => ++$this->telegramId]);
        }
        return $user;
    }

    private function tick(): void
    {
        $service = app(ReminderService::class);
        $service->schedule();
        foreach (TelegramBotReminder::where('status', 'pending')->pluck('id') as $id) {
            $service->send($id);
        }
    }

    private function event(User $user, string $kind = 'conn'): array
    {
        return ['user_id' => $user->id, 'kind' => $kind, 'limit' => 10, 'observed' => 10, 'count' => 3];
    }

    public function test_defaults_are_off_and_admin_can_save_each_switch_without_stopping_bot(): void
    {
        $view = app(ConfigService::class)->view();
        foreach (['expiring', 'expired', 'device', 'connection'] as $kind) {
            $this->assertFalse($view['remind_' . $kind]);
        }
        $admin = $this->user(null, false);
        $admin->update(['is_admin' => true]);
        Sanctum::actingAs($admin);
        $path = '/api/v2/' . hash('crc32b', config('app.key')) . '/telegram-bot/reminders';
        $this->postJson($path, $this->settings(['remind_connection' => false, 'remind_days' => 7]))
            ->assertOk()->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.remind_connection', false)->assertJsonPath('data.remind_days', 7);
        $this->postJson($path, $this->settings(['remind_days' => 0]))->assertUnprocessable();
        $this->postJson($path, $this->settings(['remind_interval_minutes' => 1.5]))->assertUnprocessable();
        $this->postJson($path, $this->settings(['remind_interval_minutes' => 10081]))->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_ordinary_user_cannot_change_settings(): void
    {
        Sanctum::actingAs($this->user(null, false));
        $this->postJson('/api/v2/' . hash('crc32b', config('app.key')) . '/telegram-bot/reminders', $this->settings())
            ->assertForbidden();
    }

    public function test_expiring_boundary_and_durable_deduplication(): void
    {
        $this->enable();
        $this->user(now()->timestamp + 3 * 86400);
        $this->user(now()->timestamp + 3 * 86400 + 1);
        $this->user(null);
        $this->user(0);
        $this->user(now()->timestamp + 100, false);
        $this->tick();
        Http::assertSentCount(1);
        Queue::assertPushed(SendTelegramBotReminder::class);
        Cache::flush();
        $this->tick();
        app(ReminderService::class)->send(TelegramBotReminder::first()->id);
        Http::assertSentCount(1);
        $this->travel(1)->seconds();
        $this->tick();
        Http::assertSentCount(2);
    }

    public function test_existing_expired_accounts_are_not_backfilled_but_new_expiration_is_sent_once(): void
    {
        $this->user(now()->timestamp - 10);
        $this->user(now()->timestamp);
        $this->user(now()->timestamp + 60);
        $this->enable(['remind_expiring' => false]);
        $this->tick();
        Http::assertNothingSent();
        $this->travel(60)->seconds();
        $this->tick();
        $this->tick();
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request['text'], '您的订阅已过期'));
    }

    public function test_saving_unrelated_settings_keeps_expired_start_time(): void
    {
        $this->enable(['remind_expiring' => false]);
        $start = TelegramBotConfig::find(1)->remind_expired_since;
        $this->user(now()->timestamp + 30);
        $this->travel(60)->seconds();
        $this->enable(['remind_expiring' => false, 'remind_interval_minutes' => 120]);
        $this->assertSame($start, TelegramBotConfig::find(1)->remind_expired_since);
        $this->tick();
        Http::assertSentCount(1);
    }

    public function test_binding_after_expiration_does_not_receive_old_expired_notice(): void
    {
        $this->enable(['remind_expiring' => false]);
        $this->travel(60)->seconds();
        $this->user(now()->timestamp - 30);
        $this->tick();
        Http::assertNothingSent();
    }

    public function test_renewal_invalidates_queued_notice_and_new_period_gets_its_own_notice(): void
    {
        $this->enable();
        $user = $this->user(now()->timestamp + 60);
        app(ReminderService::class)->schedule();
        $user->update(['expired_at' => now()->timestamp + 10 * 86400]);
        $this->tick();
        Http::assertNothingSent();
        $this->travel(8)->days();
        $this->tick();
        Http::assertSentCount(1);
        $this->assertSame(2, TelegramBotReminder::count());
    }

    public function test_expiring_notice_is_not_sent_after_expiration(): void
    {
        $this->enable();
        $this->user(now()->timestamp + 60);
        app(ReminderService::class)->schedule();
        $this->travel(60)->seconds();
        $this->tick();
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request['text'], '订阅过期提醒'));
    }

    public function test_unbinding_cascades_records_and_old_jobs_do_not_reach_new_binding(): void
    {
        $this->enable();
        $user = $this->user(now()->timestamp + 60);
        app(ReminderService::class)->schedule();
        $id = TelegramBotReminder::first()->id;
        TelegramBotBinding::where('user_id', $user->id)->delete();
        TelegramBotBinding::create(['user_id' => $user->id, 'telegram_id' => ++$this->telegramId]);
        app(ReminderService::class)->send($id);
        $this->assertSame(0, TelegramBotReminder::count());
        Http::assertNothingSent();
    }

    public function test_bot_disable_and_individual_switch_prevent_pending_send(): void
    {
        $this->enable();
        $this->user(now()->timestamp + 60);
        app(ReminderService::class)->schedule();
        $this->enable(['remind_expiring' => false]);
        $this->tick();
        Http::assertNothingSent();
        $user = $this->user(now()->timestamp + 86400);
        app(ReminderService::class)->recordLimits([$this->event($user)]);
        TelegramBotConfig::find(1)->update(['enabled' => false]);
        foreach (TelegramBotReminder::pluck('id') as $id) app(ReminderService::class)->send($id);
        Http::assertNothingSent();
    }

    public function test_node_events_merge_connection_kinds_and_share_cooldown_across_nodes(): void
    {
        $this->enable();
        $user = $this->user(now()->timestamp + 10 * 86400);
        $node = new Server(['name' => '测试节点', 'type' => 'vmess']);
        $node->id = 1;
        ServerService::processLimitEvents($node, [$this->event($user), $this->event($user, 'rate'), $this->event($user, 'device')]);
        $this->tick();
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => str_contains($request['text'], '同时连接数上限')
            && str_contains($request['text'], '每秒新建连接数上限'));
        $node->id = 2;
        $this->travel(3599)->seconds();
        Cache::flush();
        ServerService::processLimitEvents($node, [$this->event($user, 'rate'), $this->event($user, 'device')]);
        $this->tick();
        Http::assertSentCount(2);
        $this->travel(1)->seconds();
        ServerService::processLimitEvents($node, [$this->event($user, 'rate'), $this->event($user, 'device')]);
        $this->tick();
        Http::assertSentCount(4);
        $this->assertSame(2, TelegramBotReminder::count());
    }

    public function test_limit_switches_and_configurable_interval(): void
    {
        $this->enable(['remind_connection' => false, 'remind_interval_minutes' => 2]);
        $user = $this->user(null);
        $service = app(ReminderService::class);
        $events = [$this->event($user), $this->event($user, 'rate'), $this->event($user, 'device')];
        $service->recordLimits($events);
        $this->tick();
        Http::assertSentCount(1);
        $this->travel(119)->seconds();
        $service->recordLimits($events);
        $this->tick();
        Http::assertSentCount(1);
        $this->travel(1)->seconds();
        $service->recordLimits($events);
        $this->tick();
        Http::assertSentCount(2);
    }

    public function test_unbound_zero_and_expired_limit_events_do_not_send(): void
    {
        $this->enable(['remind_expiring' => false]);
        $service = app(ReminderService::class);
        $events = [
            $this->event($this->user(null, false)),
            $this->event($this->user(now()->timestamp - 10)),
            array_merge($this->event($this->user(null)), ['count' => 0]),
            array_merge($this->event($this->user(null)), ['limit' => 0]),
        ];
        $service->recordLimits($events);
        $this->tick();
        Http::assertNothingSent();
    }

    public function test_failed_send_retries_after_backoff_and_does_not_consume_success(): void
    {
        $this->enable();
        $this->user(now()->timestamp + 86400);
        $this->responseStatus = 500;
        $this->tick();
        $this->assertNull(TelegramBotReminder::first()->sent_at);
        $this->tick();
        Http::assertSentCount(1);
        $this->responseStatus = 200;
        $this->travel(60)->seconds();
        $this->tick();
        Http::assertSentCount(2);
        $this->assertSame('sent', TelegramBotReminder::first()->status);
        $this->tick();
        Http::assertSentCount(2);
    }

    public function test_invalid_telegram_acknowledgment_is_not_success(): void
    {
        $this->enable();
        $this->user(now()->timestamp + 86400);
        $this->invalidResponse = true;
        $this->tick();
        $this->assertSame('pending', TelegramBotReminder::first()->status);
        $this->assertNull(TelegramBotReminder::first()->sent_at);
    }

    public function test_permanent_failure_does_not_retry_on_each_scan(): void
    {
        $this->enable();
        $this->user(now()->timestamp + 86400);
        $this->responseStatus = 403;
        $this->tick();
        $this->travel(60)->minutes();
        $this->tick();
        Http::assertSentCount(1);
        $this->assertSame('failed', TelegramBotReminder::first()->status);
    }

    public function test_stale_limit_event_is_discarded_and_new_event_can_send(): void
    {
        $this->enable(['remind_expiring' => false]);
        $user = $this->user(null);
        $service = app(ReminderService::class);
        $service->recordLimits([$this->event($user)]);
        $this->travel(61)->minutes();
        $this->tick();
        Http::assertNothingSent();
        $service->recordLimits([$this->event($user, 'rate')]);
        $this->tick();
        Http::assertSentCount(1);
    }

    public function test_shortening_notice_window_defers_unsent_notice_until_new_window(): void
    {
        $this->enable();
        $this->user(now()->timestamp + 2 * 86400);
        app(ReminderService::class)->schedule();
        $this->enable(['remind_days' => 1]);
        $this->tick();
        Http::assertNothingSent();
        $this->travel(1)->days();
        $this->tick();
        Http::assertSentCount(1);
        $this->tick();
        Http::assertSentCount(1);
    }

    public function test_reenabled_expired_reminder_skips_disabled_period(): void
    {
        $this->enable(['remind_expiring' => false]);
        $this->user(now()->timestamp + 60);
        $this->enable(['remind_expiring' => false, 'remind_expired' => false]);
        $this->travel(61)->seconds();
        $this->enable(['remind_expiring' => false]);
        $this->tick();
        Http::assertNothingSent();
    }

    public function test_command_dispatches_queue_job_and_job_sends_from_current_binding(): void
    {
        $this->enable();
        $this->user(now()->timestamp + 86400);
        $this->artisan('telegram-bot:send-reminders')->assertSuccessful();
        $reminder = TelegramBotReminder::firstOrFail();
        Queue::assertPushed(SendTelegramBotReminder::class, fn ($job) => $job->reminderId === $reminder->id
            && $job->queue === 'send_telegram');
        (new SendTelegramBotReminder($reminder->id))->handle(app(ReminderService::class));
        Http::assertSentCount(1);
    }

    public function test_transient_failures_stop_after_five_attempts(): void
    {
        $this->enable();
        $this->user(now()->timestamp + 86400);
        $this->responseStatus = 500;
        for ($i = 0; $i < 6; $i++) {
            $this->tick();
            $this->travel(30)->minutes();
        }
        Http::assertSentCount(5);
        $this->assertSame('failed', TelegramBotReminder::first()->status);
    }

    public function test_one_minute_interval_survives_next_scheduler_tick(): void
    {
        $this->enable(['remind_interval_minutes' => 1]);
        $user = $this->user(null);
        app(ReminderService::class)->recordLimits([$this->event($user)]);
        $this->travel(61)->seconds();
        $this->tick();
        Http::assertSentCount(1);
    }
}
