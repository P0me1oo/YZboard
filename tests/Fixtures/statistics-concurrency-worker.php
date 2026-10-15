<?php

// 仅由 MariaDB 并发回归启动，不读取部署连接，也不创建或清理数据库。
require __DIR__ . '/../bootstrap-mariadb.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$app['config']->set('cache.default', 'array');
$app['config']->set('cache.stores.redis', ['driver' => 'array']);
$app['config']->set('app.timezone', 'Asia/Shanghai');
$app['cache']->forgetDriver('redis');
\Illuminate\Support\Facades\Redis::shouldReceive('sadd')->andReturn(1);
\Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-10-16 12:00:00', 'Asia/Shanghai'));
[$script, $mode, $directory, $name, $value] = $argv;
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
if ($mode === 'batch') {
    (new \App\Jobs\ProcessNodeReportBatch((int) $value))->handle();
} elseif ($mode === 'cleanup') {
    \Illuminate\Support\Facades\DB::table('v2_inbound_ip')->where('ip', '8.8.8.8')
        ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('v2_stat_user_inbound_ip')
            ->whereColumn('v2_stat_user_inbound_ip.ip', 'v2_inbound_ip.ip'))->delete();
} else {
    \Illuminate\Support\Facades\DB::transaction(function () use ($mode, $value, $directory, $name, $wait): void {
        app(\App\Services\InboundIpRecorder::class)->record([101 => ['8.8.8.8', '2400:cb00:1:2::1'], 102 => ['8.8.8.8']], (int) $value);
        if ($mode === 'hold') {
            file_put_contents($directory . '/' . $name . '.locked', 'locked');
            $wait($directory . '/release');
        }
    }, 5);
}
echo "ok\n";
