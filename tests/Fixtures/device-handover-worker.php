<?php

use App\Services\DeviceHandoverService;
use App\Services\DeviceStateService;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

[$script, $directory, $index, $mode] = $argv;
$cached = str_starts_with($mode, 'cached-');
if ($cached) $mode = substr($mode, 7);
$app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$app['config']->set([
    'database.default' => 'sqlite', 'database.connections.sqlite.database' => $directory . '/database.sqlite',
    'cache.default' => 'file', 'cache.stores.file.path' => $directory . '/cache',
    'cache.stores.file.lock_path' => $directory . '/locks',
]);
$app['db']->purge('sqlite');
$app['cache']->forgetDriver('file');
$devices = new class extends DeviceStateService {
    public function getDeviceSources(int $userId): array { return []; }
};
$service = new DeviceHandoverService($devices);
touch($directory . '/' . $index . '.ready');
$deadline = microtime(true) + 20;
while (!is_file($directory . '/start')) {
    clearstatcache();
    if (microtime(true) >= $deadline) throw new RuntimeException('设备并发屏障超时');
    usleep(10000);
}
$run = str_repeat('a', 32);
$sequence = (int) $index + 1;
$reply = $service->admit(1, $mode === 'users' ? $sequence : 10, 1, [
    'run' => $run, 'sequence' => $sequence, 'ip' => $mode === 'same' ? '8.8.8.8' : '8.8.8.' . (10 + $sequence),
    'snapshot' => ['run' => $run, 'sequence' => 20 + $sequence, 'pending' => range(1, 8), 'sources' => [], 'retired' => []],
]+ ($cached ? ['lease_cache' => 1] : []));
echo json_encode($reply);
