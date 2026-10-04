<?php

namespace Tests\Feature\TelegramBot;

use App\Jobs\NodeUserSyncJob;
use App\Models\AdminAuditLog;
use App\Models\TelegramBotBinding;
use App\Models\TelegramBotConfig;
use App\Models\TelegramBotUpdate;
use App\Models\User;
use App\Services\TelegramBot\BindingService;
use App\Services\TelegramBot\ConfigService;
use App\Support\Setting;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TelegramBotTest extends TestCase
{
    use RefreshDatabase;

    private string $token;
    private string $secret;
    private string $remoteUrl = '';
    private int $sequence = 1;
    private array $calls = [];
    private bool $failReply = false;
    private bool $failRegistration = false;
    private bool $failAuthentication = false;
    private array $webhookInfo = [];
    private int $replyStatus = 0;
    private bool $invalidReply = false;
    private const BOT_ID = 123456789;
    private const TELEGRAM_ID = 900000001;
    private const SITE = 'https://panel.example.test';

    protected function setUp(): void
    {
        parent::setUp();
        app(Setting::class)->save(['app_url' => self::SITE, 'subscribe_url' => self::SITE]);
        $this->token = self::BOT_ID . ':' . Str::random(35);
        $this->secret = Str::random(48);
        TelegramBotConfig::findOrFail(1)->update([
            'token' => $this->token,
            'webhook_secret' => $this->secret,
            'webhook_url' => self::SITE . ConfigService::WEBHOOK_PATH,
            'bot_id' => self::BOT_ID,
            'bot_username' => 'yz_test_bot',
            'enabled' => true,
        ]);
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            $method = basename(parse_url($request->url(), PHP_URL_PATH));
            $data = $request->data();
            $this->calls[] = ['method' => $method, 'data' => $data];
            if ($method === 'getMe' && $this->failAuthentication) {
                return Http::response(['ok' => false, 'error_code' => 401], 401);
            }
            if ($method === 'sendMessage' && $this->replyStatus) {
                return Http::response(['ok' => false, 'error_code' => $this->replyStatus,
                    'description' => '模拟错误 ' . $this->token], $this->replyStatus);
            }
            if ($this->failReply && in_array($method, ['sendMessage', 'editMessageText'], true)) {
                $this->failReply = false;
                return Http::response(['ok' => false, 'description' => '模拟发送失败'], 500);
            }
            if ($method === 'setWebhook') {
                if ($this->failRegistration) {
                    return Http::response(['ok' => false], 500);
                }
                $this->remoteUrl = $data['url'];
            }
            if ($method === 'deleteWebhook') {
                $this->remoteUrl = '';
            }
            $result = match ($method) {
                'getMe' => ['id' => self::BOT_ID, 'is_bot' => true, 'username' => 'yz_test_bot'],
                'getWebhookInfo' => array_merge(['url' => $this->remoteUrl, 'pending_update_count' => 0], $this->webhookInfo),
                'sendMessage' => $this->invalidReply ? true : [
                    'message_id' => 1, 'chat' => ['id' => (int) $data['chat_id'], 'type' => 'private'],
                ],
                default => true,
            };
            return Http::response(['ok' => true, 'result' => $result]);
        });
    }

    private function user(array $attributes = []): User
    {
        return User::create(array_merge([
            'email' => Str::lower(Str::random(12)) . '@example.test',
            'password' => password_hash(Str::random(24), PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(false),
            'transfer_enable' => 1024 ** 3,
            'expired_at' => time() + 86400,
        ], $attributes));
    }

    private function message(string $text, int $sender = self::TELEGRAM_ID): array
    {
        $id = $this->sequence++;
        return ['update_id' => $id, 'message' => [
            'message_id' => $id, 'date' => time(), 'text' => $text,
            'from' => ['id' => $sender, 'is_bot' => false],
            'chat' => ['id' => $sender, 'type' => 'private'],
        ]];
    }

    private function buttonUpdate(string $action, int $sender = self::TELEGRAM_ID): array
    {
        return ['update_id' => $this->sequence++, 'callback_query' => [
            'id' => Str::random(20), 'data' => $action,
            'from' => ['id' => $sender, 'is_bot' => false],
            'message' => [
                'message_id' => 42, 'date' => time(),
                'from' => ['id' => self::BOT_ID, 'is_bot' => true],
                'chat' => ['id' => $sender, 'type' => 'private'],
            ],
        ]];
    }

    private function webhook(array $update)
    {
        return $this->postJson(ConfigService::WEBHOOK_PATH, $update, [
            'X-Telegram-Bot-Api-Secret-Token' => $this->secret,
        ]);
    }

    private function link(User $user): string
    {
        return self::SITE . '/s/' . $user->token;
    }

    private function bind(User $user, int $sender = self::TELEGRAM_ID): void
    {
        $this->webhook($this->message($this->link($user), $sender))->assertOk();
        $this->assertDatabaseHas('v2_telegram_bot_bindings', ['user_id' => $user->id, 'telegram_id' => $sender]);
    }

    private function lastReply(): array
    {
        $messages = array_values(array_filter($this->calls, fn ($call) => in_array($call['method'], ['sendMessage', 'editMessageText'], true)));
        return $messages[array_key_last($messages)]['data'];
    }

    private function adminPath(string $action): string
    {
        return '/api/v2/' . hash('crc32b', config('app.key')) . '/telegram-bot/' . $action;
    }

    private function asAdmin(): void
    {
        Sanctum::actingAs($this->user(['is_admin' => true]));
    }

    public function test_start_prompts_for_a_link_without_loading_the_old_plugin(): void
    {
        $this->webhook($this->message('/start'))->assertOk();
        $this->assertStringContainsString('订阅链接', $this->lastReply()['text']);
        $this->assertDatabaseCount('v2_telegram_bot_bindings', 0);
        $this->assertDatabaseMissing('v2_plugins', ['code' => 'telegram', 'is_enabled' => 1]);
    }

    public function test_connection_check_exposes_delivery_failure_even_when_registration_matches(): void
    {
        $this->asAdmin();
        $this->remoteUrl = self::SITE . ConfigService::WEBHOOK_PATH;
        $this->webhookInfo = [
            'pending_update_count' => 3, 'last_error_date' => time(),
            'last_error_message' => 'Wrong response from the webhook: 403 Forbidden ' . $this->token,
        ];
        $response = $this->postJson($this->adminPath('check'))->assertOk()
            ->assertJsonPath('data.webhook_matches', true)->assertJsonPath('data.pending_update_count', 3)
            ->assertJsonPath('data.last_received_at', null);
        $this->assertStringContainsString('HTTP 403', $response->json('data.last_error'));
        $this->assertStringNotContainsString($this->token, $response->getContent());
        $this->assertStringNotContainsString($this->token, TelegramBotConfig::first()->last_error);
        $this->getJson($this->adminPath('config'))->assertJsonPath('data.last_error', $response->json('data.last_error'));
    }

    public function test_delivery_errors_are_classified_without_storing_remote_text(): void
    {
        $this->asAdmin();
        $this->remoteUrl = self::SITE . ConfigService::WEBHOOK_PATH;
        foreach ([
            'Wrong response from the webhook: 404 Not Found' => 'HTTP 404',
            'Wrong response from the webhook: 503 Service Unavailable' => 'HTTP 503',
            'SSL error: certificate verify failed' => '证书',
            'Failed to resolve host' => '域名解析失败',
            'Connection timed out' => '超时',
            'Connection refused' => '连接被拒绝',
            'Unrecognized failure' => '面板入口和服务日志',
        ] as $remote => $expected) {
            $this->webhookInfo = ['last_error_message' => $remote . ' ' . $this->secret];
            $response = $this->postJson($this->adminPath('check'))->assertOk();
            $this->assertStringContainsString($expected, $response->json('data.last_error'));
            $this->assertStringNotContainsString($this->secret, $response->getContent());
        }
    }

    public function test_received_messages_clear_historical_errors_only_when_no_messages_are_pending(): void
    {
        $this->asAdmin();
        $this->remoteUrl = self::SITE . ConfigService::WEBHOOK_PATH;
        $this->webhookInfo = ['last_error_date' => time() - 60, 'last_error_message' => 'Connection timed out'];
        $this->postJson($this->adminPath('check'))->assertOk();
        $this->assertNotNull(TelegramBotConfig::first()->last_error);
        $this->webhook($this->message('/start'))->assertOk();
        $this->postJson($this->adminPath('check'))->assertOk()->assertJsonPath('data.last_error', null);
        $this->webhookInfo['pending_update_count'] = 1;
        $this->postJson($this->adminPath('check'))->assertOk();
        $this->assertNotNull(TelegramBotConfig::first()->last_error);
        $this->webhookInfo = [];
        $this->postJson($this->adminPath('check'))->assertOk()->assertJsonPath('data.last_error', null);
    }

    public function test_foreign_receiver_errors_are_not_reported_as_this_panels_delivery_failure(): void
    {
        $this->asAdmin();
        $this->remoteUrl = 'https://other.example.test/webhook';
        $this->webhookInfo = ['last_error_message' => 'Connection refused'];
        $this->postJson($this->adminPath('check'))->assertOk()
            ->assertJsonPath('data.webhook_matches', false)->assertJsonPath('data.last_error', null);
    }

    public function test_test_message_requires_admin_and_a_valid_private_recipient(): void
    {
        $path = $this->adminPath('test-message');
        $this->postJson($path, ['telegram_id' => self::TELEGRAM_ID])->assertStatus(403);
        Sanctum::actingAs($this->user());
        $this->postJson($path, ['telegram_id' => self::TELEGRAM_ID])->assertStatus(403);
        $this->asAdmin();
        foreach ([null, '', '0', '-100000001', '@example', '1.5', '1e9', '4503599627370496', ['123']] as $invalid) {
            $this->postJson($path, ['telegram_id' => $invalid])->assertStatus(422);
        }
        Http::assertNothingSent();
    }

    public function test_test_message_works_without_binding_or_webhook_and_does_not_change_receiving_state(): void
    {
        $this->asAdmin();
        TelegramBotConfig::first()->update(['enabled' => false, 'last_error' => '现有投递错误']);
        $before = TelegramBotConfig::first()->getRawOriginal();
        $this->postJson($this->adminPath('test-message'), [
            'telegram_id' => (string) self::TELEGRAM_ID, 'text' => '不允许自定义内容',
        ])->assertOk()->assertJsonPath('data.sent', true);
        $this->assertSame(['sendMessage'], array_column($this->calls, 'method'));
        $this->assertSame((string) self::TELEGRAM_ID, $this->lastReply()['chat_id']);
        $this->assertStringContainsString('测试消息', $this->lastReply()['text']);
        $this->assertStringNotContainsString('不允许自定义内容', $this->lastReply()['text']);
        $this->assertSame($before, TelegramBotConfig::first()->getRawOriginal());
        $this->assertDatabaseCount('v2_telegram_bot_bindings', 0);
        $this->assertDatabaseCount('v2_telegram_bot_updates', 0);
        $this->assertStringNotContainsString((string) self::TELEGRAM_ID, AdminAuditLog::latest('id')->first()->request_data);
    }

    public function test_successful_test_sends_are_limited_and_can_resume_after_ten_seconds(): void
    {
        $this->asAdmin();
        $path = $this->adminPath('test-message');
        $input = ['telegram_id' => (string) self::TELEGRAM_ID];
        $this->postJson($path, $input)->assertOk();
        $this->postJson($path, $input)->assertStatus(429);
        $this->assertCount(1, $this->calls);
        $this->travel(11)->seconds();
        $this->postJson($path, $input)->assertOk();
        $this->assertCount(2, $this->calls);
    }

    public function test_failed_test_send_is_actionable_and_can_be_retried_without_clearing_delivery_errors(): void
    {
        $this->asAdmin();
        TelegramBotConfig::first()->update(['last_error' => '现有投递错误']);
        foreach ([400, 403, 401, 429] as $status) {
            $this->replyStatus = $status;
            $response = $this->postJson($this->adminPath('test-message'), ['telegram_id' => self::TELEGRAM_ID])->assertStatus(502);
            $this->assertStringNotContainsString($this->token, $response->getContent());
            if (in_array($status, [400, 403], true)) {
                $this->assertStringContainsString('/start', $response->json('message'));
            }
        }
        $this->replyStatus = 0;
        $this->postJson($this->adminPath('test-message'), ['telegram_id' => self::TELEGRAM_ID])->assertOk();
        $this->assertNull(TelegramBotConfig::first()->last_received_at);
        $this->assertSame('现有投递错误', TelegramBotConfig::first()->last_error);
    }

    public function test_test_send_does_not_report_success_without_a_telegram_message_receipt(): void
    {
        $this->asAdmin();
        $this->invalidReply = true;
        $this->postJson($this->adminPath('test-message'), ['telegram_id' => self::TELEGRAM_ID])->assertStatus(502);
        $this->assertNull(TelegramBotConfig::first()->last_received_at);
    }

    public function test_test_send_without_saved_credentials_does_not_call_telegram(): void
    {
        $this->asAdmin();
        TelegramBotConfig::first()->update(['token' => null]);
        $this->postJson($this->adminPath('test-message'), ['telegram_id' => self::TELEGRAM_ID])->assertStatus(400);
        Http::assertNothingSent();
    }

    public function test_binding_displays_four_inline_menu_actions(): void
    {
        $user = $this->user(['telegram_id' => 800000001]);
        $this->bind($user);
        $buttons = $this->lastReply()['reply_markup']['inline_keyboard'];
        $this->assertSame(['订阅信息', '订阅链接', '重置订阅', '账户信息'], array_map(fn ($row) => $row[0]['text'], $buttons));
        $this->assertSame(800000001, $user->fresh()->telegram_id);
        $this->assertDatabaseCount('v2_telegram_bot_bindings', 1);
    }

    public function test_both_sides_are_unique_and_existing_binding_is_never_overwritten(): void
    {
        $first = $this->user();
        $second = $this->user();
        $this->bind($first);
        $this->webhook($this->message($this->link($first), self::TELEGRAM_ID + 1))->assertOk();
        $this->webhook($this->message($this->link($second)))->assertOk();
        $this->webhook($this->message($this->link($first)))->assertOk();
        $this->assertDatabaseCount('v2_telegram_bot_bindings', 1);
        $this->assertDatabaseHas('v2_telegram_bot_bindings', ['user_id' => $first->id, 'telegram_id' => self::TELEGRAM_ID]);
    }

    public function test_expired_account_can_bind_and_subscription_query_is_read_only(): void
    {
        $user = $this->user(['expired_at' => time() - 10, 'u' => 200, 'd' => 300, 'next_reset_at' => time() - 1]);
        $this->bind($user);
        $before = $user->fresh()->getRawOriginal();
        $this->webhook($this->buttonUpdate('subscription'))->assertOk();
        $this->assertStringContainsString('已过期', $this->lastReply()['text']);
        $this->assertSame($before, $user->fresh()->getRawOriginal());
    }

    public function test_subscription_changes_keep_binding_and_link_uses_latest_token(): void
    {
        $user = $this->user();
        $oldLink = $this->link($user);
        $this->bind($user);
        $id = TelegramBotBinding::first()->id;
        foreach ([
            ['expired_at' => time() + 30 * 86400],
            ['u' => 0, 'd' => 0, 'transfer_enable' => 2 * 1024 ** 3],
            ['expired_at' => time() - 1],
            ['banned' => true],
            ['token' => Helper::guid(false), 'uuid' => Helper::guid(true)],
        ] as $change) {
            $user->update($change);
            $this->assertSame($id, TelegramBotBinding::first()->id);
        }
        $this->webhook($this->buttonUpdate('link'))->assertOk();
        $this->assertStringContainsString($user->token, $this->lastReply()['text']);
        $this->assertStringNotContainsString($oldLink, $this->lastReply()['text']);
        $this->webhook($this->message($oldLink, self::TELEGRAM_ID + 1))->assertOk();
        $this->assertStringContainsString('无效', $this->lastReply()['text']);
        $this->assertDatabaseCount('v2_telegram_bot_bindings', 1);
    }

    public function test_subscription_reset_requires_confirmation_and_preserves_account_and_binding(): void
    {
        $user = $this->user(['telegram_id' => 800000001, 'u' => 123, 'd' => 456, 'balance' => 789]);
        $this->bind($user);
        $before = $user->fresh()->getRawOriginal();
        $binding = TelegramBotBinding::first();
        $oldLink = $this->link($user);
        Queue::fake([NodeUserSyncJob::class]);

        $this->webhook($this->buttonUpdate('reset_subscription'))->assertOk();
        $this->assertSame($before, $user->fresh()->getRawOriginal());
        Queue::assertNothingPushed();
        $confirmation = $this->lastReply()['reply_markup']['inline_keyboard'][0][0]['callback_data'];
        $this->assertStringContainsString('绑定会保留', $this->lastReply()['text']);
        $this->webhook($this->buttonUpdate($confirmation))->assertOk();

        $fresh = $user->fresh();
        $this->assertNotSame($before['token'], $fresh->token);
        $this->assertNotSame($before['uuid'], $fresh->uuid);
        $unchanged = array_diff_key($before, array_flip(['token', 'uuid', 'updated_at']));
        $this->assertSame($unchanged, array_intersect_key($fresh->getRawOriginal(), $unchanged));
        $binding->refresh();
        $this->assertSame($user->id, $binding->user_id);
        $this->assertSame(self::TELEGRAM_ID, $binding->telegram_id);
        $this->assertNull($binding->reset_token);
        $this->assertNull($binding->reset_expires_at);
        $this->assertDatabaseCount('v2_telegram_bot_bindings', 1);
        $reply = $this->lastReply();
        $this->assertStringContainsString('订阅已重置', $reply['text']);
        $this->assertStringContainsString(Helper::getSubscribeUrl($fresh->token), $reply['text']);
        $this->assertStringNotContainsString($oldLink, $reply['text']);
        $this->assertStringNotContainsString($fresh->uuid, $reply['text']);
        $this->assertTrue($reply['link_preview_options']['is_disabled']);
        Queue::assertPushed(NodeUserSyncJob::class, 1);

        $this->webhook($this->buttonUpdate('account'))->assertOk();
        $this->assertStringContainsString($user->email, $this->lastReply()['text']);
        $this->webhook($this->buttonUpdate('link'))->assertOk();
        $this->assertStringContainsString($fresh->token, $this->lastReply()['text']);
        $this->webhook($this->message($oldLink, self::TELEGRAM_ID + 1))->assertOk();
        $this->assertStringContainsString('无效', $this->lastReply()['text']);
        $this->assertSame($binding->id, TelegramBotBinding::first()->id);
        $records = TelegramBotUpdate::all()->toJson();
        $this->assertStringNotContainsString($fresh->token, $records);
        $this->assertStringNotContainsString($fresh->uuid, $records);
    }

    public function test_subscription_reset_confirmation_is_encrypted_and_hidden(): void
    {
        $this->bind($this->user());
        $this->webhook($this->buttonUpdate('reset_subscription'))->assertOk();
        $binding = TelegramBotBinding::first();
        $this->assertNotSame($binding->reset_token, $binding->getRawOriginal('reset_token'));
        $this->assertArrayNotHasKey('reset_token', $binding->toArray());
        $this->assertLessThanOrEqual(64, strlen($this->lastReply()['reply_markup']['inline_keyboard'][0][0]['callback_data']));
    }

    public function test_duplicate_reset_confirmation_only_rotates_credentials_once(): void
    {
        $user = $this->user();
        $this->bind($user);
        Queue::fake([NodeUserSyncJob::class]);
        $this->webhook($this->buttonUpdate('reset_subscription'))->assertOk();
        $confirmation = $this->lastReply()['reply_markup']['inline_keyboard'][0][0]['callback_data'];
        $update = $this->buttonUpdate($confirmation);
        $this->webhook($update)->assertOk();
        $after = $user->fresh()->getRawOriginal();
        $count = count($this->calls);
        $this->webhook($update)->assertOk();
        $this->assertCount($count + 1, $this->calls);
        $this->webhook($this->buttonUpdate($confirmation))->assertOk();
        $this->assertStringContainsString('失效', $this->lastReply()['text']);
        $this->assertSame($after, $user->fresh()->getRawOriginal());
        Queue::assertPushed(NodeUserSyncJob::class, 1);

        $this->webhook($this->buttonUpdate('reset_subscription'))->assertOk();
        $confirmation = $this->lastReply()['reply_markup']['inline_keyboard'][0][0]['callback_data'];
        $this->webhook($this->buttonUpdate($confirmation))->assertOk();
        $this->assertNotSame($after['token'], $user->fresh()->token);
        Queue::assertPushed(NodeUserSyncJob::class, 2);
    }

    public function test_failed_reset_reply_retries_current_link_without_rotating_again(): void
    {
        $user = $this->user();
        $this->bind($user);
        $this->webhook($this->buttonUpdate('reset_subscription'))->assertOk();
        $confirmation = $this->lastReply()['reply_markup']['inline_keyboard'][0][0]['callback_data'];
        $update = $this->buttonUpdate($confirmation);
        Queue::fake([NodeUserSyncJob::class]);
        $this->failReply = true;
        $this->webhook($update)->assertStatus(503);
        $after = $user->fresh()->getRawOriginal();
        $record = TelegramBotUpdate::where('update_id', $update['update_id'])->firstOrFail();
        $this->assertSame('subscription_reset', $record->action);
        $this->assertNull($record->sent_at);
        Cache::flush();
        $this->webhook($update)->assertOk();
        $this->assertSame($after, $user->fresh()->getRawOriginal());
        $this->assertNotNull($record->fresh()->sent_at);
        $this->assertStringContainsString(Helper::getSubscribeUrl($after['token']), $this->lastReply()['text']);
        $this->assertDatabaseCount('v2_telegram_bot_bindings', 1);
        Queue::assertPushed(NodeUserSyncJob::class, 1);
    }

    public function test_cancelled_expired_and_replaced_reset_confirmations_do_not_change_credentials(): void
    {
        $user = $this->user();
        $this->bind($user);
        $before = $user->fresh()->getRawOriginal();
        $this->webhook($this->buttonUpdate('reset_subscription'))->assertOk();
        $cancelled = $this->lastReply()['reply_markup']['inline_keyboard'][0][0]['callback_data'];
        $this->webhook($this->buttonUpdate('reset_cancel'))->assertOk();
        $this->webhook($this->buttonUpdate($cancelled))->assertOk();
        $this->assertSame($before, $user->fresh()->getRawOriginal());

        $this->webhook($this->buttonUpdate('reset_subscription'))->assertOk();
        $expired = $this->lastReply()['reply_markup']['inline_keyboard'][0][0]['callback_data'];
        TelegramBotBinding::query()->update(['reset_expires_at' => time()]);
        $this->webhook($this->buttonUpdate($expired))->assertOk();
        $this->assertSame($before, $user->fresh()->getRawOriginal());

        $this->webhook($this->buttonUpdate('reset_subscription'))->assertOk();
        $replaced = $this->lastReply()['reply_markup']['inline_keyboard'][0][0]['callback_data'];
        $this->webhook($this->buttonUpdate('reset_subscription'))->assertOk();
        $current = $this->lastReply()['reply_markup']['inline_keyboard'][0][0]['callback_data'];
        $this->webhook($this->buttonUpdate($replaced))->assertOk();
        $this->assertSame($before, $user->fresh()->getRawOriginal());
        $this->webhook($this->buttonUpdate($current))->assertOk();
        $this->assertNotSame($before['token'], $user->fresh()->token);
        $this->assertDatabaseCount('v2_telegram_bot_bindings', 1);
    }

    public function test_reset_confirmation_cannot_target_another_sender_or_a_group(): void
    {
        $first = $this->user();
        $second = $this->user();
        $this->bind($first);
        $this->bind($second, self::TELEGRAM_ID + 1);
        $before = [$first->fresh()->token, $second->fresh()->token];
        $this->webhook($this->buttonUpdate('reset_subscription'))->assertOk();
        $confirmation = $this->lastReply()['reply_markup']['inline_keyboard'][0][0]['callback_data'];
        $this->webhook($this->buttonUpdate($confirmation, self::TELEGRAM_ID + 1))->assertOk();
        $this->assertStringContainsString('失效', $this->lastReply()['text']);
        $update = $this->buttonUpdate($confirmation);
        $update['callback_query']['message']['chat'] = ['id' => -100000001, 'type' => 'supergroup'];
        $this->webhook($update)->assertOk();
        $this->assertStringContainsString('私聊', $this->lastReply()['text']);
        $this->webhook($this->buttonUpdate('reset:' . Str::random(24)))->assertOk();
        $this->assertSame($before, [$first->fresh()->token, $second->fresh()->token]);
        $this->webhook($this->buttonUpdate($confirmation))->assertOk();
        $this->assertNotSame($before[0], $first->fresh()->token);
        $this->assertSame($before[1], $second->fresh()->token);
    }

    public function test_unbound_and_deleted_users_cannot_reset_subscription(): void
    {
        $user = $this->user();
        $before = $user->fresh()->getRawOriginal();
        $this->webhook($this->buttonUpdate('reset_subscription'))->assertOk();
        $this->webhook($this->buttonUpdate('reset:' . Str::random(24)))->assertOk();
        $this->assertSame($before, $user->fresh()->getRawOriginal());
        $this->assertDatabaseCount('v2_telegram_bot_bindings', 0);
        $this->bind($user);
        $this->webhook($this->buttonUpdate('reset_subscription'))->assertOk();
        $confirmation = $this->lastReply()['reply_markup']['inline_keyboard'][0][0]['callback_data'];
        $user->delete();
        $this->webhook($this->buttonUpdate($confirmation))->assertOk();
        $this->assertDatabaseCount('v2_telegram_bot_bindings', 0);
        $this->assertStringNotContainsString($before['token'], $this->lastReply()['text']);
    }

    public function test_old_reset_confirmations_cannot_reset_a_rebound_account(): void
    {
        $first = $this->user();
        $second = $this->user();
        $this->bind($first);
        $this->webhook($this->buttonUpdate('reset_subscription'))->assertOk();
        $confirmation = $this->lastReply()['reply_markup']['inline_keyboard'][0][0]['callback_data'];
        $this->webhook($this->buttonUpdate('unbind'))->assertOk();
        $unbind = $this->lastReply()['reply_markup']['inline_keyboard'][0][0]['callback_data'];
        $this->webhook($this->buttonUpdate($unbind))->assertOk();
        $this->bind($second);
        $before = [$first->fresh()->token, $second->fresh()->token];
        $this->webhook($this->buttonUpdate($confirmation))->assertOk();
        $this->assertSame($before, [$first->fresh()->token, $second->fresh()->token]);
        $this->assertDatabaseHas('v2_telegram_bot_bindings', ['user_id' => $second->id, 'telegram_id' => self::TELEGRAM_ID]);
    }

    public function test_retrying_reset_reply_after_rebinding_does_not_expose_the_new_account(): void
    {
        $first = $this->user();
        $second = $this->user();
        $this->bind($first);
        $this->webhook($this->buttonUpdate('reset_subscription'))->assertOk();
        $confirmation = $this->lastReply()['reply_markup']['inline_keyboard'][0][0]['callback_data'];
        $update = $this->buttonUpdate($confirmation);
        $this->failReply = true;
        $this->webhook($update)->assertStatus(503);
        $this->webhook($this->buttonUpdate('unbind'))->assertOk();
        $unbind = $this->lastReply()['reply_markup']['inline_keyboard'][0][0]['callback_data'];
        $this->webhook($this->buttonUpdate($unbind))->assertOk();
        $this->bind($second);
        $before = [$first->fresh()->token, $second->fresh()->token];
        $this->webhook($update)->assertOk();
        $this->assertStringContainsString('失效', $this->lastReply()['text']);
        $this->assertStringNotContainsString($second->token, $this->lastReply()['text']);
        $this->assertSame($before, [$first->fresh()->token, $second->fresh()->token]);
    }

    public function test_failed_reset_transaction_keeps_credentials_and_confirmation_for_retry(): void
    {
        $user = $this->user();
        $this->bind($user);
        $before = $user->fresh()->getRawOriginal();
        $this->webhook($this->buttonUpdate('reset_subscription'))->assertOk();
        $confirmation = $this->lastReply()['reply_markup']['inline_keyboard'][0][0]['callback_data'];
        $bindingBefore = TelegramBotBinding::first()->getRawOriginal();
        $update = $this->buttonUpdate($confirmation);
        $fail = true;
        Event::listen('eloquent.updating: ' . TelegramBotBinding::class, function ($binding) use (&$fail) {
            if ($fail && $binding->isDirty('reset_token') && $binding->reset_token === null) {
                throw new \RuntimeException('模拟确认保存失败');
            }
        });
        Queue::fake([NodeUserSyncJob::class]);
        $this->webhook($update)->assertStatus(503);
        $this->assertSame($before, $user->fresh()->getRawOriginal());
        $this->assertSame($bindingBefore, TelegramBotBinding::first()->getRawOriginal());
        $this->assertDatabaseMissing('v2_telegram_bot_updates', ['update_id' => $update['update_id']]);
        Queue::assertNothingPushed();
        $fail = false;
        $this->webhook($update)->assertOk();
        $this->assertNotSame($before['token'], $user->fresh()->token);
        Queue::assertPushed(NodeUserSyncJob::class, 1);
    }

    public function test_reset_confirmation_migration_keeps_existing_binding_data(): void
    {
        $this->bind($this->user());
        $migration = require database_path('migrations/2026_10_05_000001_add_subscription_reset_confirmation_to_telegram_bot_bindings.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('v2_telegram_bot_bindings', 'reset_token'));
        $before = DB::table('v2_telegram_bot_bindings')->first();
        $migration->up();
        $after = DB::table('v2_telegram_bot_bindings')->first();
        $this->assertSame((array) $before, array_intersect_key((array) $after, (array) $before));
        $this->assertNull($after->reset_token);
        $this->assertNull($after->reset_expires_at);
    }

    public function test_callbacks_are_scoped_to_sender_and_do_not_accept_target_user_ids(): void
    {
        $first = $this->user();
        $second = $this->user();
        $this->bind($first);
        $this->bind($second, self::TELEGRAM_ID + 1);
        $this->webhook($this->buttonUpdate('account', self::TELEGRAM_ID + 1))->assertOk();
        $this->assertStringContainsString($second->email, $this->lastReply()['text']);
        $this->assertStringNotContainsString($first->email, $this->lastReply()['text']);
        $this->webhook($this->buttonUpdate('account:' . $first->id, self::TELEGRAM_ID + 1))->assertOk();
        $this->assertStringNotContainsString($first->email, $this->lastReply()['text']);
        $forged = $this->buttonUpdate('account');
        $forged['callback_query']['message']['chat']['id'] = self::TELEGRAM_ID + 1;
        $count = count($this->calls);
        $this->webhook($forged)->assertOk();
        $this->assertCount($count, $this->calls);
    }

    public function test_group_chats_cannot_bind_or_read_account_details(): void
    {
        $user = $this->user();
        $update = $this->message($this->link($user));
        $update['message']['chat'] = ['id' => -100000001, 'type' => 'supergroup'];
        $this->webhook($update)->assertOk();
        $this->assertDatabaseCount('v2_telegram_bot_bindings', 0);
        $this->assertStringContainsString('私聊', $this->lastReply()['text']);
        $this->bind($user);
        $update = $this->buttonUpdate('account');
        $update['callback_query']['message']['chat'] = ['id' => -100000001, 'type' => 'supergroup'];
        $this->webhook($update)->assertOk();
        $this->assertStringNotContainsString($user->email, $this->lastReply()['text']);
    }

    public function test_unbind_requires_confirmation_and_old_confirmations_cannot_unbind_a_new_account(): void
    {
        $user = $this->user();
        $this->bind($user);
        $this->webhook($this->message('/unbind'))->assertOk();
        $this->assertDatabaseCount('v2_telegram_bot_bindings', 1);
        $confirmation = $this->lastReply()['reply_markup']['inline_keyboard'][0][0]['callback_data'];
        $confirmUpdate = $this->buttonUpdate($confirmation);
        $this->webhook($confirmUpdate)->assertOk();
        $this->assertDatabaseCount('v2_telegram_bot_bindings', 0);
        $this->webhook($this->buttonUpdate('account'))->assertOk();
        $this->assertStringNotContainsString($user->email, $this->lastReply()['text']);
        $second = $this->user();
        $this->bind($second);
        $this->webhook($confirmUpdate)->assertOk();
        $this->webhook($this->buttonUpdate($confirmation))->assertOk();
        $this->assertDatabaseHas('v2_telegram_bot_bindings', ['user_id' => $second->id]);
    }

    public function test_cancelled_or_expired_unbind_confirmation_does_not_delete_binding(): void
    {
        $this->bind($this->user());
        $this->webhook($this->buttonUpdate('unbind'))->assertOk();
        $confirmation = $this->lastReply()['reply_markup']['inline_keyboard'][0][0]['callback_data'];
        $this->webhook($this->buttonUpdate('unbind_cancel'))->assertOk();
        $this->webhook($this->buttonUpdate($confirmation))->assertOk();
        $this->assertDatabaseCount('v2_telegram_bot_bindings', 1);
        $this->webhook($this->buttonUpdate('unbind'))->assertOk();
        $confirmation = $this->lastReply()['reply_markup']['inline_keyboard'][0][0]['callback_data'];
        TelegramBotBinding::query()->update(['unbind_expires_at' => time() - 1]);
        $this->webhook($this->buttonUpdate($confirmation))->assertOk();
        $this->assertDatabaseCount('v2_telegram_bot_bindings', 1);
    }

    public function test_failed_delivery_can_retry_without_applying_binding_twice(): void
    {
        $user = $this->user();
        $update = $this->message($this->link($user));
        $this->failReply = true;
        $this->webhook($update)->assertStatus(503);
        $this->assertDatabaseCount('v2_telegram_bot_bindings', 1);
        $this->assertNull(TelegramBotUpdate::first()->sent_at);
        Cache::flush();
        $this->webhook($update)->assertOk();
        $this->assertNotNull(TelegramBotUpdate::first()->sent_at);
        $this->assertDatabaseCount('v2_telegram_bot_bindings', 1);
        $count = count($this->calls);
        $this->webhook($update)->assertOk();
        $this->assertCount($count, $this->calls);
    }

    public function test_replaying_an_old_link_after_unbinding_does_not_restore_binding(): void
    {
        $user = $this->user();
        $update = $this->message($this->link($user));
        $this->webhook($update)->assertOk();
        TelegramBotBinding::query()->delete();
        $this->webhook($update)->assertOk();
        $this->assertDatabaseCount('v2_telegram_bot_bindings', 0);
        TelegramBotUpdate::query()->update(['created_at' => time() - 8 * 86400]);
        $this->artisan('telegram-bot:prune-updates')->assertSuccessful();
        $this->assertDatabaseCount('v2_telegram_bot_updates', 0);
        $update['message']['date'] = time() - 8 * 86400;
        $this->webhook($update)->assertOk();
        $this->assertDatabaseCount('v2_telegram_bot_bindings', 0);
    }

    public function test_database_deletion_cascades_for_single_and_bulk_user_deletes(): void
    {
        $first = $this->user();
        $second = $this->user();
        $this->bind($first);
        $this->bind($second, self::TELEGRAM_ID + 1);
        $first->delete();
        $this->assertDatabaseMissing('v2_telegram_bot_bindings', ['user_id' => $first->id]);
        User::whereKey($second->id)->delete();
        $this->assertDatabaseCount('v2_telegram_bot_bindings', 0);
        $this->user(['email' => $first->email]);
        $this->webhook($this->buttonUpdate('account'))->assertOk();
        $this->assertStringNotContainsString($first->email, $this->lastReply()['text']);
    }

    public function test_duplicate_subscription_identifiers_are_rejected(): void
    {
        $first = $this->user();
        $this->user(['token' => $first->token]);
        $this->webhook($this->message($this->link($first)))->assertOk();
        $this->assertDatabaseCount('v2_telegram_bot_bindings', 0);
    }

    public function test_webhook_rejects_invalid_source_and_disabled_bot(): void
    {
        $this->postJson(ConfigService::WEBHOOK_PATH, $this->message('/start'))->assertStatus(403);
        TelegramBotConfig::whereKey(1)->update(['enabled' => false]);
        $this->webhook($this->message('/start'))->assertStatus(503);
        Http::assertNothingSent();
    }

    public function test_processed_records_do_not_store_links_or_credentials(): void
    {
        $user = $this->user();
        $this->bind($user);
        $stored = json_encode(DB::table('v2_telegram_bot_updates')->get());
        foreach ([$this->link($user), $user->token, $this->secret, $this->token] as $secret) {
            $this->assertStringNotContainsString($secret, $stored);
        }
    }

    public function test_configuration_is_admin_only_encrypted_and_not_automatically_connected(): void
    {
        $this->getJson($this->adminPath('config'))->assertStatus(403);
        Sanctum::actingAs($this->user());
        $this->getJson($this->adminPath('config'))->assertStatus(403);
        $this->asAdmin();
        TelegramBotConfig::whereKey(1)->update(['enabled' => false]);
        $token = self::BOT_ID . ':' . Str::random(35);
        $this->postJson($this->adminPath('save') . '?secret=' . Str::random(16), [
            'token' => $token,
            'webhook_url' => self::SITE . ConfigService::WEBHOOK_PATH,
            'nested' => ['token' => $token],
        ])->assertOk()->assertJsonPath('data.configured', true)->assertJsonMissingPath('data.token');
        $this->assertNotSame($token, DB::table('v2_telegram_bot_configs')->value('token'));
        $this->assertSame($token, TelegramBotConfig::first()->token);
        $audit = AdminAuditLog::latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertStringNotContainsString($token, json_encode($audit));
        $this->assertStringNotContainsString('?', $audit->uri);
        Http::assertNothingSent();
    }

    public function test_audit_redaction_does_not_depend_on_the_admin_url_shape(): void
    {
        $this->asAdmin();
        TelegramBotConfig::whereKey(1)->update(['enabled' => false]);
        $path = '/api/v2/custom/admin/telegram-bot/save';
        $this->app['router']->post($path, [\App\Http\Controllers\V2\Admin\TelegramBotController::class, 'save'])
            ->middleware(['admin', 'log']);
        $token = self::BOT_ID . ':' . Str::random(35);
        $this->postJson($path . '?secret=' . $token, [
            'token' => $token,
            'webhook_url' => self::SITE . ConfigService::WEBHOOK_PATH,
            'nested' => ['token' => $token],
        ])->assertOk();
        $audit = AdminAuditLog::latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertStringNotContainsString($token, json_encode($audit));
        $this->assertSame($path, $audit->uri);
    }

    public function test_saved_token_is_available_only_through_the_admin_reveal_endpoint(): void
    {
        $this->getJson($this->adminPath('token'))->assertStatus(403);
        Sanctum::actingAs($this->user());
        $this->getJson($this->adminPath('token'))->assertStatus(403);
        $this->asAdmin();
        $encrypted = DB::table('v2_telegram_bot_configs')->value('token');

        $this->getJson($this->adminPath('config'))->assertOk()->assertJsonMissingPath('data.token');
        $this->getJson($this->adminPath('token'))->assertOk()
            ->assertJsonPath('data.token', $this->token)
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Pragma', 'no-cache');
        $this->assertSame($encrypted, DB::table('v2_telegram_bot_configs')->value('token'));
        $this->assertStringNotContainsString($this->token, AdminAuditLog::all()->toJson());
        Http::assertNothingSent();
    }

    public function test_saved_token_reveal_returns_empty_when_no_token_is_configured(): void
    {
        $this->asAdmin();
        TelegramBotConfig::first()->update(['token' => null]);
        $this->getJson($this->adminPath('token'))->assertOk()->assertJsonPath('data.token', null);
    }

    public function test_enabling_registers_private_commands_and_header_secret(): void
    {
        $this->asAdmin();
        TelegramBotConfig::whereKey(1)->update(['enabled' => false]);
        $this->postJson($this->adminPath('enable'))->assertOk()->assertJsonPath('data.enabled', true);
        $registration = array_values(array_filter($this->calls, fn ($call) => $call['method'] === 'setWebhook'))[0]['data'];
        $this->assertSame($this->secret, $registration['secret_token']);
        $this->assertSame(['message', 'callback_query'], $registration['allowed_updates']);
        $this->assertFalse($registration['drop_pending_updates']);
        $this->assertContains('setMyCommands', array_column($this->calls, 'method'));
    }

    public function test_existing_foreign_webhook_is_not_overwritten_or_deleted(): void
    {
        $this->asAdmin();
        TelegramBotConfig::whereKey(1)->update(['enabled' => false]);
        $this->remoteUrl = 'https://other.example.test/webhook';
        $this->postJson($this->adminPath('enable'))->assertStatus(409);
        $this->assertNotContains('setWebhook', array_column($this->calls, 'method'));
        $this->postJson($this->adminPath('disable'))->assertOk();
        $this->assertNotContains('deleteWebhook', array_column($this->calls, 'method'));
        $this->assertSame('https://other.example.test/webhook', $this->remoteUrl);
    }

    public function test_uncertain_registration_prevents_credential_changes_until_stopped(): void
    {
        $this->asAdmin();
        TelegramBotConfig::whereKey(1)->update(['enabled' => false]);
        $this->failRegistration = true;
        $this->postJson($this->adminPath('enable'))->assertStatus(502);
        $this->assertTrue(TelegramBotConfig::first()->webhook_registered);
        $this->postJson($this->adminPath('save'), [
            'token' => self::BOT_ID . ':' . Str::random(35),
            'webhook_url' => self::SITE . ConfigService::WEBHOOK_PATH,
        ])->assertStatus(409);
        $this->postJson($this->adminPath('disable'))->assertOk();
        $this->assertFalse(TelegramBotConfig::first()->webhook_registered);
    }

    public function test_disabling_and_changing_configuration_preserves_binding(): void
    {
        $user = $this->user();
        $this->bind($user);
        $this->asAdmin();
        $this->remoteUrl = self::SITE . ConfigService::WEBHOOK_PATH;
        $this->postJson($this->adminPath('disable'))->assertOk()->assertJsonPath('data.enabled', false);
        $this->assertDatabaseHas('v2_telegram_bot_bindings', ['user_id' => $user->id]);
        $this->postJson($this->adminPath('save'), [
            'token' => self::BOT_ID . ':' . Str::random(35),
            'webhook_url' => self::SITE . ConfigService::WEBHOOK_PATH,
        ])->assertOk();
        $this->assertDatabaseHas('v2_telegram_bot_bindings', ['user_id' => $user->id]);
    }

    public function test_revoked_credentials_can_be_replaced_after_local_shutdown(): void
    {
        $user = $this->user();
        $this->bind($user);
        $this->asAdmin();
        TelegramBotConfig::whereKey(1)->update(['webhook_registered' => true]);
        $this->failAuthentication = true;
        $this->postJson($this->adminPath('disable'))->assertOk()
            ->assertJsonPath('data.enabled', false)->assertJsonPath('data.webhook_registered', false);
        $this->assertNull(TelegramBotConfig::first()->webhook_secret);
        $this->postJson($this->adminPath('save'), [
            'token' => self::BOT_ID . ':' . Str::random(35),
            'webhook_url' => self::SITE . ConfigService::WEBHOOK_PATH,
        ])->assertOk();
        $this->failAuthentication = false;
        $this->postJson($this->adminPath('enable'))->assertOk()->assertJsonPath('data.enabled', true);
        $this->assertDatabaseHas('v2_telegram_bot_bindings', ['user_id' => $user->id]);
    }

    public function test_retrying_failed_unbind_reply_does_not_remove_later_binding(): void
    {
        $this->bind($this->user());
        $this->webhook($this->message('/unbind'))->assertOk();
        $confirmation = $this->lastReply()['reply_markup']['inline_keyboard'][0][0]['callback_data'];
        $update = $this->buttonUpdate($confirmation);
        $this->failReply = true;
        $this->webhook($update)->assertStatus(503);
        $this->assertDatabaseCount('v2_telegram_bot_bindings', 0);
        $nextUser = $this->user();
        $this->bind($nextUser);
        $this->webhook($update)->assertOk();
        $this->assertDatabaseHas('v2_telegram_bot_bindings', ['user_id' => $nextUser->id]);
    }

    public function test_replayed_private_query_never_delivers_account_data_to_a_group(): void
    {
        $user = $this->user();
        $this->bind($user);
        $update = $this->buttonUpdate('link');
        $this->failReply = true;
        $this->webhook($update)->assertStatus(503);
        $update['callback_query']['message']['chat'] = ['id' => -100000001, 'type' => 'supergroup'];
        $this->webhook($update)->assertOk();
        $this->assertStringNotContainsString($user->token, $this->lastReply()['text']);
        $this->assertStringContainsString('私聊', $this->lastReply()['text']);
    }

    public function test_admin_binding_list_is_paginated_and_does_not_serialize_credentials(): void
    {
        $first = $this->user();
        $second = $this->user();
        $this->bind($first);
        $this->bind($second, self::TELEGRAM_ID + 1);
        $this->asAdmin();
        $response = $this->getJson($this->adminPath('bindings') . '?per_page=1')->assertOk();
        $response->assertJsonPath('total', 2)->assertJsonCount(1, 'data');
        $this->assertStringNotContainsString($first->token, $response->getContent());
        $this->getJson($this->adminPath('bindings') . '?search=' . self::TELEGRAM_ID)
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.user_id', $first->id);
    }
}
