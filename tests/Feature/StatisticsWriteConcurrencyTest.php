<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\StatisticsWriteFixture as Fixture;
use Tests\TestCase;

class StatisticsWriteConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    private string $directory;
    private array $workers = [];

    protected function beforeTruncatingDatabase(): void
    {
        if (!defined('YZ_TEST_MARIADB')) $this->markTestSkipped('行锁并发检查仅在专用 MariaDB 实例运行');
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'Asia/Shanghai']);
        $this->travelTo(\Carbon\Carbon::parse('2026-10-16 12:00:00', 'Asia/Shanghai'));
        $root = getenv('YZ_TEST_TEMP_DIR') ?: storage_path('framework/testing');
        $this->directory = $root . '/statistics-concurrency-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->workers as $worker) if ($worker->isRunning()) $worker->stop();
        if (isset($this->directory)) {
            foreach (glob($this->directory . '/*') as $path) unlink($path);
            rmdir($this->directory);
        }
        parent::tearDown();
    }

    public static function tearDownAfterClass(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDownAfterClass();
    }

    private function worker(string $mode, string $name, int $value): Process
    {
        $arguments = getenv('YZ_TEST_PHP_MYSQL_EXTENSION') ? ['-d', 'extension=pdo_mysql'] : [];
        $process = new Process([PHP_BINARY, ...$arguments, base_path('tests/Fixtures/statistics-concurrency-worker.php'),
            $mode, $this->directory, $name, (string) $value], base_path(), null, null, 30);
        $this->workers[] = $process;
        $process->start();
        $this->waitFor($name . '.ready');
        return $process;
    }

    private function waitFor(string $name): void
    {
        $deadline = microtime(true) + 20;
        while (!is_file($this->directory . '/' . $name)) {
            foreach ($this->workers as $worker) {
                if (!$worker->isRunning() && $worker->getExitCode() !== 0) $this->fail($worker->getErrorOutput() . $worker->getOutput());
            }
            if (microtime(true) > $deadline) $this->fail('等待并发屏障超时');
            usleep(10000);
            clearstatcache();
        }
    }

    private function finish(Process ...$workers): void
    {
        foreach ($workers as $worker) {
            $worker->wait();
            $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput() . $worker->getOutput());
            $this->assertSame("ok\n", $worker->getOutput());
        }
    }

    public function test_concurrent_missing_addresses_keep_minimum_and_maximum(): void
    {
        $first = $this->worker('inbound', 'first', now()->subMinute()->timestamp);
        $second = $this->worker('inbound', 'second', now()->timestamp);
        touch($this->directory . '/start');
        $this->finish($first, $second);
        $this->assertDatabaseCount('v2_inbound_ip', 2);
        $this->assertDatabaseCount('v2_stat_user_inbound_ip', 3);
        $this->assertSame(3, DB::table('v2_stat_user_inbound_ip')->where('first_seen_at', now()->subMinute()->timestamp)
            ->where('last_seen_at', now()->timestamp)->count());
    }

    public function test_address_cleanup_waits_for_observation_commit(): void
    {
        DB::table('v2_inbound_ip')->insert(['ip' => '8.8.8.8', 'ip_key' => str_pad('08080808', 32, '0', STR_PAD_LEFT), 'ip_version' => 4]);
        $first = $this->worker('hold', 'first', now()->timestamp);
        touch($this->directory . '/start');
        $this->waitFor('first.locked');
        $second = $this->worker('cleanup', 'second', 0);
        usleep(150000);
        $this->assertTrue($second->isRunning(), '清理应等待观察事务持有的行锁');
        touch($this->directory . '/release');
        $this->finish($first, $second);
        $this->assertDatabaseHas('v2_inbound_ip', ['ip' => '8.8.8.8']);
        $this->assertDatabaseCount('v2_stat_user_inbound_ip', 3);
    }

    public function test_same_batch_workers_only_account_once(): void
    {
        ['batch' => $batch] = Fixture::make();
        $first = $this->worker('batch', 'first', $batch->id);
        $second = $this->worker('batch', 'second', $batch->id);
        touch($this->directory . '/start');
        $this->finish($first, $second);
        $this->assertSame(306, (int) DB::table('v2_stat_traffic_hour')->sum('u'));
        $this->assertSame('processed', $batch->fresh()->status);
    }

    public function test_different_batches_atomically_add_overlapping_statistics(): void
    {
        ['batch' => $batch] = Fixture::make(); $other = Fixture::duplicate($batch);
        $first = $this->worker('batch', 'first', $batch->id);
        $second = $this->worker('batch', 'second', $other->id);
        touch($this->directory . '/start');
        $this->finish($first, $second);
        foreach (['v2_stat_traffic_hour', 'v2_stat_user_route_source', 'v2_stat_route_hour', 'v2_stat_route_minute'] as $table) {
            $this->assertSame(612, (int) DB::table($table)->sum('u'));
        }
        $this->assertSame(864, (int) DB::table('v2_stat_node_hour')->sum('u'));
        $this->assertSame('processed', $batch->fresh()->status);
        $this->assertSame('processed', $other->fresh()->status);
    }
}
