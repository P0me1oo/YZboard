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
use Symfony\Component\Console\Input\InputDefinition;
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

class InitializationInvalidDefinitionCommand extends \Symfony\Component\Console\Command\Command
{
    public function getDefinition(): InputDefinition
    {
        throw new \Symfony\Component\Console\Exception\LogicException('plugin-invalid-definition-test');
    }
}

class InitializationEnablementCommand extends Command
{
    protected $signature = 'test:plugin-enablement';
    public function isEnabled(): bool
    {
        if ($this->getLaravel() === null || $this->getApplication() === null) {
            throw new \RuntimeException('plugin-command-context-missing');
        }
        return false;
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

    public function test_invalid_named_definition_is_rejected_during_registration(): void
    {
        $this->expectException(\Symfony\Component\Console\Exception\LogicException::class);
        $this->expectExceptionMessage('plugin-invalid-definition-test');
        $this->kernel->registerPluginCommand(new InitializationInvalidDefinitionCommand('test:invalid-definition'));
    }

    public function test_invalid_named_command_cannot_poison_other_plugin_commands(): void
    {
        $this->kernel->registerPluginCommand(new \Symfony\Component\Console\Command\Command('test:valid-before'));
        try {
            $this->kernel->registerPluginCommand(new InitializationInvalidDefinitionCommand('test:invalid-definition'));
            $this->fail('无效命令应在插件注册时拒绝');
        } catch (\Symfony\Component\Console\Exception\LogicException $error) {
            $this->assertSame('plugin-invalid-definition-test', $error->getMessage());
        }
        $this->kernel->registerPluginCommand(new \Symfony\Component\Console\Command\Command('test:valid-after'));
        $commands = $this->kernel->all();
        $this->assertArrayHasKey('test:valid-before', $commands);
        $this->assertArrayHasKey('test:valid-after', $commands);
        $this->assertArrayNotHasKey('test:invalid-definition', $commands);
    }

    public function test_custom_enablement_uses_original_registration_context(): void
    {
        $this->kernel->registerPluginCommand(new InitializationEnablementCommand());
        $this->assertNotNull($this->consoleApplication());
        $this->assertArrayNotHasKey('test:plugin-enablement', $this->kernel->all());
    }

    public function test_console_assembly_failure_keeps_later_commands_for_retry(): void
    {
        foreach (['before', 'failure', 'after'] as $name) {
            $this->kernel->registerPluginCommand(new \Symfony\Component\Console\Command\Command('test:assembly-' . $name));
        }
        $application = $this->createMock(\Illuminate\Console\Application::class);
        $seen = [];
        $application->method('add')->willReturnCallback(function ($command) use (&$seen) {
            $seen[] = $command->getName();
            if ($command->getName() === 'test:assembly-failure') throw new \RuntimeException('plugin-assembly-test-failure');
            return $command;
        });
        $this->kernel->setArtisan($application);
        $getArtisan = new \ReflectionMethod(Kernel::class, 'getArtisan');
        try {
            $getArtisan->invoke($this->kernel);
            $this->fail('注入的装配故障应传播');
        } catch (\RuntimeException $error) {
            $this->assertSame('plugin-assembly-test-failure', $error->getMessage());
        }
        $getArtisan->invoke($this->kernel);
        $this->assertSame(['test:assembly-before', 'test:assembly-failure', 'test:assembly-after'], $seen);
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
