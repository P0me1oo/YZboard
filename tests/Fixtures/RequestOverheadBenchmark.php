<?php

namespace Tests\Fixtures;

use App\Console\Kernel;
use App\Models\Plugin;
use App\Models\Server;
use App\Models\User;
use App\Services\DeviceCoordinationStateReader;
use App\Services\DeviceHandoverService;
use App\Services\DeviceStateService;
use App\Services\Plugin\AbstractPlugin;
use App\Services\Plugin\HookManager;
use App\Services\Plugin\PluginManager;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Laravel\Octane\CurrentApplication;
use Tests\Support\DeviceRedisCache;
use Tests\TestCase;

require_once __DIR__ . '/../Feature/PluginInitializationTest.php';

/** 显式执行的局部基准；默认完整回归不发现此文件。 */
class RequestOverheadBenchmark extends TestCase
{
    use RefreshDatabase;

    private function baseline(): bool { return getenv('YZ_BENCH_BASELINE') === '1'; }
    private function cpu(): float
    {
        $r = getrusage();
        return ($r['ru_utime.tv_sec'] + $r['ru_stime.tv_sec']) * 1000
            + ($r['ru_utime.tv_usec'] + $r['ru_stime.tv_usec']) / 1000;
    }

    private function report(string $name, float $start, float $cpu, array $details): void
    {
        fwrite(STDERR, 'REQUEST_BENCHMARK ' . json_encode(['name' => $name, 'baseline' => $this->baseline(),
            'milliseconds' => (hrtime(true) - $start) / 1e6, 'php_cpu_ms' => $this->cpu() - $cpu] + $details) . "\n");
    }

    public function test_plugin_initialization_benchmark(): void
    {
        $directory = sys_get_temp_dir() . '/yz-plugin-benchmark-' . bin2hex(random_bytes(8));
        File::makeDirectory($directory . '/routes', 0700, true);
        File::put($directory . '/routes/api.php', '<?php \\Illuminate\\Support\\Facades\\Route::get("/_benchmark/plugin", fn () => "ok");');
        Plugin::create(['code' => 'initialization_fixture', 'name' => 'Benchmark fixture',
            'version' => '1.0.0', 'type' => 'feature', 'is_enabled' => true]);
        $base = $this->app;
        $booted = 0;
        $cpu = $this->cpu();
        $start = hrtime(true);
        try {
            foreach (range(1, 200) as $iteration) {
                $sandbox = clone $base;
                CurrentApplication::set($sandbox);
                $sandbox->instance('request', Request::create('/_benchmark/plugin'));
                $kernel = $this->baseline() ? new class($sandbox, $sandbox['events']) extends Kernel {
                    public function registerPluginCommand(\Symfony\Component\Console\Command\Command $command): void
                    {
                        $this->registerCommand($command);
                    }
                } : new Kernel($sandbox, $sandbox['events']);
                $sandbox->instance(ConsoleKernel::class, $kernel);
                HookManager::reset();
                $plugins = new class($directory) extends PluginManager {
                    public function __construct(private readonly string $directory) { parent::__construct(); }
                    public function getPluginPath(string $pluginCode): string { return $this->directory; }
                    protected function loadPlugin(string $pluginCode): ?AbstractPlugin
                    {
                        return $this->loadedPlugins[$pluginCode] ??= new \Tests\Feature\InitializationPlugin($pluginCode);
                    }
                };
                $plugins->initializeEnabledPlugins();
                $booted += (int) ((new \ReflectionProperty(\Illuminate\Foundation\Console\Kernel::class, 'artisan'))->getValue($kernel) !== null);
                CurrentApplication::set($base);
                unset($plugins, $kernel, $sandbox);
            }
            $this->report('plugin_initialization', $start, $cpu, ['requests' => 200, 'console_boots' => $booted]);
            $this->assertSame($this->baseline() ? 200 : 0, $booted);
        } finally {
            CurrentApplication::set($base);
            File::deleteDirectory($directory);
        }
    }

