<?php

// 单独进程运行，不能载入 PHPUnit 的测试事务。仅重建明确指定的本机专用测试库。
require __DIR__ . '/../../vendor/autoload.php';
$driver = getenv('YZ_BENCH_DB') ?: 'sqlite';
if (!in_array($driver, ['sqlite', 'mysql'], true)) throw new RuntimeException('不支持的基准数据库');
foreach (['APP_ENV' => 'testing', 'DB_CONNECTION' => $driver, 'DB_DATABASE' => ':memory:',
    'CACHE_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array'] as $key => $value) {
    putenv($key . '=' . $value); $_ENV[$key] = $_SERVER[$key] = $value;
}
if ($driver === 'mysql') require __DIR__ . '/../bootstrap-mariadb.php';
$baseline = getenv('YZ_BENCH_BASELINE');
if ($baseline) {
    foreach (['InboundIpRecorder', 'TrafficStatisticsRecorder', 'FineTrafficStatistics',
        'UserRouteTraffic', 'ProxyTrafficRecorder', 'ProcessNodeReportBatch'] as $class) {
        require $baseline . '/' . $class . '.php';
    }
}
$app = require __DIR__ . '/../../bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$app['config']->set('app.timezone', 'Asia/Shanghai');
$app['config']->set('cache.default', 'array');
$app['config']->set('cache.stores.redis', ['driver' => 'array']);
$app['cache']->forgetDriver('redis');
$app['config']->set('database.connections.sqlite.database', ':memory:');
$app['db']->purge('sqlite');
if ($app['config']->get('database.default') !== $driver || !$app->environment('testing')) throw new RuntimeException('基准环境不匹配');
if ($driver === 'mysql' && ($app['db']->connection()->getConfig('host') !== '127.0.0.1'
    || $app['db']->connection()->getDatabaseName() !== 'yzboard_mariadb_test')) throw new RuntimeException('仅允许专用本机测试库');
if (getenv('YZ_BENCH_REUSE_SCHEMA') === '1' && $driver === 'mysql') {
    // 仅供串行重复基准；上方仍强制校验专用本机库，不跳过任何业务验证。
    foreach (['v2_node_report_batch', ...array_keys(\Tests\Support\StatisticsWriteFixture::totals()), 'v2_stat_user_inbound_ip', 'v2_inbound_ip'] as $table) {
        \Illuminate\Support\Facades\DB::table($table)->truncate();
    }
} elseif ($kernel->call('migrate:fresh', ['--force' => true]) !== 0) {
    throw new RuntimeException('基准迁移失败');
}
fwrite(STDERR, "基准数据库准备完成\n");
\Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-10-16 12:00:00', 'Asia/Shanghai'));

