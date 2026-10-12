<?php

namespace Tests\Feature\TelegramBot;

use App\Jobs\SendTelegramAdminNotification;
use App\Models\Server;
use App\Models\TelegramAdminNotification;
use App\Models\TelegramBotConfig;
use App\Models\User;
use App\Services\DeviceIpLocationService;
use App\Services\ServerService;
use App\Services\TelegramBot\AdminNotificationService;
use App\Services\TelegramBot\ConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TelegramAdminNotificationTest extends TestCase
{
    use RefreshDatabase;

    private array $failures = [];
    private bool $invalidResponse = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/getMe')) {
                return Http::response(['ok' => true, 'result' => ['id' => 123456789, 'is_bot' => true, 'username' => 'test_bot']]);
            }
            if (str_ends_with($request->url(), '/getWebhookInfo')) {
                return Http::response(['ok' => true, 'result' => ['url' => '', 'pending_update_count' => 0]]);
            }
            $status = $this->failures[$request['chat_id']] ?? 200;
            return $status !== 200
                ? Http::response(['ok' => false, 'error_code' => $status], $status)
                : Http::response(['ok' => true, 'result' => $this->invalidResponse ? true : [
                    'message_id' => 1, 'chat' => ['id' => $request['chat_id'], 'type' => 'private'],
                ]]);
        });
        $this->mock(DeviceIpLocationService::class)->shouldReceive('lookupMany')->andReturn([]);
        TelegramBotConfig::findOrFail(1)->update(['token' => '123456789:' . Str::random(35), 'enabled' => true]);
    }

    private function user(): User
    {
        return User::create([
            'email' => Str::random(12) . '@example.test', 'password' => Str::random(32),
            'uuid' => (string) Str::uuid(), 'token' => Str::random(32), 'transfer_enable' => 1024 ** 3,
        ]);
    }

    private function node(): Server
    {
        return Server::create(['name' => '告警测试节点', 'type' => 'vmess', 'host' => 'node.example.test',
            'port' => 443, 'server_port' => 443, 'rate' => 1, 'group_ids' => []]);
    }

    private function settings(array $changes = []): array
    {
        return array_merge(['notify_admin_device' => true, 'notify_admin_connection' => true,
            'notify_admin_interval_minutes' => 60, 'notify_admin_recipients' => ['900000001', '900000002']], $changes);
    }

    private function enable(array $changes = []): void
    {
        app(ConfigService::class)->saveAdminNotifications($this->settings($changes));
    }

    private function event(User $user, string $kind = 'conn', array $changes = []): array
    {
        return array_merge(['user_id' => $user->id, 'kind' => $kind, 'limit' => 10, 'observed' => 10, 'count' => 1], $changes);
    }

    private function tick(): void
    {
        $service = app(AdminNotificationService::class);
        $service->schedule();
        foreach (TelegramAdminNotification::where('status', 'pending')->pluck('id') as $id) {
            $service->send($id);
            $this->travel(2)->seconds();
        }
    }

    private function path(): string
    {
        return '/api/v2/' . hash('crc32b', config('app.key')) . '/telegram-bot/admin-notifications';
    }

    public function test_defaults_are_disabled_and_existing_user_reminders_are_unchanged(): void
    {
        $config = app(ConfigService::class)->view();
        $this->assertFalse($config['notify_admin_device']);
        $this->assertFalse($config['notify_admin_connection']);
        $this->assertSame([], $config['notify_admin_recipients']);
        $this->assertSame(60, $config['notify_admin_interval_minutes']);
        $this->assertFalse($config['remind_device']);
        ServerService::processLimitEvents($this->node(), [$this->event($this->user())]);
        $this->assertDatabaseCount('v2_telegram_admin_notifications', 0);
    }

    public function test_admin_can_save_without_stopping_bot_and_input_boundaries_are_enforced(): void
    {
        $admin = $this->user();
        $admin->update(['is_admin' => true]);
        Sanctum::actingAs($admin);
        $this->postJson($this->path(), $this->settings(['notify_admin_recipients' => [900000001, '4503599627370495']]))
            ->assertOk()->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.notify_admin_recipients', ['900000001', '4503599627370495'])
            ->assertJsonPath('data.remind_device', false)->assertJsonMissingPath('data.token');
        foreach ([[], ['@admin'], ['-100000001'], ['01'], ['1e9'], ['1.5'], ['4503599627370496'],
            ['900000001', '900000001'], array_map('strval', range(1, 21))] as $recipients) {
            $this->postJson($this->path(), $this->settings(['notify_admin_recipients' => $recipients]))->assertUnprocessable();
        }
        foreach ([0, 1.5, 10081] as $interval) {
            $this->postJson($this->path(), $this->settings(['notify_admin_interval_minutes' => $interval]))->assertUnprocessable();
        }
        $this->postJson($this->path(), $this->settings(['notify_admin_device' => false,
            'notify_admin_connection' => false, 'notify_admin_recipients' => []]))->assertOk();
        Http::assertNothingSent();
    }

    public function test_ordinary_users_cannot_save_or_read_admin_recipients(): void
    {
        Sanctum::actingAs($this->user());
        $this->postJson($this->path(), $this->settings())->assertForbidden();
        $this->getJson(str_replace('admin-notifications', 'config', $this->path()))->assertForbidden();
    }

    public function test_unbound_user_alerts_reach_all_recipients_and_connection_types_are_merged(): void
    {
        $this->enable();
        $user = $this->user();
        $node = $this->node();
        ServerService::processLimitEvents($node, [$this->event($user, 'device'), $this->event($user), $this->event($user, 'rate')]);
        $this->assertDatabaseCount('v2_telegram_admin_notifications', 4);
        $this->assertDatabaseCount('v2_telegram_bot_bindings', 0);
        $this->tick();
        Queue::assertPushed(SendTelegramAdminNotification::class, 4);
        Http::assertSentCount(4);
        Http::assertSent(fn ($request) => $request['chat_id'] === 900000001 && str_contains($request['text'], $user->email)
            && str_contains($request['text'], $node->name) && str_contains($request['text'], '用户设备数限制告警'));
        Http::assertSent(fn ($request) => $request['chat_id'] === 900000002
            && str_contains($request['text'], '同时连接数上限：10') && str_contains($request['text'], '每秒新建连接数上限：10')
            && !str_contains($request['text'], '您的订阅') && !str_contains($request['text'], '请向管理员反馈'));
        $this->assertSame(0, TelegramAdminNotification::whereNotNull('details')->count());
    }

    public function test_repeated_reports_jobs_and_cache_reset_do_not_repeat_sent_alerts(): void
    {
        $this->enable();
        $user = $this->user();
        $node = $this->node();
        ServerService::processLimitEvents($node, [$this->event($user)]);
        ServerService::processLimitEvents($node, [$this->event($user)]);
        $this->tick();
        Cache::flush();
        ServerService::processLimitEvents($this->node(), [$this->event($user)]);
        foreach (TelegramAdminNotification::pluck('id') as $id) app(AdminNotificationService::class)->send($id);
        $this->tick();
        Http::assertSentCount(2);
        $this->assertDatabaseCount('v2_telegram_admin_notifications', 2);
    }

    public function test_cooldown_uses_success_time_and_requires_a_new_event_after_it_ends(): void
    {
        $this->enable(['notify_admin_recipients' => ['900000001']]);
        $user = $this->user();
        $node = $this->node();
        ServerService::processLimitEvents($node, [$this->event($user)]);
        app(AdminNotificationService::class)->send(TelegramAdminNotification::first()->id);
        $this->travel(3599)->seconds();
        ServerService::processLimitEvents($this->node(), [$this->event($user)]);
        $this->tick();
        Http::assertSentCount(1);
        $this->travel(1)->seconds();
        ServerService::processLimitEvents($node, [$this->event($user)]);
        $this->tick();
        Http::assertSentCount(2);
        $this->travel(3600)->seconds();
        $this->tick();
        Http::assertSentCount(2);
    }

    public function test_failed_recipient_retries_without_resending_to_successful_recipient(): void
    {
        $this->enable();
        $this->failures[900000002] = 500;
        ServerService::processLimitEvents($this->node(), [$this->event($this->user())]);
        $this->tick();
        $this->assertSame('sent', TelegramAdminNotification::where('telegram_id', 900000001)->first()->status);
        $failed = TelegramAdminNotification::where('telegram_id', 900000002)->first();
        $this->assertSame('pending', $failed->status);
        $this->assertSame(1, $failed->attempts);
        unset($this->failures[900000002]);
        $this->travelTo(now()->setTimestamp($failed->retry_at));
        $this->tick();
        Http::assertSentCount(3);
        $this->assertSame(2, TelegramAdminNotification::where('status', 'sent')->count());
        $this->assertSame(0, TelegramAdminNotification::whereNotNull('details')->count());
    }

    public function test_blocked_recipient_stops_retrying_and_does_not_block_other_recipients(): void
    {
        $this->enable();
        $this->failures[900000001] = 403;
        ServerService::processLimitEvents($this->node(), [$this->event($this->user())]);
        $this->tick();
        $failed = TelegramAdminNotification::where('telegram_id', 900000001)->first();
        $this->assertSame('failed', $failed->status);
        $this->assertNull($failed->details);
        $this->assertSame('sent', TelegramAdminNotification::where('telegram_id', 900000002)->first()->status);
        $this->tick();
        Http::assertSentCount(2);
    }

    public function test_removed_recipient_and_disabled_kind_cancel_queued_alerts(): void
    {
        $this->enable();
        $user = $this->user();
        ServerService::processLimitEvents($this->node(), [$this->event($user), $this->event($user, 'device')]);
        $ids = TelegramAdminNotification::pluck('id');
        $this->enable(['notify_admin_recipients' => ['900000002'], 'notify_admin_device' => false]);
        foreach ($ids as $id) app(AdminNotificationService::class)->send($id);
        Http::assertSentCount(1);
        $this->assertSame(0, TelegramAdminNotification::where('telegram_id', 900000001)->count());
        $this->assertSame('cancelled', TelegramAdminNotification::where('kind', 'device')->first()->status);
    }

    public function test_stop_and_restart_does_not_backfill_old_events(): void
    {
        $this->enable();
        $user = $this->user();
        ServerService::processLimitEvents($this->node(), [$this->event($user)]);
        app(ConfigService::class)->disable();
        $this->assertSame(2, TelegramAdminNotification::where('status', 'cancelled')->count());
        ServerService::processLimitEvents($this->node(), [$this->event($user, 'device')]);
        TelegramBotConfig::find(1)->update(['enabled' => true]);
        $this->tick();
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/sendMessage'));
    }

    public function test_expired_queued_snapshots_are_cleared_without_sending(): void
    {
        $this->enable(['notify_admin_interval_minutes' => 1]);
        ServerService::processLimitEvents($this->node(), [$this->event($this->user())]);
        $this->travel(300)->seconds();
        $this->tick();
        Http::assertNothingSent();
        $this->assertSame(2, TelegramAdminNotification::where('status', 'cancelled')->whereNull('details')->count());
    }

    public function test_same_chat_rate_limit_defers_without_consuming_attempts(): void
    {
        $this->enable(['notify_admin_recipients' => ['900000001']]);
        $user = $this->user();
        ServerService::processLimitEvents($this->node(), [$this->event($user), $this->event($user, 'device')]);
        $ids = TelegramAdminNotification::pluck('id');
        foreach ($ids as $id) app(AdminNotificationService::class)->send($id);
        Http::assertSentCount(1);
        $pending = TelegramAdminNotification::where('status', 'pending')->first();
        $this->assertSame(0, $pending->attempts);
        $this->travel(2)->seconds();
        app(AdminNotificationService::class)->send($pending->id);
        Http::assertSentCount(2);
    }

    public function test_invalid_delivery_confirmation_is_not_marked_sent(): void
    {
        $this->enable(['notify_admin_recipients' => ['900000001']]);
        $this->invalidResponse = true;
        ServerService::processLimitEvents($this->node(), [$this->event($this->user())]);
        $this->tick();
        $notification = TelegramAdminNotification::first();
        $this->assertSame('pending', $notification->status);
        $this->assertNull($notification->sent_at);
        $this->assertSame(1, $notification->attempts);
    }

    public function test_deleting_user_removes_notifications_and_old_jobs_are_harmless(): void
    {
        $this->enable();
        $user = $this->user();
        ServerService::processLimitEvents($this->node(), [$this->event($user)]);
        $ids = TelegramAdminNotification::pluck('id');
        $user->delete();
        foreach ($ids as $id) app(AdminNotificationService::class)->send($id);
        $this->assertDatabaseCount('v2_telegram_admin_notifications', 0);
        Http::assertNothingSent();
    }

    public function test_zero_counts_unlimited_unknown_users_and_stale_snapshots_are_ignored(): void
    {
        $this->enable();
        $user = $this->user();
        ServerService::processLimitEvents($this->node(), [
            $this->event($user, 'conn', ['count' => 0]), $this->event($user, 'device', ['limit' => 0]),
            $this->event($user, 'conn', ['user_id' => 999999]),
            $this->event($user, 'conn', ['snapshot' => ['occurred_at' => now()->timestamp - 3600,
                'source_ip' => '192.0.2.1', 'limit' => 10, 'observed' => 10]]),
        ]);
        $this->assertDatabaseCount('v2_telegram_admin_notifications', 0);
        Http::assertNothingSent();
    }

    public function test_snapshots_keep_event_time_and_source_and_queue_is_redispatched_after_restart(): void
    {
        $this->enable(['notify_admin_recipients' => ['900000001']]);
        ServerService::processLimitEvents($this->node(), [$this->event($this->user(), 'device', [
            'snapshot' => ['occurred_at' => now()->timestamp - 5, 'source_ip' => '192.0.2.8',
                'limit' => 3, 'observed' => 3, 'active_known' => true, 'active_ips' => ['192.0.2.1'], 'active_total' => 3],
        ])]);
        app(AdminNotificationService::class)->schedule();
        Cache::flush();
        $this->artisan('telegram-bot:send-reminders')->assertSuccessful();
        Queue::assertPushed(SendTelegramAdminNotification::class, 2);
        app(AdminNotificationService::class)->send(TelegramAdminNotification::first()->id);
        Http::assertSent(fn ($request) => str_contains($request['text'], '发生时间：')
            && str_contains($request['text'], '已占用设备名额：3') && str_contains($request['text'], '192.0.2.8')
            && str_contains($request['text'], '另有 2 个 IP 未展开') && mb_strlen($request['text']) <= 4096);
    }
}
