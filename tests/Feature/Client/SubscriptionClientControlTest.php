<?php

namespace Tests\Feature\Client;

use App\Http\Controllers\V2\Admin\ConfigController;
use App\Models\ServerGroup;
use App\Models\User;
use App\Services\SubscriptionClientControl;
use App\Support\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SubscriptionClientControlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('v2board', []);
        Route::middleware('admin')->group(function (): void {
            Route::get('/_tests/client-control/config', [ConfigController::class, 'fetch']);
            Route::post('/_tests/client-control/config', [ConfigController::class, 'save']);
        });
    }

    private function user(array $attributes = []): User
    {
        return User::create($attributes + [
            'email' => Str::random(12) . '@example.invalid',
            'password' => bcrypt(Str::random(24)),
            'uuid' => (string) Str::uuid(),
            'token' => Str::random(32),
            'transfer_enable' => 1024,
            'u' => 0, 'd' => 0, 'banned' => false, 'expired_at' => null,
        ]);
    }

    private function configure(string $mode, array $overrides = []): array
    {
        $settings = array_replace(SubscriptionClientControl::defaults(), ['mode' => $mode], $overrides);
        admin_setting([SubscriptionClientControl::SETTING => $settings]);
        return $settings;
    }

    private function download(User $user, ?string $agent, bool $legacy = false, string $flag = 'general')
    {
        $url = $legacy
            ? '/api/v1/client/subscribe?' . http_build_query(['token' => $user->token, 'flag' => $flag])
            : '/s/' . $user->token . '?' . http_build_query(['flag' => $flag]);
        return $this->get($url, $agent === null ? [] : ['User-Agent' => $agent]);
    }

    public function test_defaults_are_disabled_and_include_editable_presets(): void
    {
        $this->actingAs($this->user(['is_admin' => true]), 'sanctum');
        $this->getJson('/_tests/client-control/config?key=subscribe')->assertOk()
            ->assertJsonPath('data.subscribe.subscribe_client_control', SubscriptionClientControl::defaults());
        $user = $this->user();
        $this->download($user, null)->assertOk();
        $this->download($user, 'Clash/1.0', true)->assertOk();
    }

    public static function clients(): array
    {
        return [
            'Clash' => ['Clash/1.0', true],
            'Mihomo' => ['mihomo/v1.19.0', true],
            'FlClash' => ['FlClash/0.8 mihomo/v1.19', true],
            'sing-box' => ['sing-box/1.12.0', true],
            '大小写' => ['SingBox/1.12', true],
            '其他软件' => ['OtherClient/2.0', false],
            '浏览器' => ['Mozilla/5.0', false],
            '空名称' => ['', false],
            '没有名称' => [null, false],
        ];
    }

    #[DataProvider('clients')]
    public function test_both_subscription_routes_apply_white_and_black_lists(?string $agent, bool $matched): void
    {
        $user = $this->user();
        foreach (['whitelist', 'blacklist'] as $mode) {
            $this->configure($mode);
            foreach ([false, true] as $legacy) {
                $allowed = $mode === 'whitelist' ? $matched : !$matched;
                $response = $this->download($user, $agent, $legacy);
                $response->assertStatus($allowed ? 200 : 403);
                if (!$allowed) {
                    $this->assertSame('该客户端暂时不在支持范围内，请联系管理员', $response->getContent());
                    $response->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
                    $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
                    $response->assertDontSee($user->uuid)->assertDontSee($user->token);
                }
            }
        }
    }

    public function test_output_flags_cannot_change_the_download_client_decision(): void
    {
        $user = $this->user();
        $this->configure('blacklist');
        foreach (['general', 'singbox', 'clash', 'other'] as $flag) {
            $this->download($user, 'Clash/1.0', false, $flag)->assertForbidden();
        }
        $this->configure('whitelist');
        $this->download($user, 'OtherClient/1.0', true, 'clash')->assertForbidden();
        $this->download($user, 'sing-box/1.0', false, 'general')->assertOk();
    }

    public function test_only_all_effective_groups_are_exempt_on_both_routes(): void
    {
        $first = ServerGroup::forceCreate(['name' => '普通组']);
        $exempt = ServerGroup::forceCreate(['name' => '豁免组']);
        $otherExempt = ServerGroup::forceCreate(['name' => '另一豁免组']);
        $user = $this->user();
        foreach (['whitelist' => 'OtherClient/1.0', 'blacklist' => 'Clash/1.0'] as $mode => $agent) {
            $this->configure($mode, ['exempt_group_ids' => [$exempt->id, $otherExempt->id]]);
            foreach ([false, true] as $legacy) {
                foreach ([[$first->id, $exempt->id], [$exempt->id, $first->id]] as $groups) {
                    $user->update(['group_id' => $exempt->id, 'group_ids' => $groups]);
                    $this->download($user, $agent, $legacy)->assertForbidden();
                }
                // 旧单权限组值不参与已有多权限组账号的判断。
                $user->update(['group_id' => $first->id, 'group_ids' => [$exempt->id, $otherExempt->id]]);
                $this->download($user, $agent, $legacy)->assertOk();
                $user->update(['group_ids' => [$exempt->id]]);
                $this->download($user, $agent, $legacy)->assertOk();
                $user->update(['group_id' => $exempt->id, 'group_ids' => [$first->id]]);
                $this->download($user, $agent, $legacy)->assertForbidden();
                $user->update(['group_ids' => null]);
                $this->download($user, $agent, $legacy)->assertOk();
                $user->update(['group_id' => $first->id]);
                $this->download($user, $agent, $legacy)->assertForbidden();
                foreach ([['group_id' => $exempt->id, 'group_ids' => []], ['group_id' => null, 'group_ids' => null]] as $attributes) {
                    $user->update($attributes);
                    $this->download($user, $agent, $legacy)->assertForbidden();
                }
                // 修改豁免名单后立即按新规则判断，重复调用结果一致。
                $user->update(['group_ids' => [$exempt->id, $otherExempt->id]]);
                $this->configure($mode, ['exempt_group_ids' => [$exempt->id]]);
                $this->download($user, $agent, $legacy)->assertForbidden();
                $this->download($user, $agent, $legacy)->assertForbidden();
                $this->configure($mode, ['exempt_group_ids' => []]);
                $this->download($user, $agent, $legacy)->assertForbidden();
                $this->configure($mode, ['exempt_group_ids' => [$exempt->id, $otherExempt->id]]);
                $this->download($user, $agent, $legacy)->assertOk();
            }
        }
    }

    public function test_partial_exemption_still_allows_clients_permitted_by_the_list(): void
    {
        $ordinary = ServerGroup::forceCreate(['name' => '普通组']);
        $exempt = ServerGroup::forceCreate(['name' => '豁免组']);
        $user = $this->user(['group_ids' => [$ordinary->id, $exempt->id]]);
        foreach (['whitelist' => 'Clash/1.0', 'blacklist' => 'OtherClient/1.0', 'off' => 'Clash/1.0'] as $mode => $agent) {
            $this->configure($mode, ['exempt_group_ids' => [$exempt->id]]);
            foreach ([false, true] as $legacy) {
                $this->download($user, $agent, $legacy)->assertOk();
            }
        }
    }

    public function test_exempt_users_still_need_a_valid_subscription(): void
    {
        $group = ServerGroup::forceCreate(['name' => '豁免组']);
        $this->configure('blacklist', ['exempt_group_ids' => [$group->id]]);
        foreach ([['banned' => true], ['expired_at' => time() - 10], ['transfer_enable' => 0]] as $attributes) {
            $user = $this->user($attributes + ['group_id' => $group->id]);
            $this->download($user, 'Clash/1.0')->assertForbidden();
        }
        $this->get('/api/v1/client/subscribe?flag=general', ['User-Agent' => 'Clash/1.0'])->assertForbidden();
    }

    public function test_custom_keywords_disabled_rules_and_empty_lists(): void
    {
        $user = $this->user();
        $this->configure('whitelist', ['clients' => [
            ['name' => '自定义软件', 'enabled' => true, 'keywords' => ['my-client', '客户端']],
            ['name' => 'Clash', 'enabled' => false, 'keywords' => ['clash']],
        ]]);
        $this->download($user, 'MY-CLIENT/2.0')->assertOk();
        $this->download($user, '测试客户端/1.0')->assertOk();
        $this->download($user, 'Clash/1.0')->assertForbidden();

        $this->configure('whitelist', ['clients' => []]);
        $this->download($user, 'Clash/1.0')->assertForbidden();
        $this->configure('blacklist', ['clients' => []]);
        $this->download($user, 'Clash/1.0')->assertOk();
    }

    public function test_save_is_normalized_repeatable_and_survives_reloading_settings(): void
    {
        $this->actingAs($this->user(['is_admin' => true]), 'sanctum');
        $group = ServerGroup::forceCreate(['name' => '豁免组']);
        $settings = [
            'mode' => 'blacklist',
            'clients' => [['name' => ' 自定义 ', 'enabled' => 1, 'keywords' => [' MY-CLIENT ', 'my-client', 'Other']]],
            'exempt_group_ids' => [(string) $group->id],
        ];
        foreach ([1, 2] as $attempt) {
            $this->postJson('/_tests/client-control/config', [SubscriptionClientControl::SETTING => $settings])->assertOk();
        }
        $expected = SubscriptionClientControl::normalize($settings);
        $this->app->forgetInstance(Setting::class);
        $this->getJson('/_tests/client-control/config?key=subscribe')->assertOk()
            ->assertJsonPath('data.subscribe.subscribe_client_control', $expected);
        $this->postJson('/_tests/client-control/config', ['show_info_to_server_enable' => false])->assertOk();
        $this->assertSame($expected, SubscriptionClientControl::settings());
        $user = $this->user();
        $this->download($user, 'my-client/1.0')->assertForbidden();
        $this->configure('off');
        $this->download($user, 'my-client/1.0')->assertOk();
    }

    public function test_invalid_configuration_never_overwrites_saved_rules(): void
    {
        $this->actingAs($this->user(['is_admin' => true]), 'sanctum');
        $saved = $this->configure('blacklist');
        $invalid = [
            null, [], ['mode' => 'off'],
            array_replace($saved, ['mode' => 'invalid']),
            array_replace($saved, ['clients' => null]),
            array_replace($saved, ['clients' => [['name' => 'Clash', 'enabled' => true, 'keywords' => [' ']]]]),
            array_replace($saved, ['clients' => [['name' => 'Clash', 'enabled' => true, 'keywords' => []]]]),
            array_replace($saved, ['clients' => [['name' => '', 'enabled' => true, 'keywords' => ['clash']]]]),
            array_replace($saved, ['clients' => [['name' => 'Clash', 'enabled' => 'bad', 'keywords' => ['clash']]]]),
            array_replace($saved, ['clients' => [['name' => 'Clash', 'keywords' => ['clash']]]]),
            array_replace($saved, ['clients' => array_fill(0, 65, $saved['clients'][0])]),
            array_replace($saved, ['exempt_group_ids' => [99999999]]),
            array_replace($saved, ['exempt_group_ids' => null]),
            $saved + ['unexpected' => true],
        ];
        foreach ($invalid as $settings) {
            $this->postJson('/_tests/client-control/config', [SubscriptionClientControl::SETTING => $settings])->assertUnprocessable();
            $this->assertSame($saved, SubscriptionClientControl::settings());
        }
    }

    public function test_only_administrators_can_fetch_or_save_rules(): void
    {
        $this->getJson('/_tests/client-control/config?key=subscribe')->assertForbidden();
        $this->actingAs($this->user(), 'sanctum');
        $this->postJson('/_tests/client-control/config', [SubscriptionClientControl::SETTING => SubscriptionClientControl::defaults()])
            ->assertForbidden();
    }
}
