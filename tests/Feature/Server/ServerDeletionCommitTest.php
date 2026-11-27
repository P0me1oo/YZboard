<?php

namespace Tests\Feature\Server;

use App\Models\LandingPool;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Services\ServerDeletionService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\TestCase;

class ServerDeletionCommitTest extends TestCase
{
    use DatabaseTruncation;

    protected function beforeTruncatingDatabase(): void
    {
        if (DB::getDriverName() === 'sqlite') RefreshDatabaseState::$migrated = false;
    }

    public static function tearDownAfterClass(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDownAfterClass();
    }

    public function test_commit_notifies_machine_and_entry_while_rollback_does_not(): void
    {
        Queue::fake();
        $messages = [];
        Redis::shouldReceive('publish')->andReturnUsing(function ($channel, $message) use (&$messages) {
            $messages[] = json_decode($message, true);
            return 1;
        });
        $machine = ServerMachine::create(['name' => '删除测试机', 'token' => Str::random(32), 'is_active' => true]);
        $node = fn ($extra) => Server::create($extra + ['name' => '删除测试', 'type' => 'shadowsocks', 'host' => '192.0.2.1',
            'port' => 443, 'server_port' => 443, 'rate' => 1, 'enabled' => true, 'show' => true,
            'protocol_settings' => ['cipher' => 'aes-128-gcm']]);
        $entry = $node([]);
        $primary = $node(['relay_entry_id' => $entry->id]);
        $backup = $node(['relay_entry_id' => $entry->id, 'machine_id' => $machine->id]);
        $third = $node(['relay_entry_id' => $entry->id]);
        $pool = LandingPool::create(['name' => '通知测试池', 'node_id' => $primary->id, 'enabled' => true,
            'members' => [$primary->id, $backup->id, $third->id], 'state' => ['active' => [$entry->id => $backup->id]]]);
        $service = app(ServerDeletionService::class);
        $confirmation = $service->preview([$backup->id])['confirmation'];
        $messages = [];
        Cache::put('scheduling:targets:' . $entry->id, ['old']);
        DB::beginTransaction();
        $service->delete([$backup->id], $confirmation);
        $this->assertSame([], $messages);
        $this->assertSame(['old'], Cache::get('scheduling:targets:' . $entry->id));
        DB::rollBack();
        $this->assertDatabaseHas('v2_server', ['id' => $backup->id]);
        $this->assertSame([], $messages);
        $service->delete([$backup->id], $confirmation);
        $this->assertNull(Cache::get('scheduling:targets:' . $entry->id));
        $this->assertTrue(collect($messages)->contains(fn ($message) => ($message['machine_id'] ?? null) === $machine->id
            && $message['event'] === 'sync.nodes' && $message['data']['nodes'] === []));
        $this->assertSame([$primary->id, $third->id], $pool->fresh()->members);
        foreach ($pool->fresh()->state['active'] as $active) $this->assertNotEquals($backup->id, $active);
    }

    public function test_machine_deletion_notifies_only_after_outer_commit(): void
    {
        $machine = ServerMachine::create(['name' => 'commit test', 'token' => Str::random(32), 'is_active' => true]);
        $messages = [];
        Redis::shouldReceive('publish')->andReturnUsing(function ($channel, $message) use (&$messages) {
            $messages[] = json_decode($message, true);
            return 1;
        });
        DB::beginTransaction();
        \App\Services\MachineBindingService::delete($machine->id);
        $this->assertSame([], $messages);
        DB::rollBack();
        $this->assertSame([], $messages);
        $this->assertDatabaseMissing('v2_machine_binding_deletion', ['machine_id' => $machine->id]);
        \App\Services\MachineBindingService::delete($machine->id);
        $this->assertSame([['machine_id' => $machine->id, 'event' => 'machine.deleted', 'data' => []]], $messages);
    }

    public function test_nullable_primary_migration_preserves_pool_and_blocks_unsafe_rollback(): void
    {
        $migration = require database_path('migrations/2026_11_26_000001_allow_landing_pool_without_primary.php');
        $migration->down();
        $pool = LandingPool::create(['name' => '迁移保留池', 'node_id' => 123, 'enabled' => false, 'members' => [123, 124]]);
        $migration->up();
        $this->assertSame([123, 124], $pool->fresh()->members);
        $this->assertSame(123, $pool->fresh()->node_id);
        $pool->update(['node_id' => null]);
        LandingPool::create(['name' => '另一个待配置池', 'node_id' => null, 'enabled' => false, 'members' => []]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('回退前请重新配置或删除缺少主线路的落地池');
        $migration->down();
    }
}
