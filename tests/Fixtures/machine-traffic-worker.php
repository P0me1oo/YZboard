<?php

// 只使用本机专用测试数据库，不读取部署连接。
require __DIR__ . '/../bootstrap-mariadb.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$app['config']->set('cache.default', 'array');
$app['config']->set('cache.stores.redis', ['driver' => 'array']);
$app['config']->set('app.timezone', 'Asia/Shanghai');
$app['cache']->forgetDriver('redis');
\Illuminate\Support\Facades\Bus::fake([\App\Jobs\ProcessNodeReportBatch::class]);
\Illuminate\Support\Facades\Redis::shouldReceive('publish')->andReturn(1);
[$script, $directory, $name, $nodeId] = $argv;
file_put_contents($directory . '/' . $name . '.ready', 'ready');
$deadline = microtime(true) + 25;
while (!is_file($directory . '/start')) {
    if (microtime(true) > $deadline) throw new RuntimeException('计量并发屏障超时');
    usleep(10000);
    clearstatcache();
}
app(\App\Services\NodeReportService::class)->accept(\App\Models\Server::findOrFail($nodeId),
    'same-report-per-node', [1 => [100, 200]], []);
echo "ok\n";
