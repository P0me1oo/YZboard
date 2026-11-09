<?php

namespace Tests\Feature\Server;

use App\Exceptions\ApiException;
use App\Http\Controllers\V2\Admin\Server\LiteController;
use App\Models\ServerMachine;
use App\Services\LiteClient;
use App\Services\LiteMonitor;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LiteBindingConcurrencyTest extends TestCase
{
    // 必须执行真实提交和回滚，不能由 RefreshDatabase 的外层事务包裹。
    use DatabaseTruncation;

    private LiteMonitor $monitor;
    private array $nodes;
    private string $key;

    protected function beforeTruncatingDatabase(): void
    {
        // SQLite 内存库随应用重建，需要重新迁移；MariaDB 清空测试数据即可。
        if (DB::getDriverName() === 'sqlite') RefreshDatabaseState::$migrated = false;
    }

    public static function tearDownAfterClass(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->nodes = [
            ['uuid' => (string) Str::uuid(), 'name' => 'Lite one', 'ipv4' => '8.8.4.4', 'ipv6' => ''],
            ['uuid' => (string) Str::uuid(), 'name' => 'Lite two', 'ipv4' => '1.1.1.1', 'ipv6' => ''],
        ];
        $this->key = Str::random(32);
        $this->partialMock(LiteClient::class, function ($mock) {
            $mock->shouldReceive('read')->andReturnUsing(fn ($connection, $method) => match ($method) {
                'admin:listClients' => $this->nodes,
                'common:getNodes' => [],
            });
        });
        $this->monitor = app(LiteMonitor::class);
        $this->monitor->save(['url' => 'https://lite.example.test', 'api_key' => $this->key, 'enabled' => true]);
        Route::post('/_tests/lite-concurrency/bind', [LiteController::class, 'bind']);
    }

    private function machine(int $index = 0): ServerMachine
    {
        return ServerMachine::create(['name' => 'Panel ' . $index, 'token' => Str::random(32),
            'is_active' => true, 'last_seen_at' => 1, 'traffic_limit' => 0,
            'agent_runtime' => ['public_ip' => $this->nodes[$index]['ipv4']]]);
    }

    private function conflict(string $sql, array $bindings, $connection): QueryException
    {
        $message = "Record has changed since last read in table 'v2_lite_machine'; try restarting transaction " . $this->key;
        $previous = new PDOException($message, 1020);
        $previous->errorInfo = ['HY000', 1020, $message];
        return new QueryException($connection->getName(), $sql, $bindings, $previous);
    }

    public static function batchCases(): array
    {
        return ['auto recovers' => ['auto', false], 'import recovers' => ['import', false],
            'auto exhausted' => ['auto', true], 'import exhausted' => ['import', true]];
    }

    #[DataProvider('batchCases')]
    public function test_batch_conflicts_retry_atomically_and_report_exhaustion(string $action, bool $exhaust): void
    {
        if ($action === 'auto') {
            $this->machine();
            $this->machine(1);
        }
        $writes = 0;
        $inject = true;
        DB::connection()->beforeExecuting(function ($sql, $bindings, $connection) use (&$writes, &$inject, $exhaust) {
            if (!$inject || !preg_match('/^insert into [`"]v2_lite_machine[`"]/i', $sql)) return;
            $writes++;
            // 第二项失败时，第一项绑定和本轮导入的机器都必须随事务回滚。
            if ($writes % 2 === 0 && ($exhaust || $writes === 2)) {
                throw $this->conflict($sql, $bindings, $connection);
            }
        });
        $input = ['action' => $action, 'uuids' => array_column($this->nodes, 'uuid')];
        $response = $this->postJson('/_tests/lite-concurrency/bind', $input);
        if ($exhaust) {
            $response->assertStatus(503)->assertJsonPath('message', 'Lite 绑定遇到并发更新，请稍后重试。');
            $this->assertSame(6, $writes);
            $this->assertDatabaseCount('v2_lite_machine', 0);
            $this->assertDatabaseCount('v2_server_machine', $action === 'auto' ? 2 : 0);
            $this->assertStringNotContainsString($this->key, $response->getContent());
            $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        } else {
            $response->assertOk()->assertJsonPath('data.count', 2);
            $this->assertSame(4, $writes);
            $this->assertDatabaseCount('v2_lite_machine', 2);
            $this->assertDatabaseCount('v2_server_machine', 2);
            if ($action === 'import') {
                $this->assertEqualsCanonicalizing($response->json('data.created_ids'), ServerMachine::pluck('id')->all());
            }
        }
        $this->assertSame(0, DB::transactionLevel());
        $inject = false;
        $this->postJson('/_tests/lite-concurrency/bind', $input)->assertOk()->assertJsonPath('data.count', $exhaust ? 2 : 0);
        $this->assertDatabaseCount('v2_lite_machine', 2);
        $this->assertDatabaseCount('v2_server_machine', 2);
    }

    public static function bindingActions(): array
    {
        return ['automatic' => ['auto'], 'manual' => ['bind']];
    }

    #[DataProvider('bindingActions')]
    public function test_real_heartbeat_waits_for_binding_and_resumes_after_commit(string $action): void
    {
        if (!defined('YZ_TEST_MARIADB')) $this->markTestSkipped('心跳与绑定的行锁检查需要专用 MariaDB');
        $this->assertSame('REPEATABLE-READ', DB::selectOne('SELECT @@transaction_isolation AS value')->value);
        $this->assertSame(1, (int) DB::selectOne('SELECT @@innodb_snapshot_isolation AS value')->value);
        $one = $this->machine();
        $two = $this->machine(1);
        $target = $action === 'auto' ? $two : $one;
        $config = DB::connection()->getConfig();
        $heartbeat = new PDO('mysql:host=127.0.0.1;port=' . $config['port'] . ';dbname=yzboard_mariadb_test', 'root', '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $heartbeat->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $attempted = false;
        $heartbeatError = null;
        DB::connection()->beforeExecuting(function ($sql) use ($heartbeat, $target, &$attempted, &$heartbeatError) {
            if ($attempted || !preg_match('/^insert into [`"]v2_lite_machine[`"]/i', $sql)) return;
            $attempted = true;
            try {
                $heartbeat->exec('UPDATE v2_server_machine SET last_seen_at = 2 WHERE id = ' . (int) $target->id);
            } catch (PDOException $error) {
                $heartbeatError = (int) $error->errorInfo[1];
            }
        });
        $result = $this->monitor->bind(['action' => $action, 'machine_id' => $one->id, 'uuid' => $this->nodes[0]['uuid']]);
        $this->assertTrue($attempted);
        $this->assertSame(1205, $heartbeatError, '绑定提交前，心跳应等待机器行锁');
        $this->assertSame($action === 'auto' ? 2 : 1, $result['count']);
        $heartbeat->exec('UPDATE v2_server_machine SET last_seen_at = 2 WHERE id = ' . (int) $target->id);
        $this->assertSame(2, $target->fresh()->last_seen_at);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertDatabaseHas('v2_lite_machine', ['machine_id' => $one->id, 'uuid' => $this->nodes[0]['uuid']]);
    }

    public function test_failed_manual_rebind_preserves_original_binding(): void
    {
        $machine = $this->machine();
        $this->monitor->bind(['action' => 'bind', 'machine_id' => $machine->id, 'uuid' => $this->nodes[0]['uuid']]);
        DB::connection()->beforeExecuting(function ($sql, $bindings, $connection) {
            if (preg_match('/^update [`"]v2_lite_machine[`"]/i', $sql)) throw $this->conflict($sql, $bindings, $connection);
        });
        $this->postJson('/_tests/lite-concurrency/bind', ['action' => 'bind', 'machine_id' => $machine->id,
            'uuid' => $this->nodes[1]['uuid']])->assertStatus(503);
        $this->assertDatabaseCount('v2_lite_machine', 1);
        $this->assertDatabaseHas('v2_lite_machine', ['machine_id' => $machine->id, 'uuid' => $this->nodes[0]['uuid']]);
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_deleted_target_is_reported_without_writing_a_binding(): void
    {
        $machine = $this->machine();
        DB::table('v2_server_machine')->where('id', $machine->id)->delete();
        try {
            $this->monitor->bind(['action' => 'bind', 'machine_id' => $machine->id, 'uuid' => $this->nodes[0]['uuid']]);
            $this->fail('已删除的目标机器不能绑定');
        } catch (ApiException $error) {
            $this->assertSame(409, $error->getCode());
            $this->assertSame('目标服务器已不存在，请重新读取。', $error->getMessage());
        }
        $this->assertDatabaseCount('v2_lite_machine', 0);
    }

    public function test_unrelated_database_errors_are_not_reported_as_retryable_conflicts(): void
    {
        $this->machine();
        $this->failNextQueryMatching('/^insert into [`"]v2_lite_machine[`"]/i');
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('测试数据库写入失败');
        $this->monitor->bind(['action' => 'auto']);
    }
}
