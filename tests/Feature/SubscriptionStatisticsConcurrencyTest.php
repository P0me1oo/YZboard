<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class SubscriptionStatisticsConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    protected function beforeTruncatingDatabase(): void
    {
        if (!defined('YZ_TEST_MARIADB')) $this->markTestSkipped('订阅行锁并发检查需要专用 MariaDB');
    }

    public static function tearDownAfterClass(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDownAfterClass();
    }

    public function test_parallel_writes_deduplication_and_compaction_preserve_all_requests(): void
    {
        $directory = (getenv('YZ_TEST_TEMP_DIR') ?: storage_path('framework/testing')) . '/subscription-concurrency-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700, true);
        $processes = [];
        $same = bin2hex(random_bytes(16));
        for ($i = 0; $i < 2; $i++) app(\App\Services\SubscriptionStatisticsWriter::class)->write(bin2hex(random_bytes(16)), [
            ['user_id' => 101, 'ip' => '2001:db8::1', 'client' => 'Clash', 'client_version' => '1.0', 'format' => 'Clash', 'status' => 200, 'at' => time()],
        ]);
        try {
            foreach ([$same, $same, bin2hex(random_bytes(16)), bin2hex(random_bytes(16))] as $i => $id) {
                $extension = getenv('YZ_TEST_PHP_MYSQL_EXTENSION') ? ['-d', 'extension=pdo_mysql'] : [];
                $process = new Process([PHP_BINARY, ...$extension, base_path('tests/Fixtures/subscription-concurrency-worker.php'), $directory, (string) $i, $id, $i === 3 ? 'compact' : 'write'], base_path(), null, null, 30);
                $processes[] = $process;
                $process->start();
            }
            $deadline = microtime(true) + 20;
            while (count(glob($directory . '/*.ready')) < 4) {
                if (microtime(true) > $deadline) $this->fail('并发进程启动超时');
                usleep(10000);
                clearstatcache();
            }
            touch($directory . '/start');
            foreach ($processes as $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            }
            $this->assertDatabaseCount('v2_subscription_batch', 4);
            $this->assertDatabaseCount('v2_subscription_stat', 1);
            $this->assertSame(122, (int) DB::table('v2_subscription_stat')->sum('count'));
            app(\App\Services\SubscriptionStatisticsWriter::class)->compact();
            $count = 0;
            foreach (DB::table('v2_subscription_block')->get() as $block) $count += count(app(\App\Services\SubscriptionTimeCodec::class)->decode((array) $block));
            $this->assertSame(122, $count);
        } finally {
            foreach ($processes as $process) if ($process->isRunning()) $process->stop();
            foreach (glob($directory . '/*') as $file) unlink($file);
            rmdir($directory);
        }
    }
}
