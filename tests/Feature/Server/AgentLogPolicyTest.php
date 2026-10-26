<?php

namespace Tests\Feature\Server;

use App\Http\Controllers\V2\Admin\Server\MachineController;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Services\AgentLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AgentLogPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Redis::shouldReceive('publish')->andReturn(1);
        admin_setting(['server_token' => 'log-policy-test-only', 'server_ws_enable' => 0]);
        Route::post('/_tests/log-policy/save', [MachineController::class, 'save']);
        Route::get('/_tests/log-policy/fetch', [MachineController::class, 'fetch']);
    }

    private function machine(bool $disabled = false): ServerMachine
    {
        return ServerMachine::create(['name' => '日志策略测试', 'token' => Str::random(32),
            'is_active' => true, 'agent_log_disabled' => $disabled]);
    }

    public function test_machine_save_is_one_way_and_stale_request_cannot_restore_logs(): void
    {
        $id = $this->postJson('/_tests/log-policy/save', ['name' => '新机器', 'agent_log_disabled' => true])
            ->assertOk()->json('data.id');
        $machine = ServerMachine::findOrFail($id);
        $this->assertTrue($machine->agent_log_disabled);
        $this->postJson('/_tests/log-policy/save', ['id' => $id, 'name' => '误改名称', 'agent_log_disabled' => false])->assertUnprocessable();
        $this->assertSame('新机器', $machine->fresh()->name);
        $this->assertTrue($machine->fresh()->agent_log_disabled);
        foreach ([['agent_log_disabled' => true], []] as $extra) {
            $this->postJson('/_tests/log-policy/save', ['id' => $id, 'name' => '正常编辑'] + $extra)->assertOk();
            $this->assertTrue($machine->fresh()->agent_log_disabled);
        }
        $this->getJson('/_tests/log-policy/fetch')->assertOk()->assertJsonPath('data.0.agent_log_disabled', true);
        $normal = $this->machine();
        $this->assertFalse($normal->agent_log_disabled);
        $this->postJson('/_tests/log-policy/save', ['id' => $normal->id, 'name' => $normal->name, 'agent_log_disabled' => true])->assertOk();
        $this->assertTrue($normal->fresh()->agent_log_disabled);
    }

    public function test_model_and_migration_cannot_remove_an_enabled_lock(): void
    {
        $machine = $this->machine(true);
        try {
            $machine->update(['agent_log_disabled' => false]);
            $this->fail('模型不应恢复已停用的日志');
        } catch (ValidationException) {
            $this->assertTrue($machine->fresh()->agent_log_disabled);
        }
        $migration = require database_path('migrations/2026_10_27_000001_add_agent_log_disabled_to_machines.php');
        try {
            $migration->down();
            $this->fail('迁移不应删除已启用的锁定');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('不能回滚', $error->getMessage());
            $this->assertTrue(Schema::hasColumn('v2_server_machine', 'agent_log_disabled'));
        }
    }

    public function test_policy_is_available_before_node_discovery_and_for_legacy_node_binding(): void
    {
        foreach ([false, true] as $disabled) {
            $machine = $this->machine($disabled);
            $auth = ['machine_id' => $machine->id, 'token' => $machine->token];
            foreach (['/api/v2/server/handshake', '/api/v2/server/machine/nodes'] as $url) {
                $this->postJson($url, $auth)->assertOk()->assertJsonPath('log_policy', ['version' => 1, 'disabled' => $disabled]);
            }
            $this->postJson('/api/v2/server/machine/control', $auth + ['version' => 'v2.26.0', 'boot_id' => 'test-only', 'manageable' => false])
                ->assertOk()->assertJsonPath('log_policy.disabled', $disabled);
            $node = Server::create(['name' => '日志测试节点', 'machine_id' => $machine->id,
                'type' => 'shadowsocks', 'host' => 'test.invalid', 'port' => '10001', 'server_port' => 10001,
                'rate' => 1, 'group_ids' => [], 'enabled' => true]);
            $this->postJson('/api/v2/server/handshake', ['node_id' => $node->id, 'token' => 'log-policy-test-only', 'device_handover' => 1])
                ->assertOk()->assertJsonPath('log_policy.disabled', $disabled);
        }
    }

    public function test_disabled_machine_acknowledges_old_runtime_logs_without_storing_or_notifying(): void
    {
        $machine = $this->machine(true);
        $payload = ['batch_id' => str_repeat('a', 32), 'records' => [[
            'source' => 'xray', 'level' => 'error', 'event' => 'xray.crash',
            'message' => '运行日志不应保存', 'occurred_at' => now()->timestamp,
        ]]];
        $this->assertSame(['batch_id' => $payload['batch_id']], app(AgentLog::class)->accept(null, $machine, $payload));
        $this->assertSame(0, DB::table('v2_agent_log')->count());
    }
}
