<?php

namespace Tests\Feature;

use App\Jobs\ProcessNodeReportBatch;
use App\Jobs\StatServerJob;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\StatisticsWriteFixture as Fixture;
use Tests\TestCase;

class TrafficHeartbeatConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    protected function beforeTruncatingDatabase(): void
    {
        if (!defined('YZ_TEST_MARIADB')) $this->markTestSkipped('需要专用 MariaDB 和真实提交');
    }

    public static function tearDownAfterClass(): void
    {
        // 本类真实提交的夹具不能成为后续 RefreshDatabase 测试的基线。
        RefreshDatabaseState::$migrated = false;
        parent::tearDownAfterClass();
    }

    public static function jobs(): array
    {
        return ['batch' => [false], 'legacy' => [true]];
    }

    #[DataProvider('jobs')]
    public function test_heartbeat_waits_until_accounting_commits(bool $legacy): void
    {
        Redis::shouldReceive('sadd')->andReturn(1);
        Redis::shouldReceive('publish')->andReturn(1);
        ['batch' => $batch, 'machines' => $machines, 'nodes' => $nodes] = Fixture::make(1);
        $connection = DB::connection();
        $connection->statement('SET SESSION innodb_snapshot_isolation=ON');
        $connection->statement("SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ");
        $heartbeat = new \PDO('mysql:host=127.0.0.1;port='.getenv('YZ_TEST_MARIADB_PORT').';dbname=yzboard_mariadb_test', 'root', '', [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $heartbeat->exec('SET SESSION innodb_lock_wait_timeout=1');
        $target = (int) $machines[0]->id;
        $attempted = false; $blocked = false; $locked = false;
        DB::listen(function ($event) use ($heartbeat, $target, $legacy, &$attempted, &$blocked, &$locked): void {
            if (str_contains($event->sql, 'from `v2_server_machine`') && str_contains($event->sql, 'for update')) $locked = true;
            $trigger = $legacy ? str_starts_with($event->sql, 'insert into `v2_stat_server`')
                : str_contains($event->sql, 'from `v2_settings`');
            if (!$trigger || $attempted || DB::transactionLevel() === 0) return;
            $attempted = true;
            // 真实独立连接尝试心跳；批次路径在设置读取后已经建立读取视图。
            $heartbeatSQL = 'UPDATE v2_server_machine SET last_seen_at=COALESCE(last_seen_at,0)+1 WHERE id='.$target;
            try { $heartbeat->exec($heartbeatSQL); }
            catch (\PDOException $error) {
                if ((int) $error->errorInfo[1] !== 1205) throw $error;
                $blocked = true;
            }
        });
        if ($legacy) {
            (new StatServerJob($nodes[0]->toArray(), (array) $batch->traffic, 'vless'))->handle();
        } else {
            (new ProcessNodeReportBatch((int) $batch->id))->handle();
            (new ProcessNodeReportBatch((int) $batch->id))->handle();
            $this->assertSame('processed', $batch->fresh()->status);
            $this->assertSame(1, $batch->fresh()->attempts);
        }
        $this->assertTrue($locked);
        $this->assertTrue($attempted);
        $this->assertTrue($blocked, '心跳必须等待机器累计量事务，不能使其读取视图失效');
        $this->assertSame(101, $machines[0]->fresh()->proxy_u);
        $this->assertSame(0, DB::transactionLevel());
        $heartbeat->exec('UPDATE v2_server_machine SET last_seen_at=123 WHERE id='.$target);
        $this->assertSame(123, $machines[0]->fresh()->last_seen_at);
    }
}
