<?php

namespace Tests\Feature\Server;

use App\Models\Server;
use App\Models\ServerMachine;
use App\Services\MachineStateService;
use App\Services\NodeRuntimeMetadata;
use App\Services\Plugin\HookManager;
use App\Support\Setting;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NodeRuntimeMetadataTest extends TestCase
{
    use DatabaseTruncation;

    protected function beforeTruncatingDatabase(): void
    {
        // SQLite 每例使用新内存库；MariaDB 则由框架清空上例已提交的数据。
        if (DB::connection()->getDriverName() === 'sqlite') {
            RefreshDatabaseState::$migrated = false;
        }
    }

    public static function tearDownAfterClass(): void
    {
        // 下一组事务测试重新建立迁移基线，包含迁移写入的默认记录。
        RefreshDatabaseState::$migrated = false;
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        // 真实提交/回滚用例不包在框架的外层测试事务中。
        $this->mock(Setting::class, fn ($mock) => $mock->shouldReceive('get')->andReturnNull());
    }

    private function node(): Server
    {
        return Server::create([
            'name' => '资料缓存测试', 'type' => Server::TYPE_VMESS,
            'host' => '127.0.0.1', 'port' => 443, 'server_port' => 443,
            'rate' => '1', 'group_ids' => [], 'enabled' => true,
        ]);
    }

    public function test_repeated_reports_reuse_queries_without_sharing_mutable_models(): void
    {
        $node = $this->node();
        $cache = app(NodeRuntimeMetadata::class);
        $this->assertSame($cache, app(NodeRuntimeMetadata::class));
        DB::enableQueryLog();
        DB::flushQueryLog();
        $first = $cache->nodeOrFail($node->id);
        $first->group_ids = [999];
        for ($i = 0; $i < 20; $i++) {
            $this->assertSame([], $cache->nodeOrFail($node->id)->group_ids);
        }
        $this->assertCount(1, DB::getQueryLog());
    }

    public function test_other_reader_sees_updates_and_deletion_without_waiting_for_expiry(): void
    {
        $node = $this->node();
        $reader = new NodeRuntimeMetadata();
        $reader->nodeOrFail($node->id);
        Server::findOrFail($node->id)->update(['group_ids' => [7], 'enabled' => false]);
        $current = $reader->nodeOrFail($node->id);
        $this->assertSame([7], $current->group_ids);
        $this->assertFalse($current->enabled);
        Server::findOrFail($node->id)->delete();
        $this->assertNull($reader->node($node->id));
        $this->expectException(ModelNotFoundException::class);
        $reader->nodeOrFail($node->id);
    }

    public function test_machine_disable_is_visible_to_status_acceptance_immediately(): void
    {
        $machine = ServerMachine::create(['name' => '资料测试机器', 'token' => 'runtime-metadata-test-only', 'is_active' => true]);
        $reader = new NodeRuntimeMetadata();
        $this->assertTrue($reader->machineOrFail($machine->id)->is_active);
        $machine->update(['is_active' => false]);
        $current = $reader->machineOrFail($machine->id);
        $this->assertFalse($current->is_active);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(MachineStateService::class)->accept($current, []);
    }

    public function test_transaction_rollback_does_not_publish_or_cache_uncommitted_changes(): void
    {
        $node = $this->node();
        $reader = new NodeRuntimeMetadata();
        $reader->nodeOrFail($node->id);
        $key = 'realtime:metadata:revision:nodes';
        $revision = Cache::get($key);
        DB::beginTransaction();
        Server::findOrFail($node->id)->update(['name' => '未提交名称']);
        $this->assertSame($revision, Cache::get($key));
        $this->assertSame('未提交名称', $reader->nodeOrFail($node->id)->name);
        DB::rollBack();
        $this->assertSame($revision, Cache::get($key));
        $this->assertSame('资料缓存测试', $reader->nodeOrFail($node->id)->name);

        DB::transaction(fn () => Server::findOrFail($node->id)->update(['name' => '已提交名称']));
        $this->assertNotSame($revision, Cache::get($key));
        $this->assertSame('已提交名称', $reader->nodeOrFail($node->id)->name);
    }

    public function test_expiry_and_process_restart_recover_writes_outside_model_events(): void
    {
        $node = $this->node();
        $reader = new NodeRuntimeMetadata();
        $reader->nodeOrFail($node->id);
        DB::table('v2_server')->where('id', $node->id)->update(['name' => '外部更新']);
        $this->assertSame('外部更新', (new NodeRuntimeMetadata())->nodeOrFail($node->id)->name);
        $this->travel(5)->seconds();
        $this->assertSame('外部更新', $reader->nodeOrFail($node->id)->name);
    }

    public function test_cache_failure_uses_database_instead_of_old_metadata(): void
    {
        $node = $this->node();
        $reader = new NodeRuntimeMetadata();
        $reader->nodeOrFail($node->id);
        DB::table('v2_server')->where('id', $node->id)->update(['name' => '缓存故障后的新名称']);
        Cache::shouldReceive('get')->once()->andThrow(new \RuntimeException('测试缓存不可用'));
        $this->assertSame('缓存故障后的新名称', $reader->nodeOrFail($node->id)->name);
    }

    public function test_user_filter_plugins_receive_uncached_node_fields(): void
    {
        $node = $this->node();
        $reader = new NodeRuntimeMetadata();
        $reader->nodeForDevices($node->id);
        DB::table('v2_server')->where('id', $node->id)->update(['u' => 100]);
        HookManager::registerFilter('server.users.get', fn ($users) => $users);
        $this->assertSame(100, $reader->nodeForDevices($node->id)->u);
    }

    public function test_bulk_deletion_invalidation_removes_cached_nodes(): void
    {
        $node = $this->node();
        $reader = new NodeRuntimeMetadata();
        $reader->nodeOrFail($node->id);
        $request = \Illuminate\Http\Request::create('/', 'POST', ['ids' => [$node->id]]);
        app(\App\Http\Controllers\V2\Admin\Server\ManageController::class)->batchDelete($request);
        $this->assertNull($reader->node($node->id));
    }

    public function test_machine_deletion_invalidates_its_nodes_and_machine_record(): void
    {
        $machine = ServerMachine::create(['name' => '待删除测试机器', 'token' => 'runtime-delete-test-only', 'is_active' => true]);
        $node = $this->node();
        $node->update(['machine_id' => $machine->id]);
        $reader = new NodeRuntimeMetadata();
        $this->assertSame($machine->id, $reader->nodeOrFail($node->id)->machine_id);
        $reader->machineOrFail($machine->id);
        $request = \Illuminate\Http\Request::create('/', 'POST', ['id' => $machine->id]);
        app(\App\Http\Controllers\V2\Admin\Server\MachineController::class)->drop($request);
        $this->assertNull($reader->nodeOrFail($node->id)->machine_id);
        $this->expectException(ModelNotFoundException::class);
        $reader->machineOrFail($machine->id);
    }

    public function test_cache_flush_discards_previous_generation(): void
    {
        $node = $this->node();
        $reader = new NodeRuntimeMetadata();
        $reader->nodeOrFail($node->id);
        DB::table('v2_server')->where('id', $node->id)->update(['name' => '清缓存后的新名称']);
        Cache::flush();
        $this->assertSame('清缓存后的新名称', $reader->nodeOrFail($node->id)->name);
    }
}
