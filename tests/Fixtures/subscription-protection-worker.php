<?php

// 仅供本地并发回归：独立文件缓存和 SQLite，不连接外部数据库。
require dirname(__DIR__, 2) . '/vendor/autoload.php';
$app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
$directory = $argv[1];
$app->afterBootstrapping(\Illuminate\Foundation\Bootstrap\LoadConfiguration::class, function ($app) use ($directory) {
    $app['config']->set('cache.default', 'array');
    $app['config']->set('cache.stores.redis', ['driver' => 'file', 'path' => $directory]);
    $app['config']->set('database.default', 'sqlite');
    $app['config']->set('database.connections.sqlite.database', $directory . '/test.sqlite');
});
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$app['cache']->forgetDriver('redis');
$app->forgetInstance(\App\Support\Setting::class);
if (($argv[2] ?? '') === '--prepare') {
    \Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
    \App\Models\User::create(['email' => 'parallel@example.invalid', 'password' => \Illuminate\Support\Str::random(32),
        'uuid' => (string) \Illuminate\Support\Str::uuid(), 'token' => \Illuminate\Support\Str::random(32), 'transfer_enable' => 1024]);
    exit(0);
}
$userMode = \App\Services\SubscriptionProtection::settings()['user_rate_limit_enabled'];
$request = \Illuminate\Http\Request::create('/_test/subscription', 'GET', $userMode ? ['token' => \App\Models\User::first()->token] : [], [], [],
    ['REMOTE_ADDR' => $userMode ? '2001:db8::' . dechex(getmypid()) : '198.51.100.75']);
for ($index = 0; $index < 4; $index++) {
    $response = (new \App\Services\SubscriptionProtection())->authenticate($request);
    echo ($response instanceof \App\Models\User ? 200 : $response->getStatusCode()) . "\n";
}