// 小型 RESP 客户端仅供测量真实 Redis 的集合命令和内存，不增加项目依赖。
$redis = new class {
    private $socket;
    public string $key;
    public function __construct()
    {
        $port = (int) (getenv('YZ_BENCH_REDIS_PORT') ?: 16379);
        $this->socket = stream_socket_client('tcp://127.0.0.1:' . $port, $code, $message, 3);
        if (!$this->socket) throw new RuntimeException('基准 Redis 不可用');
        stream_set_timeout($this->socket, 3);
        $this->key = 'yz-benchmark:' . bin2hex(random_bytes(8));
    }
    public function sadd(string $ignored, ...$members): int { return $this->command('SADD', $this->key, ...$members); }
    public function command(...$parts): mixed
    {
        $wire = '*' . count($parts) . "\r\n";
        foreach ($parts as $part) $wire .= '$' . strlen((string) $part) . "\r\n" . $part . "\r\n";
        if (fwrite($this->socket, $wire) !== strlen($wire)) throw new RuntimeException('Redis 写入不完整');
        $line = fgets($this->socket);
        if ($line === false) throw new RuntimeException('Redis 响应超时');
        $value = substr($line, 1, -2);
        if ($line[0] === '-') throw new RuntimeException($value);
        if ($line[0] === ':') return (int) $value;
        if ($line[0] === '$') {
            if ((int) $value < 0) return null;
            $body = '';
            while (strlen($body) < (int) $value + 2) $body .= fread($this->socket, (int) $value + 2 - strlen($body));
            return substr($body, 0, -2);
        }
        return $value;
    }
    public function stats(): array
    {
        $stats = $this->command('INFO', 'commandstats');
        preg_match('/cmdstat_sadd:calls=(\d+)/', $stats, $matches);
        $memory = $this->command('INFO', 'memory');
        preg_match('/used_memory:(\d+)/', $memory, $bytes);
        $commands = $this->command('INFO', 'stats');
        preg_match('/total_commands_processed:(\d+)/', $commands, $total);
        return ['commands' => (int) $total[1], 'sadd' => (int) ($matches[1] ?? 0), 'used_memory' => (int) $bytes[1],
            'key_bytes' => (int) $this->command('MEMORY', 'USAGE', $this->key)];
    }
};
\Illuminate\Support\Facades\Redis::swap($redis);
$measure = false; $sql = []; $transactions = 0;
\Illuminate\Support\Facades\DB::listen(function ($event) use (&$measure, &$sql): void {
    if (!$measure) return;
    $verb = strtolower(strtok(ltrim($event->sql), ' '));
    $sql[$verb] = ($sql[$verb] ?? 0) + 1;
});
$app['events']->listen(\Illuminate\Database\Events\TransactionBeginning::class, function () use (&$measure, &$transactions): void {
    if ($measure) $transactions++;
});
$run = function (callable $work) use (&$measure, &$sql, &$transactions, $redis): array {
    $sql = []; $transactions = 0; $startRedis = $redis->stats();
    $start = hrtime(true); $measure = true;
    try { $work(); } finally { $measure = false; }
    $ms = (hrtime(true) - $start) / 1e6; $endRedis = $redis->stats();
    return ['sql' => $sql, 'sql_total' => array_sum($sql), 'transactions' => $transactions, 'ms' => round($ms, 3),
        'redis_commands' => $endRedis['commands'] - $startRedis['commands'] - 4,
        'redis_sadd' => $endRedis['sadd'] - $startRedis['sadd'], 'redis_memory_delta' => $endRedis['used_memory'] - $startRedis['used_memory'],
        'redis_key_bytes' => $endRedis['key_bytes'], 'php_peak_bytes' => memory_get_peak_usage(true)];
};
try {
    $count = (int) (getenv('YZ_BENCH_USERS') ?: 128);
    $fixture = \Tests\Support\StatisticsWriteFixture::make($count);
    fwrite(STDERR, "隔离样本准备完成\n");
    \App\Services\DeviceIpExclusion::countKey('8.8.8.8'); // 相同的已加载排除规则，不将设置服务的冷启动混入写入对比。
    $alive = [];
    $ipMode = getenv('YZ_BENCH_IP_MODE') ?: 'shared';
    foreach ($fixture['users'] as $index => $user) {
        $v4 = $ipMode === 'shared' ? '8.8.8.8' : '8.8.' . intdiv($index, 250) . '.' . ($index % 250 + 1);
        $v6 = $ipMode === 'shared' ? '2400:cb00:1:2::1' : '2400:cb00:1:2::' . dechex($index + 1);
        $alive[$user->id] = [$v4, '::ffff:' . $v4, $v6];
    }
    $recorder = app(\App\Services\InboundIpRecorder::class);
    $at = now()->subMinutes(2)->timestamp;
    $result = ['database' => $driver, 'implementation' => $baseline ? 'before' : 'after', 'users' => $count, 'ip_mode' => $ipMode];
    $result['php_version'] = PHP_VERSION;
    $result['inbound_cold'] = $run(fn () => $recorder->record($alive, $at));
    $result['inbound_duplicate_20'] = $run(function () use ($recorder, $alive, $at): void {
        for ($i = 0; $i < 20; $i++) $recorder->record($alive, $at);
    });
    $result['inbound_advancing_20'] = $run(function () use ($recorder, $alive, $at): void {
        for ($i = 1; $i <= 20; $i++) $recorder->record($alive, $at + $i);
    });
    $result['inbound_bounds'] = (array) \Illuminate\Support\Facades\DB::table('v2_stat_user_inbound_ip')
        ->selectRaw('COUNT(*) AS rows_count, MIN(first_seen_at) AS first_seen, MAX(last_seen_at) AS last_seen')->first();
    fwrite(STDERR, "入站历史基准完成\n");
    $batches = [$fixture['batch']];
    for ($i = 0; $i < 5; $i++) $batches[] = \Tests\Support\StatisticsWriteFixture::duplicate($fixture['batch']);
    $result['traffic_cold'] = $run(fn () => (new \App\Jobs\ProcessNodeReportBatch($batches[0]->id))->handle());
    $result['traffic_warm_5'] = $run(function () use ($batches): void {
        foreach (array_slice($batches, 1) as $batch) (new \App\Jobs\ProcessNodeReportBatch($batch->id))->handle();
    });
    $result['traffic_replay'] = $run(fn () => (new \App\Jobs\ProcessNodeReportBatch($batches[0]->id))->handle());
    $result['totals'] = \Tests\Support\StatisticsWriteFixture::totals();
    $result['fingerprint'] = \Tests\Support\StatisticsWriteFixture::fingerprint();
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
} finally {
    $redis->command('DEL', $redis->key);
}
