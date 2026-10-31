<?php

namespace Tests\Feature\Server;

use App\Models\ServerMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MachineHeartbeatTest extends TestCase
{
    use RefreshDatabase;

    private function control(ServerMachine $machine): array
    {
        return ['machine_id' => $machine->id, 'token' => $machine->token,
            'version' => 'v2.28.3', 'boot_id' => 'heartbeat-io-test', 'manageable' => true];
    }

    public function test_control_commits_runtime_and_heartbeat_in_one_update(): void
    {
        $machine = ServerMachine::create(['name' => '合并心跳', 'token' => 'control-heartbeat-test', 'is_active' => true]);
        $updates = [];
        DB::listen(function ($query) use (&$updates): void {
            if (preg_match('/^update ["`]?v2_server_machine/', $query->sql)) $updates[] = $query->sql;
        });
        $started = time();
        $this->postJson('/api/v2/server/machine/control', $this->control($machine))->assertOk();
        $this->assertCount(1, $updates);
        $this->assertStringContainsString('agent_runtime', $updates[0]);
        $this->assertGreaterThanOrEqual($started, $machine->fresh()->last_seen_at);
        $this->assertSame('v2.28.3', $machine->fresh()->agent_runtime['version']);
    }

    public function test_failed_control_keeps_authentication_heartbeat_without_partial_runtime(): void
    {
        $this->travelTo(now()->startOfSecond());
        $authenticatedAt = now()->timestamp;
        $machine = ServerMachine::create(['name' => '失败心跳', 'token' => 'control-failure-test', 'is_active' => true]);
        $this->failNextQueryMatching('/^update ["`]?v2_server_machine/');
        $this->withoutExceptionHandling();
        try {
            $this->postJson('/api/v2/server/machine/control', $this->control($machine));
            $this->fail('控制写入失败必须返回失败');
        } catch (\Illuminate\Database\QueryException) {
            $this->assertNull($machine->fresh()->agent_runtime);
            $this->assertSame($authenticatedAt, $machine->fresh()->last_seen_at);
        }
    }

    public function test_control_does_not_overwrite_a_newer_heartbeat_and_denied_auth_does_not_write(): void
    {
        $machine = ServerMachine::create(['name' => '并发控制心跳', 'token' => 'control-newer-test',
            'is_active' => true, 'last_seen_at' => time() + 60]);
        $newer = $machine->last_seen_at;
        $this->postJson('/api/v2/server/machine/control', $this->control($machine))->assertOk();
        $this->assertSame($newer, $machine->fresh()->last_seen_at);
        $runtime = $machine->fresh()->agent_runtime;
        $this->postJson('/api/v2/server/machine/control', [...$this->control($machine), 'token' => 'invalid'])->assertForbidden();
        $this->assertSame($runtime, $machine->fresh()->agent_runtime);
        $this->assertSame($newer, $machine->fresh()->last_seen_at);
    }

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
