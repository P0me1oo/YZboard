<?php

namespace Tests\Feature;

use App\Console\Kernel;
use App\Models\Plugin;
use App\Services\Plugin\AbstractPlugin;
use App\Services\Plugin\HookManager;
use App\Services\Plugin\PluginManager;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class InitializationCommand extends Command
{
    protected $signature = 'test:plugin-initialization';
    public function handle(): int
    {
        $this->line('plugin-command-ok');
        return 0;
    }
}

class InitializationPlugin extends AbstractPlugin
{
    public function registerCommands(): void
    {
        $this->registerCommand(InitializationCommand::class);
    }

    public function boot(): void
    {
        $tenant = request()->header('X-Test-Tenant');
        $this->filter('test.plugin.tenant', fn () => $tenant);
    }
}

class PluginInitializationTest extends TestCase
{
    use RefreshDatabase;

    private Kernel $kernel;
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/yz-plugin-initialization-' . bin2hex(random_bytes(8));
        File::makeDirectory($this->directory . '/routes', 0700, true);
        File::put($this->directory . '/routes/api.php', <<<'PHP'
<?php
$tenant = request()->header('X-Test-Tenant');
\Illuminate\Support\Facades\Route::get('/_test/plugin-scope', fn () => $tenant);
PHP);
        Plugin::create(['code' => 'initialization_fixture', 'name' => 'Initialization fixture',
            'version' => '1.0.0', 'type' => 'feature', 'is_enabled' => true]);
        $this->kernel = new Kernel($this->app, $this->app['events']);
        $this->app->instance(ConsoleKernel::class, $this->kernel);
        HookManager::reset();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    private function plugins(string $tenant): PluginManager
    {
        $this->app->instance('request', Request::create('/', 'GET', server: ['HTTP_X_TEST_TENANT' => $tenant]));
        return new class($this->directory) extends PluginManager {
            public function __construct(private readonly string $directory) { parent::__construct(); }
            public function getPluginPath(string $pluginCode): string { return $this->directory; }
            protected function loadPlugin(string $pluginCode): ?AbstractPlugin
            {
                return $this->loadedPlugins[$pluginCode] ??= new InitializationPlugin($pluginCode);
            }
        };
    }

    private function consoleApplication(): mixed
    {
        return (new \ReflectionProperty(\Illuminate\Foundation\Console\Kernel::class, 'artisan'))->getValue($this->kernel);
    }

    public function test_http_plugin_initialization_does_not_boot_the_console_application(): void
    {
        $plugins = $this->plugins('first');
        $plugins->initializeEnabledPlugins();
        $this->assertTrue($this->consoleApplication() === null);
        $route = Route::getRoutes()->match(Request::create('/_test/plugin-scope'));
        $this->assertSame('first', $route->run());
    }

    public function test_deferred_plugin_command_is_available_to_console_calls(): void
    {
        $this->plugins('first')->initializeEnabledPlugins();
        $output = new BufferedOutput();
        $this->assertSame(0, $this->kernel->call('test:plugin-initialization', [], $output));
        $this->assertStringContainsString('plugin-command-ok', $output->fetch());
        $this->assertNotNull($this->consoleApplication());
    }

    public function test_registration_after_console_start_keeps_last_registration_and_aliases(): void
    {
        $this->kernel->all();
        $first = new \Symfony\Component\Console\Command\Command('test:replacement');
        $first->setDescription('first');
        $second = new \Symfony\Component\Console\Command\Command('test:replacement');
        $second->setDescription('second')->setAliases(['test:replacement-alias']);
        $this->kernel->registerCommand($first);
        $this->kernel->registerCommand($second);
        $this->assertSame('second', $this->kernel->all()['test:replacement']->getDescription());
        $this->assertSame($second, $this->kernel->all()['test:replacement-alias']);
    }

    public function test_invalid_command_is_rejected_during_registration_not_a_later_console_call(): void
    {
        $this->expectException(\Symfony\Component\Console\Exception\LogicException::class);
        $this->kernel->registerPluginCommand(new \Symfony\Component\Console\Command\Command());
    }

    public function test_deferred_registration_preserves_order_aliases_and_repeated_calls(): void
    {
        $first = new \Symfony\Component\Console\Command\Command('test:replacement');
        $second = new \Symfony\Component\Console\Command\Command('test:replacement');
        $second->setAliases(['test:replacement-alias']);
        $this->kernel->registerPluginCommand($first);
        $this->kernel->registerPluginCommand($second);
        $this->assertTrue($this->consoleApplication() === null);
        $this->assertSame($second, $this->kernel->all()['test:replacement']);
        $this->assertSame($second, $this->kernel->all()['test:replacement-alias']);
        $this->assertSame($second, $this->kernel->all()['test:replacement']);
    }

    public function test_dynamic_plugin_routes_and_hooks_are_still_request_scoped(): void
    {
        $this->plugins('first')->initializeEnabledPlugins();
        $first = Route::getRoutes()->match(Request::create('/_test/plugin-scope'));
        $this->assertSame('first', $first->run());
        $this->assertSame('first', HookManager::filter('test.plugin.tenant', null));
        HookManager::reset();
        $this->plugins('second')->initializeEnabledPlugins();
        $second = Route::getRoutes()->match(Request::create('/_test/plugin-scope'));
        $this->assertSame('second', $second->run());
        $this->assertSame('second', HookManager::filter('test.plugin.tenant', null));
        Plugin::where('code', 'initialization_fixture')->update(['is_enabled' => false]);
        HookManager::reset();
        $this->plugins('disabled')->initializeEnabledPlugins();
        $this->assertNull(HookManager::filter('test.plugin.tenant', null));
    }
}
