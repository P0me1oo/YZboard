<?php

namespace Tests\Feature\TelegramBot;

use App\Models\Setting;
use App\Models\TelegramBotBinding;
use App\Models\TelegramBotConfig;
use App\Models\User;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TelegramUserEntryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function user(array $attributes = []): User
    {
        return User::create(array_merge([
            'email' => bin2hex(random_bytes(8)) . '@example.test',
            'password' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
            'token' => Helper::guid(false), 'uuid' => Helper::guid(true),
        ], $attributes));
    }

    private function groupPath(): string
    {
        return '/api/v2/' . hash('crc32b', config('app.key')) . '/telegram-bot/group-node-settings';
    }

    private function groupSettings(array $attributes = []): array
    {
        return array_merge(['auto_approve_join' => false, 'join_group_ids' => [],
            'notify_node_offline' => false, 'node_offline_minutes' => 30], $attributes);
    }

    public function test_user_entry_and_client_flag_use_the_new_bot_without_network_requests(): void
    {
        $user = $this->user();
        Setting::createOrUpdate('telegram_bot_enable', false);
        Setting::createOrUpdate('telegram_discuss_link', 'https://t.me/old_group');
        TelegramBotConfig::whereKey(1)->update(['enabled' => true, 'bot_username' => 'new_test_bot',
            'group_link' => 'https://t.me/+NewGroup']);
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/user/comm/config')->assertOk()
            ->assertJsonPath('data.is_telegram', 1)->assertJsonPath('data.telegram_discuss_link', 'https://t.me/+NewGroup');
        $this->getJson('/api/v1/user/telegram/getBotInfo')->assertOk()
            ->assertJsonPath('data.username', 'new_test_bot')->assertJsonCount(1, 'data');
        $this->getJson('/api/v2/client/app/getConfig?token=' . $user->token)->assertOk()
            ->assertJsonPath('data.features.enable_telegram_bot', true);
        $this->assertSame('https://t.me/old_group', Setting::where('name', 'telegram_discuss_link')->first()->value);
        Http::assertNothingSent();
    }

    public function test_old_enable_switch_does_not_activate_the_new_entry(): void
    {
        Setting::createOrUpdate('telegram_bot_enable', true);
        Setting::createOrUpdate('telegram_discuss_link', 'https://t.me/old_group');
        Sanctum::actingAs($this->user());
        $this->getJson('/api/v1/user/comm/config')->assertOk()
            ->assertJsonPath('data.is_telegram', 0)->assertJsonPath('data.telegram_discuss_link', null);
        $this->getJson('/api/v1/user/telegram/getBotInfo')->assertStatus(400);
        Http::assertNothingSent();
    }

    public function test_disabled_or_unconfigured_bot_hides_entry_but_keeps_group_link_and_binding(): void
    {
        $user = $this->user();
        $binding = TelegramBotBinding::create(['user_id' => $user->id, 'telegram_id' => 900001]);
        Sanctum::actingAs($user);
        foreach ([['enabled' => false, 'bot_username' => 'test_bot'], ['enabled' => true, 'bot_username' => null]] as $state) {
            TelegramBotConfig::whereKey(1)->update($state + ['group_link' => 'https://t.me/group']);
            $this->getJson('/api/v1/user/comm/config')->assertOk()
                ->assertJsonPath('data.is_telegram', 0)->assertJsonPath('data.telegram_discuss_link', 'https://t.me/group');
            $this->getJson('/api/v1/user/telegram/getBotInfo')->assertStatus(400);
            $this->getJson('/api/v1/user/info')->assertOk()->assertJsonPath('data.telegram_id', 900001);
        }
        $this->assertNotNull($binding->fresh());
        Http::assertNothingSent();
    }

    public function test_missing_bot_configuration_is_unavailable_instead_of_using_legacy_data(): void
    {
        TelegramBotConfig::whereKey(1)->delete();
        Setting::createOrUpdate('telegram_bot_enable', true);
        Sanctum::actingAs($this->user());
        $this->getJson('/api/v1/user/comm/config')->assertOk()->assertJsonPath('data.is_telegram', 0);
        $this->getJson('/api/v1/user/telegram/getBotInfo')->assertStatus(400);
        Http::assertNothingSent();
    }

    public function test_binding_status_tracks_new_bind_unbind_and_rebind_without_changing_legacy_binding(): void
    {
        $user = $this->user(['telegram_id' => 800001]);
        $other = $this->user();
        TelegramBotBinding::create(['user_id' => $other->id, 'telegram_id' => 900002]);
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/user/info')->assertOk()->assertJsonPath('data.telegram_id', null);
        $binding = TelegramBotBinding::create(['user_id' => $user->id, 'telegram_id' => 900001]);
        $this->getJson('/api/v1/user/info')->assertOk()->assertJsonPath('data.telegram_id', 900001);
        $binding->delete();
        $this->getJson('/api/v1/user/info')->assertOk()->assertJsonPath('data.telegram_id', null);
        TelegramBotBinding::create(['user_id' => $user->id, 'telegram_id' => 900003]);
        $this->getJson('/api/v1/user/info')->assertOk()->assertJsonPath('data.telegram_id', 900003);
        $this->assertSame(800001, $user->fresh()->telegram_id);
    }

    public function test_user_endpoints_still_require_authentication(): void
    {
        foreach (['/api/v1/user/info', '/api/v1/user/comm/config', '/api/v1/user/telegram/getBotInfo'] as $path) {
            $this->getJson($path)->assertForbidden();
        }
    }

    public function test_group_link_save_clear_retry_and_old_payload_preserve_other_settings(): void
    {
        $admin = $this->user(['is_admin' => true]);
        Sanctum::actingAs($admin);
        $config = TelegramBotConfig::findOrFail(1);
        $config->enabled = true;
        $config->bot_username = 'test_bot';
        $config->remind_expiring = true;
        $config->save();
        $before = $config->toArray();
        $binding = TelegramBotBinding::create(['user_id' => $admin->id, 'telegram_id' => 900001]);
        Setting::createOrUpdate('telegram_discuss_link', 'https://t.me/old_group');
        $body = $this->groupSettings(['group_link' => ' https://t.me/+Invite ']);
        foreach ([1, 2] as $attempt) {
            $this->postJson($this->groupPath(), $body)->assertOk()->assertJsonPath('data.group_link', 'https://t.me/+Invite');
        }
        $this->postJson($this->groupPath(), $this->groupSettings())->assertOk()
            ->assertJsonPath('data.group_link', 'https://t.me/+Invite');
        $this->getJson('/api/v1/user/comm/config')->assertJsonPath('data.telegram_discuss_link', 'https://t.me/+Invite');
        $this->postJson($this->groupPath(), $this->groupSettings(['group_link' => '']))->assertOk()
            ->assertJsonPath('data.group_link', null);
        $this->getJson('/api/v1/user/comm/config')->assertJsonPath('data.telegram_discuss_link', null);
        foreach (['enabled', 'bot_username', 'remind_expiring', 'webhook_url'] as $field) {
            $this->assertSame($before[$field], $config->fresh()->toArray()[$field]);
        }
        $this->assertNotNull($binding->fresh());
        $this->assertSame('https://t.me/old_group', Setting::where('name', 'telegram_discuss_link')->first()->value);
        Http::assertNothingSent();
    }

    public function test_invalid_group_links_do_not_replace_a_saved_link(): void
    {
        Sanctum::actingAs($this->user(['is_admin' => true]));
        TelegramBotConfig::whereKey(1)->update(['group_link' => 'https://t.me/group']);
        foreach (['javascript:alert(1)', 'ftp://example.test', 't.me/group', 'https://', ['https://t.me/group'],
            'https://t.me/' . str_repeat('a', 2048)] as $invalid) {
            $this->postJson($this->groupPath(), $this->groupSettings(['group_link' => $invalid]))->assertUnprocessable();
            $this->assertSame('https://t.me/group', TelegramBotConfig::findOrFail(1)->group_link);
        }
        Http::assertNothingSent();
    }

    public function test_group_link_settings_require_admin_permission(): void
    {
        $body = $this->groupSettings(['group_link' => 'https://t.me/group']);
        $this->postJson($this->groupPath(), $body)->assertForbidden();
        foreach ([[], ['is_staff' => true]] as $attributes) {
            Sanctum::actingAs($this->user($attributes));
            $this->postJson($this->groupPath(), $body)->assertForbidden();
        }
        $this->assertNull(TelegramBotConfig::findOrFail(1)->group_link);
    }
}
