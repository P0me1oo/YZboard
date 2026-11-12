<?php

namespace Tests\Feature;

use App\Http\Controllers\V2\Admin\PluginController;
use App\Models\Plugin;
use App\Services\Plugin\PluginManager;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PluginStatePreservationTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;
    private PluginManager $plugins;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/yz-plugin-state-' . bin2hex(random_bytes(8));
        File::makeDirectory($this->directory . '/plugins', 0700, true);
        $this->plugins = $this->newManager();
        $this->app->instance(PluginManager::class, $this->plugins);
    }

    protected function tearDown(): void
    {
        try {
            File::deleteDirectory($this->directory);
        } finally {
            parent::tearDown();
        }
    }

    private function newManager(): PluginManager
    {
        return new class($this->directory) extends PluginManager {
            public function __construct(string $directory)
            {
                $this->pluginPath = $directory . '/plugins';
                $this->corePluginPath = $directory . '/plugins-core';
            }
        };
    }

    private function installedPlugin(bool $enabled = true): Plugin
    {
        $code = 'state_fixture_' . bin2hex(random_bytes(8));
        $path = $this->plugins->getPluginPath($code);
        File::makeDirectory($path, 0700, true);
        File::put($path . '/config.json', json_encode([
            'code' => $code, 'name' => '状态保留测试', 'version' => '1.0.0',
            'type' => Plugin::TYPE_FEATURE, 'description' => '临时测试插件', 'author' => 'P0me1oo',
            'config' => ['message' => ['type' => 'text', 'default' => 'default-value']],
        ]));

        return Plugin::create([
            'code' => $code, 'name' => '状态保留测试', 'version' => '1.0.0',
            'type' => Plugin::TYPE_FEATURE, 'is_enabled' => $enabled,
            'installed_at' => '2026-01-01 00:00:00',
            'config' => json_encode(['message' => 'saved-custom-value']),
        ]);
    }

    private function writeEntry(Plugin $plugin, bool $valid = true): void
    {
        $namespace = $this->plugins->getPluginNamespace($plugin->code);
        $body = $valid ? <<<'PHP'
class Plugin extends \App\Services\Plugin\AbstractPlugin
{
    public bool $booted = false;
    public bool $cleanedUp = false;
    public function boot(): void { $this->booted = true; }
    public function cleanup(): void { $this->cleanedUp = true; }
    public function install(): void { throw new \LogicException('不应重新安装已有插件'); }
    public function schedule(\Illuminate\Console\Scheduling\Schedule $schedule): void
    {
        $schedule->call(static function (): void {})->name($this->getPluginCode());
    }
}
PHP
            : 'class WrongEntry {}';
        File::put($this->plugins->getPluginPath($plugin->code) . '/Plugin.php',
            "<?php\nnamespace {$namespace};\n{$body}\n");
    }

    public static function unavailableEntries(): array
    {
        return ['入口文件缺失' => [false], '入口类不存在' => [true]];
    }

    #[DataProvider('unavailableEntries')]
    public function test_initialization_keeps_record_config_and_installed_status(bool $wrongClass): void
    {
        $plugin = $this->installedPlugin();
        if ($wrongClass) {
            $this->writeEntry($plugin, false);
        }
        $before = $plugin->fresh()->getRawOriginal();
        $healthy = $this->installedPlugin();
        $this->writeEntry($healthy);

        $loaded = $this->plugins->getEnabledPlugins();

        $this->assertSame($before, $plugin->fresh()?->getRawOriginal());
        $this->assertArrayNotHasKey($plugin->code, $loaded);
        $this->assertTrue($loaded[$healthy->code]->booted);
        $response = $this->app->make(PluginController::class)->index(Request::create('/'));
        $listed = collect($response->getData(true)['data'])->firstWhere('code', $plugin->code);
        $this->assertTrue($listed['is_installed']);
        $this->assertTrue($listed['is_enabled']);
        $this->assertSame('saved-custom-value', $listed['config']['message']['value']);
    }

    #[DataProvider('unavailableEntries')]
    public function test_schedule_registration_keeps_unavailable_plugin_and_loads_other_schedules(bool $wrongClass): void
    {
        $plugin = $this->installedPlugin();
        if ($wrongClass) {
            $this->writeEntry($plugin, false);
        }
        $before = $plugin->fresh()->getRawOriginal();
        $healthy = $this->installedPlugin();
        $this->writeEntry($healthy);
        $schedule = new Schedule();

        $this->plugins->registerPluginSchedules($schedule);

        $this->assertSame($before, $plugin->fresh()?->getRawOriginal());
        $this->assertCount(1, $schedule->events());
        $this->assertSame($healthy->code, $schedule->events()[0]->description);
    }

    #[DataProvider('unavailableEntries')]
    public function test_failed_enable_preserves_disabled_record_and_config(bool $wrongClass): void
    {
        $plugin = $this->installedPlugin(false);
        if ($wrongClass) {
            $this->writeEntry($plugin, false);
        }
        $before = $plugin->fresh()->getRawOriginal();

        try {
            $this->plugins->enable($plugin->code);
            $this->fail('入口不可用时必须返回启用失败');
        } catch (\Exception $error) {
            $this->assertSame('Plugin not found: ' . $plugin->code, $error->getMessage());
        }

        $this->assertSame($before, $plugin->fresh()?->getRawOriginal());
    }

    public function test_restored_file_loads_on_next_initialization_without_reinstallation(): void
    {
        $plugin = $this->installedPlugin();
        $before = $plugin->fresh()->getRawOriginal();
        $this->assertSame([], $this->plugins->getEnabledPlugins());

        $this->writeEntry($plugin);
        $loaded = $this->newManager()->getEnabledPlugins();

        $this->assertArrayHasKey($plugin->code, $loaded);
        $this->assertTrue($loaded[$plugin->code]->booted);
        $this->assertSame('saved-custom-value', $loaded[$plugin->code]->getConfig('message'));
        $this->assertSame($before, $plugin->fresh()?->getRawOriginal());
    }

    public function test_explicit_enable_disable_and_uninstall_still_work(): void
    {
        $plugin = $this->installedPlugin(false);
        $this->writeEntry($plugin);

        $this->assertTrue($this->plugins->enable($plugin->code));
        $this->assertTrue($plugin->fresh()->is_enabled);
        $instance = $this->plugins->getEnabledPlugins()[$plugin->code];
        $this->assertTrue($instance->booted);
        $this->assertSame('saved-custom-value', $instance->getConfig('message'));

        $this->assertTrue($this->plugins->disable($plugin->code));
        $this->assertFalse($plugin->fresh()->is_enabled);
        $this->assertTrue($instance->cleanedUp);
        $this->assertSame($plugin->config, $plugin->fresh()->config);
        $this->assertTrue($this->plugins->uninstall($plugin->code));
        $this->assertDatabaseMissing('v2_plugins', ['code' => $plugin->code]);
        $this->assertFileExists($this->plugins->getPluginPath($plugin->code) . '/Plugin.php');
    }
}
