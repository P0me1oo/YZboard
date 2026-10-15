<?php

// 仅供本地并发回归：不创建用户、不连接 Redis 或外部数据库。
require dirname(__DIR__, 2) . '/vendor/autoload.php';
$app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
$directory = $argv[1];
$app->afterBootstrapping(\Illuminate\Foundation\Bootstrap\LoadConfiguration::class, function ($app) use ($directory) {
    $app['config']->set('cache.default', 'array');
    $app['config']->set('cache.stores.redis', ['driver' => 'file', 'path' => $directory]);
    $app['config']->set('database.default', 'sqlite');
    $app['config']->set('database.connections.sqlite.database', ':memory:');
});
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$app['cache']->forgetDriver('redis');
$app->forgetInstance(\App\Support\Setting::class);
$request = \Illuminate\Http\Request::create('/_test/subscription', 'GET', [], [], [], ['REMOTE_ADDR' => '198.51.100.75']);
for ($index = 0; $index < 4; $index++) {
    $response = (new \App\Services\SubscriptionProtection())->authenticate($request);
    echo $response->getStatusCode() . "\n";
}
