<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PanelBugFixConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    protected function beforeTruncatingDatabase(): void
    {
        if (!defined('YZ_TEST_MARIADB')) $this->markTestSkipped('真实行锁检查只在专用 MariaDB 测试库运行');
    }

    public static function tearDownAfterClass(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDownAfterClass();
    }

    public static function concurrentPaths(): array
    {
        return [['recovery'], ['reset'], ['limits']];
    }

    #[DataProvider('concurrentPaths')]
    public function test_duplicate_requests_apply_once(string $mode): void
    {
        Queue::fake();
        $plan = Plan::create(['name' => '并发测试套餐', 'reset_traffic_method' => Plan::RESET_TRAFFIC_MONTHLY]);
        $user = User::create(['email' => Str::random(12) . '@concurrency.example.invalid',
            'password' => 'test-only', 'token' => Str::random(32), 'uuid' => Str::uuid()->toString(),
            'is_admin' => true, 'totp_secret' => \App\Utils\Totp::generateSecret(), 'totp_enabled_at' => time(),
            'totp_recovery_codes' => [password_hash('AAAAA-BBBBB', PASSWORD_DEFAULT)],
            'plan_id' => $plan->id, 'expired_at' => time() + 60 * 86400, 'transfer_enable' => 10000, 'u' => 1000]);
        $user->update(['next_reset_at' => time() - 1]);
        $node = Server::create(['name' => '并发测试节点', 'type' => 'vless', 'host' => 'concurrency.example.invalid',
            'port' => 443, 'server_port' => 24443, 'rate' => 1, 'group_ids' => [], 'enabled' => true]);
        $directory = (getenv('YZ_TEST_TEMP_DIR') ?: storage_path('framework/testing')) . '/panel-bugfix-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700, true);
        $workers = [];
        try {
            foreach (range(1, 2) as $name) {
                $extensions = getenv('YZ_TEST_PHP_MYSQL_EXTENSION') ? ['-d', 'extension=pdo_mysql'] : [];
                $worker = new Process([PHP_BINARY, ...$extensions, base_path('tests/Fixtures/panel-bugfix-concurrency-worker.php'),
                    $mode, $directory, (string) $name, (string) $user->id, (string) $node->id], base_path(), null, null, 40);
                $worker->start(); $workers[] = $worker;
            }
            $deadline = microtime(true) + 25;
            while (count(glob($directory . '/*.ready')) !== 2) {
                foreach ($workers as $worker) {
                    if (!$worker->isRunning() && $worker->getExitCode() !== 0) $this->fail($worker->getErrorOutput() . $worker->getOutput());
                }
                if (microtime(true) > $deadline) $this->fail('等待并发请求超时');
                usleep(10000); clearstatcache();
            }
            touch($directory . '/start');
            $results = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput() . $worker->getOutput());
                $results[] = trim($worker->getOutput());
            }
            if ($mode === 'limits') {
                $this->assertSame(['ok', 'ok'], $results);
                $this->assertCount(1, glob($directory . '/*.notified'));
                $this->assertDatabaseCount('v2_node_limit_receipt', 1);
                $this->assertDatabaseCount('v2_user_event_log', 1);
            } else {
                sort($results); $this->assertSame(['0', '1'], $results);
                if ($mode === 'recovery') $this->assertSame([], $user->fresh()->totp_recovery_codes);
                else {
                    $this->assertSame(1, $user->fresh()->reset_count);
                    $this->assertDatabaseCount('v2_traffic_reset_logs', 1);
                }
            }
        } finally {
            foreach ($workers as $worker) if ($worker->isRunning()) $worker->stop();
            foreach (glob($directory . '/*') as $path) unlink($path);
            rmdir($directory);
        }
    }
}
