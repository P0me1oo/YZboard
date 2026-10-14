<?php

namespace Tests\Feature\TelegramBot;

use App\Jobs\OrderHandleJob;
use App\Jobs\SendTelegramBusinessNotification;
use App\Jobs\SendTelegramJob;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\TelegramBotBinding;
use App\Models\TelegramBotConfig;
use App\Models\TelegramBotUpdate;
use App\Models\TelegramBusinessNotification;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\OrderService;
use App\Services\TelegramBot\BusinessNotificationService;
use App\Services\TelegramBot\ConfigService;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TelegramBusinessNotificationTest extends TestCase
{
    use RefreshDatabase;

    private array $failures = [];
    private int $messageId = 100;
    private int $sequence = 1;
    private string $secret;
    private bool $invalidResponse = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
        Queue::fake();
        Bus::fake([OrderHandleJob::class]);
        $this->secret = Str::random(48);
        TelegramBotConfig::findOrFail(1)->update([
            'token' => '123456789:' . Str::random(35), 'webhook_secret' => $this->secret,
            'bot_id' => 123456789, 'bot_username' => 'test_bot', 'enabled' => true,
        ]);
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            $status = $this->failures[$request['chat_id']] ?? 200;
            return $status !== 200
                ? Http::response(['ok' => false, 'error_code' => $status, 'parameters' => ['retry_after' => 180]], $status)
                : Http::response(['ok' => true, 'result' => $this->invalidResponse ? true : [
                    'message_id' => ++$this->messageId, 'chat' => ['id' => $request['chat_id'], 'type' => 'private'],
                ]]);
        });
    }

    private function user(array $changes = []): User
    {
        return User::create(array_merge(['email' => Str::random(12) . '@example.test', 'password' => Str::random(32),
            'uuid' => (string) Str::uuid(), 'token' => Str::random(32), 'banned' => 0,
            'transfer_enable' => 1024 ** 3, 'is_admin' => 0, 'is_staff' => 0], $changes));
    }

    private function bind(User $user, int $telegramId = 900000001): TelegramBotBinding
    {
        return TelegramBotBinding::create(['user_id' => $user->id, 'telegram_id' => $telegramId]);
    }

    private function enable(array $changes = []): void
    {
        app(ConfigService::class)->saveBusinessNotifications(array_merge([
            'notify_ticket' => true, 'allow_ticket_reply' => true, 'notify_payment' => true,
        ], $changes));
    }

    private function ticket(?User $owner = null, string $body = '需要帮助'): Ticket
    {
        return app(TicketService::class)->createTicket(($owner ?? $this->user())->id, '测试工单', 1, $body);
    }

    private function sendAll(): void
    {
        foreach (TelegramBusinessNotification::where('status', 'pending')->pluck('id') as $id) {
            app(BusinessNotificationService::class)->send($id);
            $this->travel(2)->seconds();
        }
    }

    private function replyUpdate(TelegramBusinessNotification $notification, array $changes = []): array
    {
        return ['update_id' => $this->sequence++, 'message' => array_replace_recursive([
            'message_id' => 1000 + $this->sequence, 'date' => time(), 'text' => '已经处理，请重试。',
            'from' => ['id' => $notification->telegram_id, 'is_bot' => false],
            'chat' => ['id' => $notification->telegram_id, 'type' => 'private'],
            'reply_to_message' => ['message_id' => $notification->telegram_message_id,
                'from' => ['id' => $notification->bot_id, 'is_bot' => true], 'text' => '工单编号不会作为依据'],
        ], $changes)];
    }

    private function webhook(array $update)
    {
        return $this->postJson(ConfigService::WEBHOOK_PATH, $update, ['X-Telegram-Bot-Api-Secret-Token' => $this->secret]);
    }

    private function sentTicket(): array
    {
        $actor = $this->user(['is_staff' => 1]);
        $binding = $this->bind($actor);
        $this->enable();
        $ticket = $this->ticket();
        $this->sendAll();
        return [$actor, $binding, $ticket, TelegramBusinessNotification::firstOrFail()];
    }

    public function test_defaults_and_admin_only_independent_settings_preserve_config_and_bindings(): void
    {
        $admin = $this->user(['is_admin' => 1]);
        $binding = $this->bind($admin);
        $before = TelegramBotConfig::firstOrFail();
        foreach (['notify_ticket', 'allow_ticket_reply', 'notify_payment'] as $key) {
            $this->assertFalse(app(ConfigService::class)->view()[$key]);
        }
        $path = '/api/v2/' . hash('crc32b', config('app.key')) . '/telegram-bot/business-notifications';
        $body = ['notify_ticket' => true, 'allow_ticket_reply' => false, 'notify_payment' => false];
        $this->postJson($path, $body)->assertForbidden();
        Sanctum::actingAs($this->user(['is_staff' => 1]));
        $this->postJson($path, $body)->assertForbidden();
        Sanctum::actingAs($admin);
        $this->postJson($path, $body)->assertOk()->assertJsonPath('data.notify_ticket', true)
            ->assertJsonPath('data.allow_ticket_reply', false)->assertJsonPath('data.notify_payment', false)
            ->assertJsonPath('data.enabled', true)->assertJsonMissingPath('data.token');
        $this->postJson($path, ['notify_ticket' => true])->assertUnprocessable();
        $this->postJson($path, array_merge($body, ['notify_payment' => 'wrong']))->assertUnprocessable();
        $current = TelegramBotConfig::firstOrFail();
        foreach (['token', 'webhook_secret', 'webhook_url', 'remind_device', 'notify_admin_recipients'] as $key) {
            $this->assertSame($before->{$key}, $current->{$key});
        }
        $this->assertNotNull($binding->fresh());
        Http::assertNothingSent();
    }

    public function test_ticket_creation_and_user_replies_notify_only_newly_bound_authorized_staff_once(): void
    {
        $this->enable();
        $this->bind($this->user(['is_admin' => 1]));
        $this->bind($this->user(['is_staff' => 1]), 900000002);
        $this->bind($this->user(), 900000003);
        $this->bind($this->user(['is_staff' => 1, 'banned' => 1]), 900000004);
        $this->user(['is_admin' => 1, 'telegram_id' => 900000005]);
        $owner = $this->user();
        $ticket = $this->ticket($owner);
        $source = $ticket->messages()->first();
        app(BusinessNotificationService::class)->recordTicket($source);
        $this->assertDatabaseCount('v2_telegram_business_notifications', 2);
        $this->sendAll();
        $this->sendAll();
        Http::assertSentCount(2);
        app(TicketService::class)->reply($ticket, '补充信息', $owner->id);
        $this->assertDatabaseCount('v2_telegram_business_notifications', 4);
        $this->sendAll();
        Http::assertSentCount(4);
        app(TicketService::class)->reply($ticket, '管理员回复', $this->user(['is_admin' => 1])->id);
        $this->assertDatabaseCount('v2_telegram_business_notifications', 4);
    }

    public function test_rolled_back_ticket_events_are_not_delivered(): void
    {
        $this->enable();
        $this->bind($this->user(['is_admin' => 1]));
        $owner = $this->user();
        DB::beginTransaction();
        $this->ticket($owner);
        $this->assertDatabaseCount('v2_telegram_business_notifications', 1);
        DB::rollBack();
        $this->assertDatabaseCount('v2_telegram_business_notifications', 0);
        $this->assertDatabaseCount('v2_ticket_message', 0);
        $this->sendAll();
        Http::assertNothingSent();
    }

    public function test_repeated_payment_callbacks_create_one_notification_per_recipient(): void
    {
        $this->enable(['notify_ticket' => false]);
        $this->bind($this->user(['is_admin' => 1]));
        $plan = Plan::create(['name' => '测试套餐', 'transfer_enable' => 10, 'prices' => [Plan::PERIOD_MONTHLY => 10]]);
        $payment = Payment::create(['name' => '测试渠道', 'payment' => 'Epay', 'uuid' => Str::random(16), 'config' => []]);
        $order = Order::create(['user_id' => $this->user()->id, 'plan_id' => $plan->id, 'payment_id' => $payment->id,
            'period' => Plan::PERIOD_MONTHLY, 'trade_no' => 'test-' . Str::random(16), 'total_amount' => 1234,
            'type' => Order::TYPE_NEW_PURCHASE, 'status' => Order::STATUS_PENDING]);
        $stale = $order->fresh();
        $this->assertTrue((new OrderService($order))->paid('test-callback', true));
        $this->assertTrue((new OrderService($stale))->paid('test-repeat', true));
        $this->assertDatabaseCount('v2_telegram_business_notifications', 1);
        $this->sendAll();
        Http::assertSent(fn ($request) => str_contains($request['text'], '12.34 元') && str_contains($request['text'], $order->trade_no));
        $this->assertDatabaseHas('v2_telegram_business_notifications', ['kind' => 'payment', 'status' => 'sent']);
        $free = $order->replicate();
        $free->fill(['trade_no' => 'free-' . Str::random(16), 'total_amount' => 0, 'status' => 0, 'paid_at' => null])->save();
        $this->assertTrue((new OrderService($free))->paid('test-free', true));
        $manual = $order->replicate();
        $manual->fill(['trade_no' => 'manual-' . Str::random(16), 'status' => 0, 'paid_at' => null])->save();
        $this->assertTrue((new OrderService($manual))->paid('manual_operation'));
        $this->assertDatabaseCount('v2_telegram_business_notifications', 1);
    }

    public function test_recipient_failures_retry_independently_and_honor_telegram_backoff(): void
    {
        $this->enable();
        $this->bind($this->user(['is_admin' => 1]));
        $this->bind($this->user(['is_staff' => 1]), 900000002);
        $this->failures[900000002] = 429;
        $this->ticket();
        $this->sendAll();
        $failed = TelegramBusinessNotification::where('telegram_id', 900000002)->firstOrFail();
        $this->assertSame('pending', $failed->status);
        $this->assertSame(1, $failed->attempts);
        $this->assertGreaterThanOrEqual(now()->timestamp + 178, $failed->retry_at);
        $this->sendAll();
        Http::assertSentCount(2);
        $this->failures = [];
        $this->travel(181)->seconds();
        $this->sendAll();
        Http::assertSentCount(3);
        $this->assertSame(2, TelegramBusinessNotification::where('status', 'sent')->count());
    }

    public static function terminalFailures(): array
    {
        return [[400], [401], [403], [500]];
    }

    #[DataProvider('terminalFailures')]
    public function test_permanent_errors_and_exhausted_retries_stop(int $status): void
    {
        $this->enable();
        $this->bind($this->user(['is_admin' => 1]));
        $this->ticket();
        $this->failures[900000001] = $status;
        for ($i = 0; $i < 6; $i++) {
            $this->sendAll();
            $this->travel(3601)->seconds();
        }
        $row = TelegramBusinessNotification::firstOrFail();
        $this->assertSame('failed', $row->status);
        $this->assertSame($status === 500 ? 5 : 1, $row->attempts);
        Http::assertSentCount($row->attempts);
    }

    public function test_pause_and_scheduler_recovery_do_not_resend_completed_events(): void
    {
        $this->enable();
        $this->bind($this->user(['is_staff' => 1]));
        $this->ticket();
        TelegramBotConfig::firstOrFail()->update(['enabled' => false]);
        app(BusinessNotificationService::class)->schedule();
        Queue::assertNotPushed(SendTelegramBusinessNotification::class);
        $this->sendAll();
        Http::assertNothingSent();
        TelegramBotConfig::firstOrFail()->update(['enabled' => true]);
        app(BusinessNotificationService::class)->schedule();
        Queue::assertPushed(SendTelegramBusinessNotification::class, 1);
        $this->sendAll();
        app(BusinessNotificationService::class)->schedule();
        Queue::assertPushed(SendTelegramBusinessNotification::class, 1);
        $this->sendAll();
        Http::assertSentCount(1);
    }

    public static function invalidatedRecipients(): array
    {
        return [['unbind'], ['rebind'], ['role'], ['banned'], ['bot'], ['disabled'], ['closed'], ['deleted']];
    }

    #[DataProvider('invalidatedRecipients')]
    public function test_pending_events_recheck_binding_permissions_switches_and_sources(string $change): void
    {
        $this->enable();
        $actor = $this->user(['is_staff' => 1]);
        $binding = $this->bind($actor);
        $ticket = $this->ticket();
        match ($change) {
            'unbind' => $binding->delete(),
            'rebind' => (function () use ($binding, $actor) { $binding->delete(); $this->bind($actor); })(),
            'role' => $actor->update(['is_staff' => 0]),
            'banned' => $actor->update(['banned' => 1]),
            'bot' => TelegramBotConfig::firstOrFail()->update(['bot_id' => 223456789]),
            'disabled' => $this->enable(['notify_ticket' => false]),
            'closed' => $ticket->update(['status' => Ticket::STATUS_CLOSED]),
            'deleted' => $ticket->delete(),
        };
        $this->sendAll();
        $this->assertDatabaseHas('v2_telegram_business_notifications', ['status' => 'cancelled']);
        Http::assertNothingSent();
    }

    public function test_unconfirmed_telegram_response_remains_pending_and_unicode_text_fits(): void
    {
        $this->enable();
        $this->bind($this->user(['is_admin' => 1]));
        $this->ticket(null, str_repeat("\u{1F600}中文", 1500));
        $this->invalidResponse = true;
        $this->sendAll();
        $this->assertDatabaseHas('v2_telegram_business_notifications', ['status' => 'pending', 'attempts' => 1]);
        Http::assertSent(fn ($request) => strlen(mb_convert_encoding($request['text'], 'UTF-16LE', 'UTF-8')) <= 7800);
        $this->invalidResponse = false;
        $this->travel(61)->seconds();
        $this->sendAll();
        $this->assertDatabaseHas('v2_telegram_business_notifications', ['status' => 'sent']);
    }

    public function test_staff_reply_is_atomic_with_update_record_and_failed_acknowledgement_never_replies_twice(): void
    {
        [$actor, $binding, $ticket, $notification] = $this->sentTicket();
        $update = $this->replyUpdate($notification);
        $this->failures[$notification->telegram_id] = 500;
        $this->webhook($update)->assertStatus(503);
        $this->assertSame(2, $ticket->messages()->count());
        $this->assertSame((int) $actor->id, (int) $ticket->messages()->orderByDesc('id')->first()->user_id);
        $this->assertDatabaseHas('v2_telegram_bot_updates', ['update_id' => $update['update_id'], 'action' => 'ticket_replied', 'sent_at' => null]);
        $this->failures = [];
        $this->webhook($update)->assertOk();
        $this->webhook($update)->assertOk();
        $this->assertSame(2, $ticket->messages()->count());
        $this->assertNotNull(TelegramBotUpdate::where('update_id', $update['update_id'])->first()->sent_at);
        $this->assertSame(Ticket::REPLY_STATUS_REPLIED, (int) $ticket->fresh()->reply_status);
    }

    public function test_bind_command_in_ticket_reply_preserves_the_complete_reply_text(): void
    {
        [$actor, $binding, $ticket, $notification] = $this->sentTicket();
        $text = '/bind https://example.test/s/example';
        $update = $this->replyUpdate($notification, ['text' => $text]);
        $this->webhook($update)->assertOk();
        $this->webhook($update)->assertOk();
        $this->assertSame(2, $ticket->messages()->count());
        $this->assertSame($text, $ticket->messages()->orderByDesc('id')->first()->message);
        $this->assertSame(1, TelegramBotBinding::count());
    }

    public static function rejectedReplies(): array
    {
        return [['role'], ['banned'], ['unbind'], ['rebind'], ['closed'], ['disabled'], ['ordinary'],
            ['sender'], ['group'], ['foreign_bot'], ['forged_id'], ['forwarded'], ['old_text'], ['missing_source'], ['own_ticket']];
    }

    #[DataProvider('rejectedReplies')]
    public function test_unauthorized_or_forged_reply_cannot_modify_a_ticket(string $reason): void
    {
        [$actor, $binding, $ticket, $notification] = $this->sentTicket();
        $update = $this->replyUpdate($notification);
        switch ($reason) {
            case 'role': $actor->update(['is_staff' => 0]); break;
            case 'banned': $actor->update(['banned' => 1]); break;
            case 'unbind': $binding->delete(); break;
            case 'rebind': $binding->delete(); $this->bind($actor); break;
            case 'closed': $ticket->update(['status' => Ticket::STATUS_CLOSED]); break;
            case 'disabled': $this->enable(['allow_ticket_reply' => false]); break;
            case 'ordinary': $actor->update(['is_staff' => 0]); break;
            case 'sender': $update['message']['from']['id'] = 900000002; break;
            case 'group': $update['message']['chat'] = ['id' => -100000001, 'type' => 'supergroup']; break;
            case 'foreign_bot': $update['message']['reply_to_message']['from']['id'] = 223456789; break;
            case 'forged_id': $update['message']['reply_to_message']['message_id'] = 999999; break;
            case 'forwarded': $update['message']['reply_to_message']['forward_origin'] = ['type' => 'channel']; break;
            case 'old_text': $update['message']['date'] = time() - 8 * 86400; break;
            case 'missing_source': TicketMessage::where('id', $notification->event_id)->delete(); break;
            case 'own_ticket': $ticket->update(['user_id' => $actor->id]); break;
        }
        $before = $ticket->messages()->count();
        $this->webhook($update)->assertOk();
        $this->assertSame($before, $ticket->messages()->count());
    }

    public function test_reply_write_failure_rolls_back_update_and_can_be_retried(): void
    {
        [$actor, $binding, $ticket, $notification] = $this->sentTicket();
        $update = $this->replyUpdate($notification);
        $this->failNextQueryMatching('/insert into [`"]?v2_ticket_message/i');
        $this->webhook($update)->assertStatus(503);
        $this->assertSame(1, $ticket->messages()->count());
        $this->assertDatabaseCount('v2_telegram_bot_updates', 0);
        $this->webhook($update)->assertOk();
        $this->assertSame(2, $ticket->messages()->count());
    }

    public function test_replying_to_an_ordinary_bot_prompt_still_allows_binding_and_commands(): void
    {
        app(\App\Support\Setting::class)->save(['app_url' => 'https://panel.example.test', 'subscribe_url' => 'https://panel.example.test']);
        $user = $this->user();
        $message = ['message_id' => 1001, 'date' => time(), 'text' => 'https://panel.example.test/s/' . $user->token,
            'from' => ['id' => 900000001, 'is_bot' => false], 'chat' => ['id' => 900000001, 'type' => 'private'],
            'reply_to_message' => ['message_id' => 100, 'from' => ['id' => 123456789, 'is_bot' => true]]];
        $this->webhook(['update_id' => 1, 'message' => $message])->assertOk();
        $this->assertDatabaseHas('v2_telegram_bot_bindings', ['user_id' => $user->id, 'telegram_id' => 900000001]);
        $message['text'] = '/start';
        $this->webhook(['update_id' => 2, 'message' => $message])->assertOk();
        $this->assertDatabaseHas('v2_telegram_bot_updates', ['update_id' => 2, 'action' => 'menu']);
    }

    public function test_legacy_plugin_and_queued_jobs_yield_only_the_enabled_features(): void
    {
        require_once base_path('plugins-core/Telegram/Plugin.php');
        $plugin = new \Plugin\Telegram\Plugin('telegram');
        $plugin->boot();
        $this->enable();
        $plugin->sendTicketNotify($this->ticket());
        $plugin->sendPaymentNotify(new Order());
        Queue::assertNotPushed(SendTelegramJob::class);
        (new SendTelegramJob(900000001, '旧通知', 'ticket'))->handle();
        (new SendTelegramJob(900000001, "\u{1F4EE} *工单提醒* #1"))->handle();
        (new SendTelegramJob(900000001, "\u{1F4B0}成功收款10元"))->handle();
        Http::assertNothingSent();
        TelegramBotConfig::firstOrFail()->update(['enabled' => false]);
        $this->assertTrue(BusinessNotificationService::owns('ticket'));
        $this->enable(['notify_ticket' => false, 'allow_ticket_reply' => true, 'notify_payment' => false]);
        $this->assertFalse(BusinessNotificationService::owns('ticket'));
        $this->assertTrue(BusinessNotificationService::owns('ticket_reply'));
        $this->assertFalse(BusinessNotificationService::owns('payment'));
        (new SendTelegramJob(900000001, '其他通知'))->handle();
        Http::assertSentCount(1);
    }
}
