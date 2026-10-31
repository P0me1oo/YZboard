<?php

namespace Tests\Feature\Admin;

use App\Http\Controllers\V2\Admin\ConfigController;
use App\Support\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ServerAutoSortSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Route::get('/_tests/auto-sort/config', [ConfigController::class, 'fetch']);
        Route::post('/_tests/auto-sort/config', [ConfigController::class, 'save']);
    }

    public function test_sorting_defaults_to_disabled_and_survives_settings_reload(): void
    {
        $this->getJson('/_tests/auto-sort/config?key=server')->assertOk()
            ->assertJsonPath('data.server.server_auto_sort_enable', false);
        foreach ([true, false, true] as $enabled) {
            $this->postJson('/_tests/auto-sort/config', ['server_auto_sort_enable' => $enabled])->assertOk();
            $this->app->forgetInstance(Setting::class);
            $this->getJson('/_tests/auto-sort/config?key=server')->assertOk()
                ->assertJsonPath('data.server.server_auto_sort_enable', $enabled);
        }
        $this->postJson('/_tests/auto-sort/config', ['server_pull_interval' => 45])->assertOk();
        $this->getJson('/_tests/auto-sort/config?key=server')->assertOk()
            ->assertJsonPath('data.server.server_auto_sort_enable', true);
    }

    public function test_invalid_switch_values_do_not_replace_the_saved_setting(): void
    {
        admin_setting(['server_auto_sort_enable' => true]);
        foreach ([null, '', 'enabled', 2, []] as $value) {
            $this->postJson('/_tests/auto-sort/config', ['server_auto_sort_enable' => $value])
                ->assertUnprocessable()->assertJsonValidationErrors('server_auto_sort_enable');
            $this->assertSame(1, (int) admin_setting('server_auto_sort_enable'));
        }
    }
}