    public function test_sparse_device_sync_benchmark(): void
    {
        $port = getenv('YZ_TEST_DEVICE_REDIS_PORT');
        if (!$port) $this->markTestSkipped('需要专用本机 Redis');
        $redis = new DeviceRedisCache($this->app, (int) $port);
        $redis->install($this->app);
        $this->travelTo(now()->startOfSecond());
        $this->mock(DeviceStateService::class, fn ($mock) => $mock->shouldReceive('getDeviceSources')->andReturn([]));
        if ($this->baseline() && class_exists(DeviceCoordinationStateReader::class)) {
            $this->app->instance(DeviceCoordinationStateReader::class, new class extends DeviceCoordinationStateReader {
                public function get(string $key): ?array { return Cache::get($key); }
            });
        }
        $run = fn ($id) => str_pad(dechex($id), 32, '0', STR_PAD_LEFT);
        $nodes = $users = $reports = [];
        $accountBytes = $nodeBytes = 0;
        try {
            foreach (range(1, 64) as $id) {
                $nodes[] = Server::create(['name' => '稀疏快照基准', 'type' => 'vmess', 'host' => 'test.invalid',
                    'port' => 443, 'server_port' => 443, 'rate' => 1, 'group_ids' => [1], 'enabled' => true]);
            }
            foreach (range(1, 47) as $index) {
                $user = User::create(['email' => 'sparse-' . $index . '@example.invalid', 'password' => 'test-only',
                    'uuid' => sprintf('00000000-0000-0000-0000-%012d', $index), 'token' => hash('md5', 'sparse-' . $index),
                    'group_id' => 1, 'device_limit' => 30, 'transfer_enable' => 100000, 'u' => 0, 'd' => 0, 'banned' => 0,
                    'expired_at' => time() + 86400]);
                $users[] = $user->id;
                $sources = [];
                foreach (range(1, $index === 16 ? 23 : 1 + $index % 5) as $slot) {
                    $lease = substr(hash('sha256', $index . ':' . $slot), 0, 32);
                    $ip = '198.51.100.' . $slot;
                    $owner = $nodes[1 + ($index + $slot) % 63];
                    $holders = [];
                    foreach (array_slice($nodes, 0, 55) as $node) {
                        $holders[$node->id . ':' . $run($node->id)] = ['node_id' => $node->id, 'run' => $run($node->id),
                            'sequence' => 1, 'confirmed_at' => now()->getTimestampMs(), 'expires_at' => now()->addHour()->getTimestampMs()];
                    }
                    $sources[$ip] = ['user_id' => $user->id, 'ip' => $ip, 'lease' => $lease, 'revoked' => false,
                        'owners' => [$owner->id => ['run' => $run($owner->id), 'sequence' => 1, 'connect_sequence' => 1,
                            'last_new_at' => now()->getTimestampMs()]], 'holders' => $holders];
                    $reports[$owner->id][$lease] = ['user_id' => $user->id, 'ip' => $ip, 'lease' => $lease,
                        'connect_sequence' => 1, 'age_ms' => 0, 'last_new_at' => now()->getTimestampMs()];
                }
                $state = ['sources' => $sources, 'cooldown' => [], 'pending' => null];
                $accountBytes += strlen(serialize($state));
                Cache::forever('device_handover:user:' . $user->id, $state);
            }
            foreach ($nodes as $node) {
                $key = 'device_handover:node:' . $node->id;
                $snapshot = ['run' => $run($node->id), 'sequence' => 1, 'pending' => [], 'sources' => $reports[$node->id] ?? [],
                    'version' => 1, 'seen_at' => now()->getTimestampMs(), 'lease_cache' => true];
                $nodeBytes += strlen(serialize($snapshot));
                Cache::forever($key, $snapshot);
                Cache::forever($key . ':progress', ['run' => $run($node->id), 'sequence' => 1, 'base_sequence' => 1, 'seen_at' => now()->getTimestampMs()]);
                Cache::forever('device_handover:queue:' . $node->id, $users);
            }
            $service = app(DeviceHandoverService::class);
            $sync = fn ($sequence) => $service->sync($nodes[0]->id, ['run' => $run($nodes[0]->id), 'lease_cache' => 1,
                'sequence' => $sequence, 'unchanged' => true, 'base_sequence' => 1]);
            $sync(2);
            $connection = Cache::getStore()->connection();
            $before = $connection->info('stats');
            $cpu = $this->cpu();
            $start = hrtime(true);
            foreach (range(3, 22) as $sequence) $reply = $sync($sequence);
            $this->report('sparse_device_sync', $start, $cpu, ['rounds' => 20, 'users' => 47, 'nodes' => 64,
                'account_bytes' => $accountBytes, 'node_bytes' => $nodeBytes,
                'redis_output_bytes' => $connection->info('stats')['total_net_output_bytes'] - $before['total_net_output_bytes']]);
            $this->assertNotEmpty($reply['idle']);
        } finally {
            $redis->close();
        }
    }
}
