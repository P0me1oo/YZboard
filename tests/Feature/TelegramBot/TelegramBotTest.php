<?php

namespace Tests\Feature\TelegramBot;

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
use Illuminate\Support\Facades\Http;
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
                'getWebhookInfo' => ['url' => $this->remoteUrl, 'pending_update_count' => 0],
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

    public function test_binding_displays_exactly_three_inline_menu_actions(): void
    {
        $user = $this->user(['telegram_id' => 800000001]);
        $this->bind($user);
        $buttons = $this->lastReply()['reply_markup']['inline_keyboard'];
        $this->assertSame(['订阅信息', '订阅链接', '账户信息'], array_map(fn ($row) => $row[0]['text'], $buttons));
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
