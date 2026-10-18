<?php

namespace Tests\Feature\Admin;

use App\Http\Controllers\V2\Admin\ConfigController;
use App\Models\ServerMachine;
use App\Services\MachineAgentService;
use App\Services\RealityScanService;
use App\Support\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RealityScanSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Route::post('/_tests/config/save', [ConfigController::class, 'save']);
        Route::get('/_tests/config/fetch', [ConfigController::class, 'fetch']);
    }

    public function test_default_list_and_custom_presets_survive_reload(): void
    {
        $defaults = implode("\n", RealityScanService::DEFAULTS);
        $this->getJson('/_tests/config/fetch?key=server')->assertOk()
            ->assertJsonPath('data.server.reality_scan_targets', $defaults);
        $this->postJson('/_tests/config/save', ['reality_scan_targets' => " example.com:8443, example.net\nexample.com:8443 "])
            ->assertOk();
        $this->app->forgetInstance(Setting::class);
        $this->getJson('/_tests/config/fetch?key=server')->assertOk()
            ->assertJsonPath('data.server.reality_scan_targets', "example.com:8443\nexample.net");
        $this->assertSame(['example.com:8443', 'example.net'], RealityScanService::targets(null));
        $this->assertSame(['example.com:8443', 'example.net'], RealityScanService::targets(''));
        $this->assertSame(['explicit.example:443'], RealityScanService::targets('explicit.example:443'));
        $this->postJson('/_tests/config/save', ['server_pull_interval' => 30])->assertOk();
        $this->assertSame(['example.com:8443', 'example.net'], RealityScanService::targets(null));
    }

    public function test_clearing_presets_restores_defaults(): void
    {
        foreach (['', null, " \n "] as $empty) {
            admin_setting(['reality_scan_targets' => 'example.com:8443']);
            $this->postJson('/_tests/config/save', ['reality_scan_targets' => $empty])->assertOk();
            $this->assertSame(RealityScanService::DEFAULTS, RealityScanService::targets(null));
        }
    }

    public function test_invalid_presets_do_not_replace_saved_settings(): void
    {
        admin_setting(['reality_scan_targets' => 'example.com:8443']);
        foreach (['https://example.net', 'example.net:65536', '8.8.0.0/16',
            '8.8.8.0/24,9.9.9.0/24,1.1.1.1', implode("\n", array_fill(0, 65, 'example.net')),
            str_repeat('a', 8193), ['example.net']] as $invalid) {
            $this->postJson('/_tests/config/save', ['reality_scan_targets' => $invalid])->assertUnprocessable()
                ->assertJsonValidationErrors('reality_scan_targets');
            $this->assertSame(['example.com:8443'], RealityScanService::targets(null));
        }
    }

    public function test_updating_presets_keeps_running_task_and_applies_to_next_task(): void
    {
        $machine = ServerMachine::create(['name' => '预设测试服务器', 'token' => 'preset-test-only', 'is_active' => true]);
        $report = ['version' => 'v2.12.0', 'boot_id' => 'preset-test-process', 'manageable' => true, 'reality_scan_supported' => true];
        MachineAgentService::exchange($machine, $report, null);
        $machine = $machine->fresh();
        $task = RealityScanService::start($machine, null);
        $this->postJson('/_tests/config/save', ['reality_scan_targets' => 'example.com:8443'])->assertOk();
        $this->assertSame($task, RealityScanService::view($machine->id));
        try {
            RealityScanService::start($machine, null);
            $this->fail('在途任务不能被新预设覆盖');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('targets', $e->errors());
        }
        RealityScanService::exchange($machine, $report + ['reality_scan' => ['id' => $task['id'], 'status' => 'succeeded', 'results' => []]]);
        $next = RealityScanService::start($machine, null);
        $this->assertSame(['example.com:8443'], $next['targets']);
        $this->assertSame($next['id'], RealityScanService::start($machine, '')['id']);
    }
}
