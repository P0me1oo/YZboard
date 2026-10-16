<?php

namespace Tests\Feature\TelegramBot;

use App\Jobs\SendTelegramBusinessNotification;
use App\Jobs\SendTelegramJob;
use App\Models\Server;
use App\Models\TelegramBotBinding;
use App\Models\TelegramBotConfig;
use App\Models\TelegramBotUpdate;
use App\Models\TelegramBusinessNotification;
use App\Models\User;
use App\Services\TelegramBot\BusinessNotificationService;
use App\Services\TelegramBot\ConfigService;
use App\Services\TelegramBot\NodeOfflineService;
use App\Utils\CacheKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TelegramGroupNodeTest extends TestCase
{
    use RefreshDatabase;

    private string $secret;
    private int $failure = 0;
    private bool $invalidResult = false;
    private string $remoteUrl = 'https://panel.example.test/api/v1/guest/telegram-bot/webhook';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
        Queue::fake();
        $this->secret = Str::random(48);
        TelegramBotConfig::findOrFail(1)->update([
            'token' => '123456789:' . Str::random(35), 'webhook_secret' => $this->secret,
            'bot_id' => 123456789, 'enabled' => true, 'webhook_registered' => true, 'webhook_url' => $this->remoteUrl,
        ]);
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            if ($this->failure) return Http::response(['ok' => false, 'error_code' => $this->failure,
                'parameters' => ['retry_after' => 180]], $this->failure);
            $method = basename(parse_url($request->url(), PHP_URL_PATH));
            $result = match ($method) {
                'getMe' => ['id' => 123456789, 'is_bot' => true, 'username' => 'test_bot'],
                'getWebhookInfo' => ['url' => $this->remoteUrl],
                'sendMessage' => ['message_id' => 100, 'chat' => ['id' => $request['chat_id'], 'type' => 'private']],
                default => true,
            };
            return Http::response(['ok' => true, 'result' => $this->invalidResult ? false : $result]);
        });
    }

    public function test_group_and_offline_forms_save_only_their_own_fields(): void
    {
        Sanctum::actingAs($this->user(['is_admin' => 1]));
        $path = '/api/v2/' . hash('crc32b', config('app.key')) . '/telegram-bot/group-node-settings';
        $this->postJson($path, ['notify_node_offline' => true, 'node_offline_minutes' => 45])->assertOk()
            ->assertJsonPath('data.auto_approve_join', false)->assertJsonPath('data.notify_node_offline', true);
        $this->postJson($path, ['group_link' => 'https://t.me/example', 'auto_approve_join' => true,
            'join_group_ids' => ['-1001234567890']])->assertOk()->assertJsonPath('data.node_offline_minutes', 45);
        $this->postJson($path, ['notify_node_offline' => false, 'node_offline_minutes' => 20])->assertOk()
            ->assertJsonPath('data.auto_approve_join', true)->assertJsonPath('data.group_link', 'https://t.me/example')
            ->assertJsonPath('data.join_group_ids', ['-1001234567890'])->assertJsonPath('data.enabled', true);
        foreach ([[], ['auto_approve_join' => false], ['notify_node_offline' => true],
            ['notify_node_offline' => true, 'node_offline_minutes' => 0],
            ['auto_approve_join' => true, 'join_group_ids' => []]] as $invalid) {
            $this->postJson($path, $invalid)->assertUnprocessable();
        }
    }

    private function user(array $changes = []): User
    {
        return User::create(array_merge(['email' => Str::random(12) . '@example.test', 'password' => Str::random(32),
            'uuid' => (string) Str::uuid(), 'token' => Str::random(32), 'banned' => 0, 'is_admin' => 0, 'is_staff' => 0,
            'transfer_enable' => 1024 ** 3, 'expired_at' => null], $changes));
    }

    private function bind(User $user, int $id = 900000001): TelegramBotBinding
    {
        return TelegramBotBinding::create(['user_id' => $user->id, 'telegram_id' => $id]);
    }

    private function settings(array $changes = []): array
    {
        return array_merge(['auto_approve_join' => false, 'join_group_ids' => [],
            'notify_node_offline' => true, 'node_offline_minutes' => 30], $changes);
    }

    private function configure(array $changes = []): void
    {
        TelegramBotConfig::findOrFail(1)->update($this->settings($changes));
    }

    private function join(array $changes = []): array
    {
        return array_replace_recursive(['update_id' => 1, 'chat_join_request' => [
            'chat' => ['id' => -1001234567890, 'type' => 'supergroup'],
            'from' => ['id' => 900000001, 'is_bot' => false], 'date' => now()->timestamp,
        ]], $changes);
    }

    private function webhook(array $update)
    {
        return $this->postJson(ConfigService::WEBHOOK_PATH, $update, ['X-Telegram-Bot-Api-Secret-Token' => $this->secret]);
    }

    private function node(array $changes = []): Server
    {
        return Server::create(array_merge(['name' => '测试节点', 'type' => 'vless', 'host' => 'node.example.test',
            'port' => 443, 'server_port' => 443, 'rate' => 1, 'enabled' => true], $changes));
    }

    private function scan(): void
    {
        app(NodeOfflineService::class)->schedule();
        app(BusinessNotificationService::class)->schedule();
    }

    private function heartbeat(Server $node): void
    {
        // 用当前测试时间模拟 Node 上报；生产 touchNode 使用系统 time()，不受时间旅行影响。
        Cache::put(CacheKey::get('SERVER_' . strtoupper($node->type) . '_LAST_CHECK_AT', $node->id), now()->timestamp, 3600);
    }

    private function pending(): TelegramBusinessNotification
    {
        return TelegramBusinessNotification::where('kind', 'node_offline')->where('status', 'pending')->firstOrFail();
    }

    private function send(TelegramBusinessNotification $notification): void
    {
        app(BusinessNotificationService::class)->send($notification->id);
    }

    public function test_defaults_admin_authorization_validation_and_unrelated_data_preserved(): void
    {
        $admin = $this->user(['is_admin' => 1]);
        $binding = $this->bind($admin);
        $before = TelegramBotConfig::firstOrFail();
        $view = app(ConfigService::class)->view();
        $this->assertFalse($view['auto_approve_join']);
        $this->assertFalse($view['notify_node_offline']);
        $this->assertSame([], $view['join_group_ids']);
        $this->assertSame(30, $view['node_offline_minutes']);
        $path = '/api/v2/' . hash('crc32b', config('app.key')) . '/telegram-bot/group-node-settings';
        $body = $this->settings();
        $this->postJson($path, $body)->assertForbidden();
        Sanctum::actingAs($this->user(['is_staff' => 1]));
        $this->postJson($path, $body)->assertForbidden();
        Sanctum::actingAs($admin);
        $this->postJson($path, $body + ['token' => 'ignored', 'notify_ticket' => true])->assertOk()
            ->assertJsonPath('data.notify_node_offline', true)->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.notify_ticket', false)->assertJsonMissingPath('data.token');
        foreach ([['auto_approve_join' => true], ['auto_approve_join' => 'bad'], ['join_group_ids' => ['@group']],
            ['join_group_ids' => ['123']], ['join_group_ids' => ['-4503599627370496']],
            ['join_group_ids' => ['-1', '-1']], ['join_group_ids' => [-123]], ['join_group_ids' => null],
            ['join_group_ids' => array_map(fn ($n) => (string) -$n, range(1, 21))],
            ['node_offline_minutes' => 0], ['node_offline_minutes' => 1441], ['node_offline_minutes' => 1.5]] as $invalid) {
            $this->postJson($path, array_merge($body, $invalid))->assertUnprocessable();
        }
        $current = $before->fresh();
        foreach (['token', 'webhook_secret', 'webhook_url', 'remind_device', 'notify_admin_recipients', 'notify_ticket'] as $key) {
            $this->assertSame($before->{$key}, $current->{$key});
        }
        $this->assertNotNull($binding->fresh());
        Http::assertNothingSent();
    }

    public function test_enabling_join_on_running_bot_subscribes_without_dropping_updates(): void
    {
        $input = $this->settings(['auto_approve_join' => true, 'join_group_ids' => ['-1001234567890']]);
        app(ConfigService::class)->saveGroupNodeSettings($input);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/setWebhook')
            && $request['allowed_updates'] === ['message', 'callback_query', 'chat_join_request']
            && $request['drop_pending_updates'] === false && $request['secret_token'] === $this->secret);
        Http::assertSentCount(3);
        app(ConfigService::class)->saveGroupNodeSettings($input);
        Http::assertSentCount(3);
        $this->assertTrue(TelegramBotConfig::firstOrFail()->enabled);
    }

    #[DataProvider('registrationFailures')]
    public function test_join_registration_failure_does_not_save_settings(bool $mismatch): void
    {
        if ($mismatch) $this->remoteUrl = 'https://other.example.test/webhook';
        else $this->failure = 500;
        try {
            app(ConfigService::class)->saveGroupNodeSettings($this->settings([
                'auto_approve_join' => true, 'join_group_ids' => ['-1001234567890'],
            ]));
            $this->fail('应拒绝保存');
        } catch (\App\Exceptions\ApiException) {
            $this->assertFalse(TelegramBotConfig::firstOrFail()->auto_approve_join);
        }
    }

    public static function registrationFailures(): array
    {
        return [[true], [false]];
    }

    public function test_stopped_bot_saves_locally_and_subscribes_to_join_events_on_enable(): void
    {
        TelegramBotConfig::firstOrFail()->update(['enabled' => false, 'webhook_registered' => false]);
        app(ConfigService::class)->saveGroupNodeSettings($this->settings([
            'auto_approve_join' => true, 'join_group_ids' => ['-1001234567890'],
        ]));
        Http::assertNothingSent();
        app(ConfigService::class)->enable();
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/setWebhook')
            && $request['allowed_updates'] === ['message', 'callback_query', 'chat_join_request']);
        $this->assertTrue(TelegramBotConfig::firstOrFail()->enabled);
    }

    public function test_minute_command_records_and_queues_node_outage(): void
    {
        $this->configure();
        $this->bind($this->user(['is_admin' => 1]));
        $node = $this->node();
        $this->heartbeat($node);
        $this->travel(31)->minutes();
        $this->artisan('telegram-bot:send-reminders')->assertSuccessful();
        $this->assertSame('node_offline', $this->pending()->kind);
        Queue::assertPushed(SendTelegramBusinessNotification::class);
        Http::assertNothingSent();
    }

    #[DataProvider('joinEligibility')]
    public function test_join_uses_new_binding_and_legacy_eligibility(array $changes, bool $newBinding, bool $approve): void
    {
        $this->configure(['auto_approve_join' => true, 'join_group_ids' => ['-1001234567890']]);
        $user = $this->user($changes);
        if ($newBinding) $this->bind($user);
        $update = $this->join();
        $this->webhook($update)->assertOk();
        $this->webhook($update)->assertOk();
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), $approve ? '/approveChatJoinRequest' : '/declineChatJoinRequest')
            && $request['chat_id'] === -1001234567890 && $request['user_id'] === 900000001);
        $this->assertSame($approve ? 'join_approved' : 'join_declined', TelegramBotUpdate::firstOrFail()->action);
    }

    public static function joinEligibility(): array
    {
        return [
            '长期有效' => [[], true, true], '封禁' => [['banned' => 1], true, false],
            '无额度' => [['transfer_enable' => 0], true, false], '过期' => [['expired_at' => 1], true, false],
            '未绑定' => [[], false, false], '只有旧绑定' => [['telegram_id' => 900000001], false, false],
            '流量用尽沿用旧规则' => [['u' => 1024 ** 3], true, true],
        ];
    }

    public function test_disabled_unknown_group_malformed_stale_and_bad_source_do_not_review(): void
    {
        $this->bind($this->user());
        $this->webhook($this->join())->assertOk();
        $this->configure(['auto_approve_join' => true, 'join_group_ids' => ['-1001234567890']]);
        foreach ([['chat' => ['id' => -1009999999999]], ['chat' => ['id' => 42]],
            ['chat' => ['type' => 'channel']], ['chat' => 'bad'], ['from' => ['id' => '900000001']],
            ['from' => ['is_bot' => true]], ['date' => now()->timestamp - 86401], ['date' => now()->timestamp + 61]] as $changes) {
            $this->webhook($this->join(['chat_join_request' => $changes]))->assertOk();
        }
        $this->postJson(ConfigService::WEBHOOK_PATH, $this->join())->assertForbidden();
        Http::assertNothingSent();
        $this->assertDatabaseCount('v2_telegram_bot_updates', 0);
    }

    public function test_join_retry_rechecks_binding_and_requires_confirmed_api_result(): void
    {
        $this->configure(['auto_approve_join' => true, 'join_group_ids' => ['-1001234567890']]);
        $binding = $this->bind($this->user());
        $update = $this->join();
        $this->invalidResult = true;
        $this->webhook($update)->assertStatus(503);
        $this->assertDatabaseCount('v2_telegram_bot_updates', 0);
        $this->invalidResult = false;
        $this->failure = 429;
        $this->webhook($update)->assertStatus(503);
        $binding->delete();
        $this->failure = 0;
        $this->webhook($update)->assertOk();
        $this->assertSame('join_declined', TelegramBotUpdate::firstOrFail()->action);
        $this->webhook($update)->assertOk();
        Http::assertSentCount(3);
    }

    public function test_permanently_unavailable_join_is_not_claimed_as_approved(): void
    {
        $this->configure(['auto_approve_join' => true, 'join_group_ids' => ['-1001234567890']]);
        $this->bind($this->user());
        $this->failure = 400;
        $this->webhook($this->join())->assertOk();
        $this->webhook($this->join())->assertOk();
        $this->assertSame('join_unavailable', TelegramBotUpdate::firstOrFail()->action);
        Http::assertSentCount(1);
    }

    public function test_node_threshold_dedup_cache_expiry_recovery_and_second_outage(): void
    {
        $this->configure();
        $this->bind($this->user(['is_admin' => 1]));
        $this->bind($this->user(['is_staff' => 1]), 900000002);
        $this->bind($this->user(), 900000003);
        $this->bind($this->user(['is_admin' => 1, 'banned' => 1]), 900000004);
        $this->user(['is_admin' => 1, 'telegram_id' => 900000005]);
        $node = $this->node();
        $this->scan();
        $this->assertDatabaseCount('v2_telegram_node_states', 0);
        $this->heartbeat($node);
        $this->scan();
        $this->travel(30)->minutes();
        $this->scan();
        $this->assertDatabaseCount('v2_telegram_business_notifications', 0);
        $this->travel(1)->seconds();
        $this->scan();
        $notification = $this->pending();
        Queue::assertPushed(SendTelegramBusinessNotification::class);
        $this->send($notification);
        $this->send($notification);
        $this->travel(2)->hours();
        Cache::flush();
        $this->scan();
        Http::assertSentCount(1);
        $this->assertDatabaseCount('v2_telegram_business_notifications', 1);
        $this->heartbeat($node);
        $this->scan();
        $this->travel(31)->minutes();
        $this->scan();
        $this->send($this->pending());
        Http::assertSentCount(2);
        $this->assertDatabaseCount('v2_telegram_node_incidents', 2);
        Http::assertSent(fn ($request) => str_contains($request['text'] ?? '', '测试节点')
            && str_contains($request['text'], 'node.example.test') && $request['chat_id'] === 900000001);
    }

    public function test_long_threshold_survives_heartbeat_cache_expiry(): void
    {
        $this->configure(['node_offline_minutes' => 120]);
        $this->bind($this->user(['is_admin' => 1]));
        $node = $this->node();
        $this->heartbeat($node);
        $this->scan();
        $this->travel(121)->minutes();
        $this->assertNull($node->last_check_at);
        $this->scan();
        $this->send($this->pending());
        Http::assertSentCount(1);
    }

    public function test_skip_disabled_shared_children_but_check_relay_nodes(): void
    {
        $this->configure();
        $this->bind($this->user(['is_admin' => 1]));
        $parent = $this->node();
        $this->node(['parent_id' => $parent->id]);
        $disabled = $this->node(['enabled' => false]);
        $relay = $this->node(['relay_entry_id' => $parent->id]);
        foreach ([$parent, $disabled, $relay] as $node) $this->heartbeat($node);
        $this->travel(31)->minutes();
        $this->scan();
        $this->assertDatabaseCount('v2_telegram_node_incidents', 2);
        $this->assertSame([$parent->id, $relay->id], DB::table('v2_telegram_node_incidents')->orderBy('id')->pluck('server_id')->all());
    }

    #[DataProvider('staleNodeNotifications')]
    public function test_send_rechecks_node_recipient_binding_and_configuration(string $change): void
    {
        $this->configure();
        $admin = $this->user(['is_admin' => 1]);
        $binding = $this->bind($admin);
        $node = $this->node();
        $this->heartbeat($node);
        $this->travel(31)->minutes();
        $this->scan();
        $notification = $this->pending();
        match ($change) {
            '恢复' => $this->heartbeat($node),
            '删除节点' => $node->delete(),
            '停用节点' => $node->update(['enabled' => false]),
            '共享子节点' => $node->update(['parent_id' => $this->node()->id]),
            '降为员工' => $admin->update(['is_admin' => 0, 'is_staff' => 1]),
            '封禁' => $admin->update(['banned' => 1]),
            '解绑' => $binding->delete(),
            '改绑定' => $binding->update(['telegram_id' => 900000009]),
            '删除管理员' => $admin->delete(),
            '更换机器人' => TelegramBotConfig::firstOrFail()->update(['bot_id' => 123456788]),
            '调长等待时间' => TelegramBotConfig::firstOrFail()->update(['node_offline_minutes' => 60]),
            '关闭通知' => app(ConfigService::class)->saveGroupNodeSettings($this->settings(['notify_node_offline' => false])),
        };
        $this->send($notification);
        Http::assertNothingSent();
        $this->assertSame('cancelled', $notification->fresh()->status);
    }

    public static function staleNodeNotifications(): array
    {
        return array_map(fn ($kind) => [$kind], ['恢复', '删除节点', '停用节点', '共享子节点', '降为员工',
            '封禁', '解绑', '改绑定', '删除管理员', '更换机器人', '调长等待时间', '关闭通知']);
    }

    public function test_node_pause_resume_independent_retry_and_legacy_queue_suppression(): void
    {
        $this->configure();
        $this->bind($this->user(['is_admin' => 1]));
        $node = $this->node();
        $this->heartbeat($node);
        $this->travel(31)->minutes();
        $this->scan();
        $notification = $this->pending();
        TelegramBotConfig::firstOrFail()->update(['enabled' => false]);
        $this->send($notification);
        (new SendTelegramJob(900000001, '节点掉线通知'))->handle();
        $this->artisan('check:server')->assertSuccessful();
        Http::assertNothingSent();
        $this->assertSame('pending', $notification->fresh()->status);
        TelegramBotConfig::firstOrFail()->update(['enabled' => true]);
        $this->failure = 429;
        $this->send($notification);
        $this->assertSame(now()->timestamp + 180, $notification->fresh()->retry_at);
        $this->send($notification);
        Http::assertSentCount(1);
        $this->travel(181)->seconds();
        $this->failure = 0;
        $this->send($notification);
        $this->assertSame('sent', $notification->fresh()->status);
        Http::assertSentCount(2);
    }

    public function test_legacy_join_yields_only_configured_groups_even_while_new_bot_paused(): void
    {
        $this->configure(['auto_approve_join' => true, 'join_group_ids' => ['-1001234567890'], 'enabled' => false]);
        $this->assertTrue(\App\Services\TelegramBot\JoinRequestService::ownsGroup(-1001234567890));
        $this->assertFalse(\App\Services\TelegramBot\JoinRequestService::ownsGroup(-1009999999999));
        $controller = app(\App\Http\Controllers\V1\Guest\TelegramController::class);
        $method = new \ReflectionMethod($controller, 'formatChatJoinRequest');
        $method->invoke($controller, $this->join());
        Http::assertNothingSent();
    }
}
