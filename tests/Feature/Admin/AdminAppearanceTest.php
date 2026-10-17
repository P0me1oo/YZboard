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
            ->assertJsonPath('data', ['appearance' => ['admin_sidebar_hidden' => []]]);
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
}
