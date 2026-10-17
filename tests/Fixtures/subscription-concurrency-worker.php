<?php

require __DIR__ . '/../bootstrap-mariadb.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$app['config']->set('cache.default', 'array');
$app['config']->set('cache.stores.redis', ['driver' => 'array']);
$app['cache']->forgetDriver('redis');
[$script, $directory, $name, $batch, $mode] = $argv;
file_put_contents($directory . '/' . $name . '.ready', 'ready');
$deadline = microtime(true) + 20;
while (!is_file($directory . '/start')) {
    if (microtime(true) > $deadline) throw new RuntimeException('订阅并发屏障超时');
    usleep(10000);
    clearstatcache();
}
$writer = app(\App\Services\SubscriptionStatisticsWriter::class);
if ($mode === 'compact') $writer->compact();
else $writer->write($batch, array_fill(0, 60, ['user_id' => 101, 'ip' => '2001:db8::1', 'client' => 'Clash', 'client_version' => '1.0', 'format' => 'Clash', 'status' => 200, 'at' => time()]));
echo "ok\n";
