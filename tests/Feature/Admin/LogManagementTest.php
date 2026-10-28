<?php

namespace Tests\Feature\Admin;

use App\Http\Routes\V2\AdminRoute;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\User;
use App\Services\AgentLog;
use App\Services\UserEventLog;
use App\Services\ServerService;
use App\Services\AuthService;
use App\Services\LogSanitizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LogManagementTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\IsolatesLogStorage;
    private const URL = '/_tests/log-admin/logs/';
    protected function setUp(): void
    {
        parent::setUp();
        $this->isolateLogStorage();
        $this->travelTo(now()->startOfSecond());
        $router = app('router'); $router->setRoutes(new RouteCollection());
        admin_setting(['secure_path' => 'log-admin']);
        Route::group(['prefix' => '_tests'], fn () => (new AdminRoute())->map($router));
        Route::post('/_tests/user/change-password', [\App\Http\Controllers\V1\User\UserController::class, 'changePassword'])->middleware('user');
        Route::post('/_tests/server/agent-log', [\App\Http\Controllers\V2\Server\AgentLogController::class, 'report'])->middleware('server.v2');
        Route::post('/_tests/machine/agent-log', [\App\Http\Controllers\V2\Server\MachineController::class, 'agentLog']);
    }
    protected function tearDown(): void
    {
        $this->removeLogStorage();
        parent::tearDown();
    }

    private function user(bool $admin = false): User
    {
        return User::create(['email' => Str::random(12) . '@logs.example.invalid', 'password' => password_hash('test-only', PASSWORD_DEFAULT),
            'uuid' => Str::uuid(), 'token' => Str::random(32), 'is_admin' => $admin]);
    }
    private function node(ServerMachine $machine): Server
    {
        return Server::create(['name' => '测试节点', 'machine_id' => $machine->id, 'type' => 'vless', 'host' => 'test.invalid', 'port' => '443', 'server_port' => 24443, 'rate' => 1, 'group_ids' => [], 'enabled' => true]);
    }
    public function test_logs_require_admin_and_never_return_raw_audit_payload(): void
    {
        $this->getJson(self::URL . 'agent/nodes')->assertForbidden();
        foreach (['system', 'agent', 'user'] as $category) $this->getJson(self::URL . $category)->assertForbidden();
        Sanctum::actingAs($this->user());
        $this->getJson(self::URL . 'agent/nodes')->assertForbidden();
        foreach (['system', 'agent', 'user'] as $category) $this->getJson(self::URL . $category)->assertForbidden();
        $admin = $this->user(true); Sanctum::actingAs($admin);
        DB::table('v2_admin_audit_log')->insert(['admin_id' => $admin->id, 'action' => 'user.update', 'method' => 'POST', 'uri' => '/test?token=never-return-this',
            'request_data' => '{"password":"never-return-this"}', 'ip' => '192.0.2.1', 'created_at' => now()->timestamp, 'updated_at' => now()->timestamp]);
        $response = $this->getJson(self::URL . 'system?source=audit')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $response->assertJsonPath('data.total', 1);
        $this->assertStringNotContainsString('never-return-this', $response->getContent());
    }
    public function test_agent_nodes_include_current_and_recent_deleted_nodes_with_only_names_and_ids(): void
    {
        Sanctum::actingAs($this->user(true));
        $this->getJson(self::URL . 'agent/nodes')->assertOk()->assertJsonPath('data', []);
        $machine = ServerMachine::create(['name' => '测试机器', 'token' => Str::random(32), 'is_active' => true]);
        $node = $this->node($machine);
        $node->update(['name' => '现在的节点名称', 'enabled' => false, 'show' => false]);
        $rows = [
            [$node->id, '原节点名称', now()->timestamp], [900001, '已删除节点旧名称', now()->timestamp],
            [900001, '已删除节点新名称', now()->timestamp], [900002, '过期节点', now()->subDays(8)->timestamp],
            [900003, null, now()->timestamp], [null, null, now()->timestamp],
        ];
        foreach ($rows as $index => [$id, $name, $at]) DB::table('v2_agent_log')->insert([
            'event_key' => hash('sha256', 'node-options-' . $index), 'node_id' => $id, 'node_name' => $name,
            'machine_id' => $machine->id, 'machine_name' => $machine->name,
            'source' => 'agent', 'level' => 'info', 'message' => '测试日志', 'occurred_at' => $at,
        ]);
        $this->getJson(self::URL . 'agent/nodes')->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data', [
                ['id' => $node->id, 'name' => '现在的节点名称'],
                ['id' => 900001, 'name' => '已删除节点新名称'], ['id' => 900003, 'name' => '#900003'],
            ]);
    }

    public function test_agent_node_filter_excludes_other_nodes_and_machine_only_logs_and_combines_filters(): void
    {
        $machine = ServerMachine::create(['name' => '测试机器', 'token' => Str::random(32), 'is_active' => true]);
        $node = $this->node($machine); $other = $this->node($machine);
        $logs = app(AgentLog::class);
        $logs->accept(null, $machine, ['batch_id' => str_repeat('c', 32), 'records' => [
            ['node_id' => $node->id, 'source' => 'agent', 'level' => 'info', 'message' => '节点配置更新', 'occurred_at' => now()->timestamp],
            ['node_id' => $node->id, 'source' => 'singbox', 'level' => 'error', 'message' => '节点监听失败', 'occurred_at' => now()->timestamp],
            ['node_id' => $other->id, 'source' => 'agent', 'level' => 'info', 'message' => '其他节点', 'occurred_at' => now()->timestamp],
            ['source' => 'xray', 'level' => 'error', 'message' => '机器级日志', 'occurred_at' => now()->timestamp],
        ]]);
        Sanctum::actingAs($this->user(true));
        $this->getJson(self::URL . 'agent?node_id=' . $node->id)->assertOk()->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.list.0.node_id', $node->id)->assertJsonPath('data.list.1.node_id', $node->id);
        $this->getJson(self::URL . 'agent?node_id=' . $node->id . '&machine_id=' . $machine->id . '&source=singbox&level=error')
            ->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.list.0.message', '节点监听失败');
        $this->getJson(self::URL . 'agent?node_id=' . $node->id . '&source=xray')->assertOk()->assertJsonPath('data.total', 0);
        $this->getJson(self::URL . 'agent?node_id=900001')->assertOk()->assertJsonPath('data.total', 0);
        $this->getJson(self::URL . 'agent?node_id=0')->assertUnprocessable();
        $this->getJson(self::URL . 'agent')->assertOk()->assertJsonPath('data.total', 4);
    }
    public function test_limit_events_persist_without_notifications_and_retries_are_deduplicated(): void
    {
        $user = $this->user();
        $machine = ServerMachine::create(['name' => '测试机器', 'token' => Str::random(32), 'is_active' => true]); $node = $this->node($machine);
        $events = array_map(fn ($kind) => ['user_id' => $user->id, 'kind' => $kind, 'limit' => 3, 'observed' => 3, 'count' => 5,
            'occurred_at' => now()->timestamp, 'last_at' => now()->timestamp], ['conn', 'rate', 'device', 'speed']);
        ServerService::processLimitEvents($node, $events, 'test-batch', false);
        ServerService::processLimitEvents($node, $events, 'test-batch', false);
        $this->assertDatabaseCount('v2_user_event_log', 4);
        $this->assertDatabaseHas('v2_user_event_log', ['event' => 'speed_limit', 'result' => 'throttled', 'count' => 5]);
        Sanctum::actingAs($this->user(true));
        $this->getJson(self::URL . 'user?event=device_limit&user=' . $user->id)->assertOk()->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.list.0.node_name', '测试节点');
        $this->getJson(self::URL . 'user?page_size=101')->assertUnprocessable();
        $this->travel(31)->days();
        $this->getJson(self::URL . 'user')->assertOk()->assertJsonPath('data.total', 0);
        $this->artisan('management-log:prune')->assertSuccessful();
        $this->assertDatabaseCount('v2_user_event_log', 0);
    }
    public function test_agent_ingestion_redacts_retries_and_rejects_other_machine_nodes(): void
    {
        $machine = ServerMachine::create(['name' => '测试机器', 'token' => Str::random(32), 'is_active' => true]); $node = $this->node($machine);
        $batch = ['batch_id' => str_repeat('a', 32), 'records' => [['node_id' => $node->id, 'source' => 'singbox', 'level' => 'error',
            'message' => 'failed password=temporary-test-secret url=https://test.invalid/path?token=temporary-test-secret', 'occurred_at' => now()->timestamp]]];
        $logs = app(AgentLog::class);
        $logs->accept(null, $machine, $batch); $logs->accept(null, $machine, $batch);
        $this->assertDatabaseCount('v2_agent_log', 1);
        $this->assertStringNotContainsString('temporary-test-secret', DB::table('v2_agent_log')->value('message'));
        Sanctum::actingAs($this->user(true));
        $this->getJson(self::URL . 'agent?source=singbox&machine_id=' . $machine->id)->assertOk()->assertJsonPath('data.total', 1);
        $other = ServerMachine::create(['name' => '其他机器', 'token' => Str::random(32), 'is_active' => true]);
        try { $logs->accept(null, $other, $batch); $this->fail('应拒绝其他机器节点'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) { $this->assertSame(403, $error->getStatusCode()); }
        $this->travel(8)->days();
        $this->getJson(self::URL . 'agent')->assertOk()->assertJsonPath('data.total', 0);
    }
    public function test_login_is_recorded_after_session_issuance_and_log_failure_does_not_break_login(): void
    {
        $user = $this->user();
        $auth = new AuthService($user); $auth->generateAuthData();
        $this->assertDatabaseHas('v2_user_event_log', ['user_id' => $user->id, 'event' => 'login', 'result' => 'success']);
        $this->failNextQueryMatching('/insert into ["`]?v2_user_event_log/i');
        $this->assertArrayHasKey('auth_data', $auth->generateAuthData());
        $this->assertDatabaseCount('v2_user_event_log', 1);
    }
    public function test_system_logs_use_bounded_local_files_and_hide_credentials(): void
    {
        $path = sys_get_temp_dir() . '/yz-logs-' . Str::random(12);
        mkdir($path . '/logs', 0700, true);
        $old = app()->storagePath(); app()->useStoragePath($path);
        try {
            $line = '[' . now()->format('Y-m-d H:i:s') . '] local.ERROR: failed token=temporary-test-secret {"config":"temporary-test-secret"}' . "\n[stacktrace]\n";
            file_put_contents($path . '/logs/laravel-' . now()->toDateString() . '.log', str_repeat('x', 2 * 1024 * 1024) . "\n" . $line);
            Sanctum::actingAs($this->user(true));
            $response = $this->getJson(self::URL . 'system')->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.limited', true);
            $this->assertStringNotContainsString('temporary-test-secret', $response->getContent());
            $this->getJson(self::URL . 'system?date=../../secret')->assertUnprocessable();
            $this->getJson(self::URL . 'system?date=' . now()->subDays(15)->toDateString())->assertUnprocessable();
        } finally {
            app()->useStoragePath($old);
            foreach (glob($path . '/logs/*') as $file) unlink($file);
            rmdir($path . '/logs'); rmdir($path);
        }
    }
    public function test_redaction_covers_nested_and_mixed_case_secrets(): void
    {
        $text = LogSanitizer::text('Authorization=Bearer temporary-test-secret OLD_PASSWORD="temporary-test-secret" uuid=00000000-0000-0000-0000-000000000000');
        $this->assertStringNotContainsString('temporary-test-secret', $text);
        $this->assertStringNotContainsString('00000000-', $text);
    }
    public function test_password_change_records_result_and_preserves_password_policy(): void
    {
        $user = $this->user(); Sanctum::actingAs($user);
        $this->postJson('/_tests/user/change-password', ['old_password' => 'incorrect-test', 'new_password' => 'new-test-only'])->assertStatus(400);
        $this->assertDatabaseHas('v2_user_event_log', ['user_id' => $user->id, 'event' => 'password_change', 'result' => 'wrong_password']);
        $this->postJson('/_tests/user/change-password', ['old_password' => 'test-only', 'new_password' => 'new-test-only'])->assertOk();
        $this->assertDatabaseHas('v2_user_event_log', ['user_id' => $user->id, 'event' => 'password_change', 'result' => 'success']);
        $this->assertTrue(password_verify('new-test-only', $user->fresh()->password));
    }
    public function test_ingestion_endpoints_authenticate_node_and_machine_without_logging_payload(): void
    {
        $machine = ServerMachine::create(['name' => '测试机器', 'token' => Str::random(32), 'is_active' => true]);
        $node = $this->node($machine);
        admin_setting(['server_token' => Str::random(32)]);
        $payload = ['batch_id' => str_repeat('b', 32), 'records' => [['source' => 'agent', 'level' => 'warn', 'message' => '测试运行日志', 'occurred_at' => now()->timestamp]]];
        $this->postJson('/_tests/machine/agent-log', $payload + ['machine_id' => $machine->id, 'token' => 'invalid-test'])->assertForbidden();
        $this->postJson('/_tests/machine/agent-log', $payload + ['machine_id' => $machine->id, 'token' => $machine->token])->assertOk()->assertJsonPath('data.batch_id', $payload['batch_id']);
        $this->postJson('/_tests/server/agent-log', $payload + ['node_id' => $node->id, 'token' => admin_setting('server_token')])->assertOk();
        $this->assertDatabaseCount('v2_agent_log', 2);
        $this->assertDatabaseCount('v2_admin_audit_log', 0);
    }
}
