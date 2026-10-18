<?php

namespace Tests\Feature\Server;

use App\Models\Server;
use App\Models\ServerMachine;
use App\Services\MachineTrafficService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MachineTrafficConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    protected function beforeTruncatingDatabase(): void
    {
        if (!defined('YZ_TEST_MARIADB')) $this->markTestSkipped('机器行锁并发检查仅在专用 MariaDB 运行');
    }

    public static function tearDownAfterClass(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDownAfterClass();
    }

    public function test_concurrent_duplicate_receipts_and_multiple_nodes_do_not_lose_or_double_usage(): void
    {
        config(['app.timezone' => 'Asia/Shanghai']);
        Redis::shouldReceive('publish')->andReturn(1);
        $machine = ServerMachine::create(['name' => '并发计量', 'token' => Str::random(32), 'is_active' => true]);
        $nodes = [];
        foreach (range(1, 2) as $index) {
            $nodes[] = Server::create(['name' => '并发节点 ' . $index, 'machine_id' => $machine->id,
                'type' => 'vless', 'host' => '127.0.0.1', 'port' => 443, 'server_port' => 443,
                'rate' => 5, 'enabled' => true, 'group_ids' => []]);
        }
        MachineTrafficService::configure($machine, ['traffic_mode' => 'proxy', 'traffic_limit_gb' => 1]);
        $machine->saveQuietly();
        $directory = (getenv('YZ_TEST_TEMP_DIR') ?: storage_path('framework/testing')) . '/machine-traffic-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700, true);
        $workers = [];
        try {
            // 同一节点、同一编号并发重试；同时另一节点上报独立的报告。
            foreach ([$nodes[0], $nodes[0], $nodes[1]] as $index => $node) {
                $arguments = getenv('YZ_TEST_PHP_MYSQL_EXTENSION') ? ['-d', 'extension=pdo_mysql'] : [];
                $worker = new Process([PHP_BINARY, ...$arguments, base_path('tests/Fixtures/machine-traffic-worker.php'),
                    $directory, (string) $index, (string) $node->id], base_path(), null, null, 40);
                $worker->start();
                $workers[] = $worker;
            }
            $deadline = microtime(true) + 25;
            while (count(glob($directory . '/*.ready')) !== 3) {
                foreach ($workers as $worker) {
                    if (!$worker->isRunning() && $worker->getExitCode() !== 0) $this->fail($worker->getErrorOutput() . $worker->getOutput());
                }
                if (microtime(true) > $deadline) $this->fail('等待计量并发屏障超时');
                usleep(10000);
                clearstatcache();
            }
            touch($directory . '/start');
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput() . $worker->getOutput());
                $this->assertSame("ok\n", $worker->getOutput());
            }
            $this->assertDatabaseCount('v2_node_report_batch', 2);
            $this->assertSame(600, MachineTrafficService::view($machine->fresh())['used']);
        } finally {
            foreach ($workers as $worker) if ($worker->isRunning()) $worker->stop();
            foreach (glob($directory . '/*') as $path) unlink($path);
            rmdir($directory);
            $ids = array_map(fn ($node) => $node->id, $nodes);
            \App\Models\NodeReportBatch::whereIn('server_id', $ids)->delete();
            Server::whereIn('id', $ids)->delete();
            ServerMachine::whereKey($machine->id)->delete();
        }
    }
}
