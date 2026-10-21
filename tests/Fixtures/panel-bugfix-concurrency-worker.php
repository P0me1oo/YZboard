<?php

require __DIR__ . '/../bootstrap-mariadb.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$app['config']->set('cache.default', 'array');
$app['config']->set('cache.stores.redis', ['driver' => 'array']);
$app['cache']->forgetDriver('redis');
\Illuminate\Support\Facades\Queue::fake();
\Illuminate\Support\Facades\Redis::shouldReceive('publish')->andReturn(1);
[$script, $mode, $directory, $name, $userId, $nodeId] = $argv;
$user = \App\Models\User::findOrFail((int) $userId);
$node = $mode === 'limits' ? \App\Models\Server::findOrFail((int) $nodeId) : null;
$wait = static function (string $path): void {
    $deadline = microtime(true) + 20;
    while (!is_file($path)) {
        if (microtime(true) > $deadline) throw new \RuntimeException('并发屏障超时');
        usleep(10000);
        clearstatcache(true, $path);
    }
};
file_put_contents($directory . '/' . $name . '.ready', 'ready');
$wait($directory . '/start');
if ($mode === 'recovery') {
    echo app(\App\Services\Auth\TotpService::class)->verifyCode($user, 'AAAAA-BBBBB') ? '1' : '0';
} elseif ($mode === 'reset') {
    echo app(\App\Services\TrafficResetService::class)->checkAndReset($user) ? '1' : '0';
} else {
    \App\Services\Plugin\HookManager::register('server.limit.exceeded', function () use ($directory, $name): void {
        file_put_contents($directory . '/' . $name . '.notified', '1');
    });
    \App\Services\ServerService::processLimitEvents($node, [[
        'user_id' => $user->id, 'kind' => 'conn', 'limit' => 3, 'observed' => 3, 'count' => 5,
    ]], 'test-concurrent-limits');
    echo 'ok';
}
