<?php

namespace Tests\Feature\Admin;

use App\Http\Controllers\V2\Admin\ConfigController;
use App\Support\AdminAppearance;
use App\Support\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AdminAppearanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Route::post('/_tests/appearance/save', [ConfigController::class, 'save']);
        Route::get('/_tests/appearance/fetch', [ConfigController::class, 'fetch']);
    }

    public function test_default_save_reload_repeat_and_restore(): void
    {
        $this->getJson('/_tests/appearance/fetch?key=appearance')->assertOk()
            ->assertJsonPath('data', ['appearance' => ['admin_sidebar_hidden' => [], 'admin_sidebar_order' => []]]);
        admin_setting(['subscribe_path' => 'test-subscription-path']);
        $hidden = ['subscription-management', 'plan-management', 'subscription-client-control', 'subscription-statistics'];
        for ($i = 0; $i < 2; $i++) {
            $this->postJson('/_tests/appearance/save', ['admin_sidebar_hidden' => $hidden])->assertOk()->assertJsonPath('data', true);
        }
        $this->app->forgetInstance(Setting::class);
        $this->getJson('/_tests/appearance/fetch?key=appearance')->assertOk()->assertJsonPath('data.appearance.admin_sidebar_hidden', $hidden);
        $this->postJson('/_tests/appearance/save', ['app_name' => 'Appearance test'])->assertOk();
        $this->assertSame($hidden, AdminAppearance::hidden());
        $this->assertSame('test-subscription-path', admin_setting('subscribe_path'));
        $this->postJson('/_tests/appearance/save', ['admin_sidebar_hidden' => []])->assertOk();
        $this->assertSame([], AdminAppearance::hidden());
    }

    public function test_invalid_values_and_protected_entries_cannot_overwrite_settings(): void
    {
        admin_setting(['admin_sidebar_hidden' => ['plan-management']]);
        foreach ([null, 'plan-management', ['unknown'], ['system-config'], ['system-management'],
            ['plan-management', 'plan-management'], ['key' => 'dashboard'], [false], [['dashboard']]] as $value) {
            $this->postJson('/_tests/appearance/save', ['admin_sidebar_hidden' => $value])->assertUnprocessable();
            $this->assertSame(['plan-management'], AdminAppearance::hidden());
        }
    }

    public function test_legacy_invalid_values_are_ignored_on_read(): void
    {
        admin_setting(['admin_sidebar_hidden' => ['system-config', 'unknown', 'dashboard', 'dashboard', null]]);
        $this->assertSame(['dashboard'], AdminAppearance::hidden());
        admin_setting(['admin_sidebar_hidden' => 'invalid']);
        $this->assertSame([], AdminAppearance::hidden());
    }

    public function test_all_supported_entries_can_be_hidden_and_restored(): void
    {
        $this->postJson('/_tests/appearance/save', ['admin_sidebar_hidden' => AdminAppearance::ITEMS])->assertOk();
        $this->app->forgetInstance(Setting::class);
        $this->assertSame(AdminAppearance::ITEMS, AdminAppearance::hidden());
        $this->postJson('/_tests/appearance/save', ['admin_sidebar_hidden' => []])->assertOk();
        $this->assertSame([], AdminAppearance::hidden());
    }

    public function test_order_persists_independently_and_can_be_restored(): void
    {
        $order = ['user-management', 'ticket-management', 'user-manage', 'system-management', 'system-config'];
        admin_setting(['admin_sidebar_hidden' => ['access-logs']]);
        for ($i = 0; $i < 2; $i++) {
            $this->postJson('/_tests/appearance/save', ['admin_sidebar_order' => $order])->assertOk()->assertJsonPath('data', true);
        }
        $this->app->forgetInstance(Setting::class);
        $this->getJson('/_tests/appearance/fetch?key=appearance')->assertOk()
            ->assertJsonPath('data.appearance.admin_sidebar_order', $order)
            ->assertJsonPath('data.appearance.admin_sidebar_hidden', ['access-logs']);
        $this->postJson('/_tests/appearance/save', ['admin_sidebar_hidden' => []])->assertOk();
        $this->assertSame($order, AdminAppearance::order());
        $this->postJson('/_tests/appearance/save', ['admin_sidebar_hidden' => ['access-logs']])->assertOk();
        $this->postJson('/_tests/appearance/save', ['admin_sidebar_order' => []])->assertOk();
        $this->assertSame([], AdminAppearance::order());
        $this->assertSame(['access-logs'], AdminAppearance::hidden());
        $this->postJson('/_tests/appearance/save', ['admin_sidebar_order' => AdminAppearance::sortableItems()])->assertOk();
        $this->assertSame(AdminAppearance::sortableItems(), AdminAppearance::order());
    }

    public function test_invalid_order_does_not_overwrite_either_setting(): void
    {
        admin_setting(['admin_sidebar_order' => ['dashboard'], 'admin_sidebar_hidden' => ['access-logs']]);
        foreach ([null, 'dashboard', ['unknown'], ['dashboard', 'dashboard'], ['key' => 'dashboard'], [false], [['dashboard']]] as $value) {
            $this->postJson('/_tests/appearance/save', ['admin_sidebar_order' => $value, 'admin_sidebar_hidden' => []])->assertUnprocessable();
            $this->assertSame(['dashboard'], AdminAppearance::order());
            $this->assertSame(['access-logs'], AdminAppearance::hidden());
        }
    }

    public function test_invalid_stored_order_is_sanitized(): void
    {
        admin_setting(['admin_sidebar_order' => ['system-config', 'unknown', 'dashboard', 'dashboard', null]]);
        $this->assertSame(['system-config', 'dashboard'], AdminAppearance::order());
        admin_setting(['admin_sidebar_order' => 'invalid']);
        $this->assertSame([], AdminAppearance::order());
    }

    public function test_admin_page_bootstraps_current_sanitized_appearance_before_app_script(): void
    {
        admin_setting([
            'admin_sidebar_hidden' => ['plan-management', 'unknown', 'system-config'],
            'admin_sidebar_order' => ['subscription-management', 'plan-management', 'unknown', 'plan-management'],
        ]);
        $render = fn () => view('admin', ['title' => '测试面板', 'version' => 'test', 'logo' => '', 'secure_path' => 'test-admin'])->render();
        $html = $render();
        preg_match('/appearance:\s*(\{[^;]+?\})\s*,/', $html, $matches);
        $this->assertSame([
            'admin_sidebar_hidden' => ['plan-management'],
            'admin_sidebar_order' => ['subscription-management', 'plan-management'],
        ], json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR));
        $this->assertLessThan(strpos($html, '<script type="module"'), strpos($html, 'appearance:'));

        admin_setting(['admin_sidebar_hidden' => [], 'admin_sidebar_order' => []]);
        preg_match('/appearance:\s*(\{[^;]+?\})\s*,/', $render(), $matches);
        $this->assertSame(['admin_sidebar_hidden' => [], 'admin_sidebar_order' => []], json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR));
    }

    public function test_plan_and_subscription_groups_can_be_saved_independently(): void
    {
        $order = ['plan-management-group', 'gift-card-management', 'plan-management', 'subscription-management', 'subscription-template'];
        foreach ([['plan-management-group'], ['subscription-management'], ['plan-management', 'coupon-management'], []] as $hidden) {
            $this->postJson('/_tests/appearance/save', ['admin_sidebar_hidden' => $hidden, 'admin_sidebar_order' => $order])
                ->assertOk()->assertJsonPath('data', true);
            $this->app->forgetInstance(Setting::class);
            $this->getJson('/_tests/appearance/fetch?key=appearance')->assertOk()
                ->assertJsonPath('data.appearance.admin_sidebar_hidden', $hidden)
                ->assertJsonPath('data.appearance.admin_sidebar_order', $order);
        }
    }
}
