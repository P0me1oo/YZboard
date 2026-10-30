<?php

namespace Tests\Feature\Server;

use App\Models\ServerMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MachineHeartbeatTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_second_heartbeat_does_not_write_or_save_unrelated_attributes(): void
    {
        $this->travelTo(now()->startOfSecond());
        $machine = ServerMachine::create(['name' => '心跳测试', 'token' => 'heartbeat-test-only', 'is_active' => true]);
        $updates = 0;
        DB::listen(function ($query) use (&$updates): void {
            if (preg_match('/^update ["`]?v2_server_machine/', $query->sql)) $updates++;
        });
        $machine->name = '未保存草稿';
        $machine->recordHeartbeat();
        $machine->recordHeartbeat();
        $machine->fresh()->recordHeartbeat();
        $this->assertSame(1, $updates);
        $this->assertSame('心跳测试', $machine->fresh()->name);
        $this->assertSame(now()->timestamp, $machine->fresh()->last_seen_at);
        $this->travel(1)->seconds();
        $machine->recordHeartbeat();
        $this->assertSame(2, $updates);
        $this->assertSame(now()->timestamp, $machine->fresh()->last_seen_at);
    }

    public function test_stale_model_cannot_move_a_newer_database_heartbeat_backwards(): void
    {
        $this->travelTo(now()->startOfSecond());
        $machine = ServerMachine::create(['name' => '并发心跳', 'token' => 'heartbeat-race-test', 'is_active' => true]);
        $newer = now()->addSeconds(5)->timestamp;
        DB::table('v2_server_machine')->where('id', $machine->id)->update(['last_seen_at' => $newer]);
        $machine->recordHeartbeat();
        $this->assertSame($newer, $machine->fresh()->last_seen_at);
    }

    public function test_failed_write_does_not_mark_the_model_heartbeat_as_persisted(): void
    {
        $machine = ServerMachine::create(['name' => '失败重试', 'token' => 'heartbeat-failure-test', 'is_active' => true]);
        $this->failNextQueryMatching('/^update ["`]?v2_server_machine/');
        try {
            $machine->recordHeartbeat();
            $this->fail('数据库失败必须由请求处理');
        } catch (\Illuminate\Database\QueryException) {
            $this->assertNull($machine->last_seen_at);
        }
        $machine->recordHeartbeat();
        $this->assertSame(now()->timestamp, $machine->fresh()->last_seen_at);
    }
}
