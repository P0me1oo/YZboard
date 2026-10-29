<?php

namespace Tests\Feature\Admin;

use App\Http\Routes\V2\AdminRoute;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\User;
use App\Services\AgentLog;
use App\Services\AuthService;
use App\Services\DatabaseMaintenance;
use App\Services\LogSettings;
use App\Services\ProxyAccessLog;
use App\Services\ServerService;
use App\Services\SystemLogPruner;
use App\Services\UserEventLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LogSettingsTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\IsolatesLogStorage;

    private const URL = '/_tests/log-settings-admin/logs/';

    protected function setUp(): void
    {
        parent::setUp();
        $this->isolateLogStorage();
        $this->travelTo(now()->startOfSecond());
        $router = app('router'); $router->setRoutes(new RouteCollection());
        admin_setting(['secure_path' => 'log-settings-admin']);
        Route::group(['prefix' => '_tests'], fn () => (new AdminRoute())->map($router));
    }

    protected function tearDown(): void
    {
        $this->removeLogStorage();
        parent::tearDown();
    }

    private function user(bool $admin = false): User
    {
        return User::create(['email' => Str::random(12) . '@log-settings.example.invalid', 'password' => password_hash('test-only', PASSWORD_DEFAULT),
            'uuid' => Str::uuid(), 'token' => Str::random(32), 'is_admin' => $admin]);
    }

    private function node(): Server
    {
        $machine = ServerMachine::create(['name' => '测试机器', 'token' => Str::random(32), 'is_active' => true]);
        return Server::create(['name' => '测试节点', 'machine_id' => $machine->id, 'type' => 'vless', 'host' => 'test.invalid',
            'port' => '443', 'server_port' => 24443, 'rate' => 1, 'group_ids' => [], 'enabled' => true]);
    }

    private function change(string $module, array $patch): void
    {
        $settings = LogSettings::view();
        $settings[$module] = array_replace($settings[$module], $patch);
        LogSettings::save($settings);
    }

    private function agentBatch(int $at, string $id = 'a'): array
    {
        return ['batch_id' => str_repeat($id, 32), 'records' => [
            ['source' => 'agent', 'level' => 'warn', 'message' => '测试运行日志', 'occurred_at' => $at],
        ]];
    }

    private function accessBatch(Server $node, int $at, string $id = 'b'): array
    {
        return ['batch_id' => str_repeat($id, 32), 'records' => [
            ['node_id' => $node->id, 'user_id' => 1, 'destination' => 'example.invalid', 'port' => 443, 'connected_at' => $at],
        ]];
    }

    private function audit(int $at): int
    {
        return DB::table('v2_admin_audit_log')->insertGetId(['admin_id' => 1, 'action' => 'test.action', 'method' => 'POST',
            'uri' => '/test', 'created_at' => $at, 'updated_at' => $at]);
    }

    public function test_settings_require_admin_and_validate_all_modules_without_partial_saves(): void
    {
        $settings = LogSettings::view();
        $this->getJson(self::URL . 'settings')->assertForbidden();
        $this->postJson(self::URL . 'settings', ['settings' => $settings])->assertForbidden();
        Sanctum::actingAs($this->user());
        $this->postJson(self::URL . 'settings', ['settings' => $settings])->assertForbidden();
        Sanctum::actingAs($this->user(true));
        $this->getJson(self::URL . 'settings')->assertOk()->assertJsonPath('data', $settings)->assertHeader('Cache-Control', 'no-store, private');
        foreach ([0, -1, 366, 1.5, null, 'invalid'] as $days) {
            $invalid = $settings; $invalid['agent']['retention_days'] = $days;
            $this->postJson(self::URL . 'settings', ['settings' => $invalid])->assertUnprocessable();
            $this->assertSame($settings, LogSettings::view());
        }
        $invalid = $settings; $invalid['agent']['record_from'] = 0;
        $this->postJson(self::URL . 'settings', ['settings' => $invalid])->assertUnprocessable();
        $invalid = $settings; unset($invalid['system']);
        $this->postJson(self::URL . 'settings', ['settings' => $invalid])->assertUnprocessable();
        $settings['agent'] = ['enabled' => false, 'retention_days' => 365];
        $settings['system']['retention_days'] = 1;
        $this->postJson(self::URL . 'settings', ['settings' => $settings])->assertOk()->assertJsonPath('data', $settings);
        app()->forgetScopedInstances();
        $this->assertSame($settings, LogSettings::view());
    }

    public function test_disabled_modules_stop_writes_and_reenable_drops_delayed_records(): void
    {
        $node = $this->node(); $user = $this->user();
        $agent = app(AgentLog::class); $access = app(ProxyAccessLog::class);
        $old = now()->timestamp;
        foreach (['agent', 'access', 'user'] as $module) $this->change($module, ['enabled' => false]);
        $this->assertArrayHasKey('auth_data', (new AuthService($user))->generateAuthData());
        $this->assertSame(['batch_id' => str_repeat('a', 32)], $agent->accept($node, null, $this->agentBatch($old)));
        $access->accept($node, $this->accessBatch($node, $old));
        foreach (['v2_agent_log', 'v2_proxy_access_log', 'v2_user_event_log'] as $table) $this->assertDatabaseCount($table, 0);
        $this->travel(2)->seconds();
        foreach (['agent', 'access', 'user'] as $module) $this->change($module, ['enabled' => true]);
        $agent->accept($node, null, $this->agentBatch($old));
        $access->accept($node, $this->accessBatch($node, $old));
        $agent->accept($node, null, $this->agentBatch(now()->timestamp, 'c'));
        $agent->accept($node, null, $this->agentBatch(now()->timestamp, 'c'));
        $access->accept($node, $this->accessBatch($node, now()->timestamp, 'd'));
        $access->accept($node, $this->accessBatch($node, now()->timestamp, 'd'));
        (new AuthService($user))->generateAuthData();
        foreach (['v2_agent_log', 'v2_proxy_access_log', 'v2_user_event_log'] as $table) $this->assertDatabaseCount($table, 1);
        ServerMachine::findOrFail($node->machine_id)->update(['agent_log_disabled' => true]);
        $this->change('agent', ['enabled' => false]); $this->change('agent', ['enabled' => true]);
        $agent->accept($node, null, $this->agentBatch(now()->timestamp, 'e'));
        $this->assertDatabaseCount('v2_agent_log', 1);
    }

    public function test_disabling_user_logs_preserves_limit_receipts_and_retry_deduplication(): void
    {
        $node = $this->node(); $user = $this->user();
        $this->change('user', ['enabled' => false, 'retention_days' => 1]);
        $events = [['user_id' => $user->id, 'kind' => 'device', 'limit' => 1, 'observed' => 2, 'count' => 1,
            'occurred_at' => now()->timestamp, 'last_at' => now()->timestamp]];
        ServerService::processLimitEvents($node, $events, 'log-settings-test-batch', false);
        $this->assertDatabaseCount('v2_node_limit_receipt', 1);
        $this->assertDatabaseCount('v2_user_event_log', 0);
        $this->travel(2)->days();
        $this->artisan('management-log:prune')->assertSuccessful();
        $this->assertDatabaseCount('v2_node_limit_receipt', 1);
        $this->change('user', ['enabled' => true]);
        ServerService::processLimitEvents($node, $events, 'log-settings-test-batch', false);
        $this->assertDatabaseCount('v2_user_event_log', 0);
    }

    public function test_queries_and_cleanup_share_retention_boundaries_even_when_recording_is_off(): void
    {
        $node = $this->node(); $user = $this->user();
        $boundary = now()->timestamp - 2 * 86400;
        foreach ([$boundary - 1, $boundary] as $index => $at) {
            DB::table('v2_agent_log')->insert(['event_key' => hash('sha256', 'settings-' . $index), 'source' => 'agent', 'level' => 'info', 'message' => '测试', 'occurred_at' => $at]);
            DB::table('v2_user_event_log')->insert(['user_id' => $user->id, 'event' => 'login', 'result' => 'success', 'count' => 1, 'occurred_at' => $at, 'last_at' => $at]);
            $this->audit($at);
            app(ProxyAccessLog::class)->accept($node, $this->accessBatch($node, $at, $index ? 'b' : 'a'));
        }
        foreach (['agent', 'user', 'audit', 'access'] as $module) $this->change($module, ['retention_days' => 2, 'enabled' => false]);
        Sanctum::actingAs($this->user(true));
        foreach (['agent', 'user', 'system?source=audit'] as $category) {
            $this->getJson(self::URL . $category)->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.retention_days', 2);
        }
        $query = \Illuminate\Http\Request::create('/', 'GET', ['period' => '7d']);
        $this->assertSame(1, app(ProxyAccessLog::class)->records($query)['total']);
        $this->artisan('management-log:prune')->assertSuccessful();
        $this->artisan('access-log:prune')->assertSuccessful();
        $this->artisan('management-log:prune')->assertSuccessful();
        foreach (['v2_agent_log', 'v2_user_event_log', 'v2_admin_audit_log', 'v2_proxy_access_log'] as $table) $this->assertDatabaseCount($table, 1);
    }

    public function test_longer_retention_keeps_records_and_old_cleanup_previews_cannot_delete_them(): void
    {
        $node = $this->node();
        $this->change('access', ['retention_days' => 30]);
        app(ProxyAccessLog::class)->accept($node, $this->accessBatch($node, now()->timestamp - 20 * 86400));
        $this->assertDatabaseCount('v2_proxy_access_log', 1);
        $request = \Illuminate\Http\Request::create('/', 'GET', ['period' => 'custom', 'start_at' => now()->subDays(21)->format('Y-m-d\TH:i'), 'end_at' => now()->format('Y-m-d\TH:i')]);
        $this->assertSame(1, app(ProxyAccessLog::class)->records($request)['total']);
        $this->change('access', ['retention_days' => 7]);
        $maintenance = app(DatabaseMaintenance::class);
        $preview = $maintenance->preview(['access'], 1);
        $this->assertSame(1, $preview['items'][0]['count']);
        $this->change('access', ['retention_days' => 30]);
        $this->assertSame(0, $maintenance->cleanup($preview['token'], 1)['deleted']);
        $this->change('audit', ['retention_days' => 365]);
        $this->audit(now()->timestamp - 100 * 86400);
        $this->artisan('reset:log')->assertSuccessful();
        $this->artisan('management-log:prune')->assertSuccessful();
        $this->assertDatabaseCount('v2_admin_audit_log', 1);
        $this->assertDatabaseCount('v2_proxy_access_log', 1);
    }

    public function test_audit_switch_applies_to_real_admin_requests_and_can_be_reenabled(): void
    {
        Sanctum::actingAs($this->user(true));
        $this->change('audit', ['enabled' => false]);
        $settings = LogSettings::view(); $settings['agent']['retention_days'] = 10;
        $this->postJson(self::URL . 'settings', ['settings' => $settings])->assertOk();
        $this->assertDatabaseCount('v2_admin_audit_log', 0);
        $settings['audit']['enabled'] = true;
        $this->postJson(self::URL . 'settings', ['settings' => $settings])->assertOk();
        $this->assertDatabaseCount('v2_admin_audit_log', 1);
    }

    public function test_existing_system_logger_observes_switch_changes_and_keeps_log_level(): void
    {
        config(['logging.channels.daily.level' => 'warning']);
        $logger = Log::channel('daily');
        $path = storage_path('logs/laravel-' . date('Y-m-d') . '.log');
        $logger->info('filtered-level'); $logger->error('before-disable');
        $this->change('system', ['enabled' => false]);
        $logger->error('disabled-record');
        $this->change('system', ['enabled' => true]);
        $logger->error('after-enable');
        $text = file_get_contents($path);
        $this->assertStringContainsString('before-disable', $text);
        $this->assertStringContainsString('after-enable', $text);
        $this->assertStringNotContainsString('disabled-record', $text);
        $this->assertStringNotContainsString('filtered-level', $text);
        $this->change('system', ['enabled' => false]);
        Cache::store('redis')->forget(\App\Support\Setting::CACHE_KEY);
        $this->failNextQueryMatching('/select .*v2_settings/i');
        $logger->error('policy-failure-record');
        $this->assertStringNotContainsString('policy-failure-record', file_get_contents($path));
    }

    public function test_system_cleanup_expires_known_files_without_new_writes_and_can_continue(): void
    {
        $this->change('system', ['enabled' => false, 'retention_days' => 2]);
        $keep = 'laravel-' . now()->subDay()->toDateString() . '.log';
        foreach ([$keep, 'backup.log', 'custom.log'] as $name) file_put_contents(storage_path('logs/' . $name), 'retained');
        foreach (range(2, 502) as $days) file_put_contents(storage_path('logs/laravel-' . now()->subDays($days)->toDateString() . '.log'), 'expired');
        file_put_contents(storage_path('logs/deprecations-' . now()->subDays(2)->toDateString() . '.log'), 'expired');
        $pruner = app(SystemLogPruner::class);
        $this->assertSame(500, $pruner->prune(2));
        $this->assertSame(2, $pruner->prune(2));
        $this->assertSame(0, $pruner->prune(2));
        foreach ([$keep, 'backup.log', 'custom.log'] as $name) $this->assertFileExists(storage_path('logs/' . $name));
        Sanctum::actingAs($this->user(true));
        $this->getJson(self::URL . 'system?date=' . now()->subDays(2)->toDateString())->assertUnprocessable();
        $this->getJson(self::URL . 'system?date=' . now()->subDay()->toDateString())->assertOk()->assertJsonPath('data.retention_days', 2);
    }

    public function test_cleanup_failure_can_retry_without_deleting_retained_records(): void
    {
        $this->change('audit', ['retention_days' => 1]);
        $this->audit(now()->timestamp - 86401); $this->audit(now()->timestamp);
        $this->failNextQueryMatching('/delete from ["`]?v2_admin_audit_log/i');
        try {
            \Illuminate\Support\Facades\Artisan::call('management-log:prune');
            $this->fail('清理失败应返回错误');
        } catch (\Illuminate\Database\QueryException) {
            $this->assertDatabaseCount('v2_admin_audit_log', 2);
        }
        $this->artisan('management-log:prune')->assertSuccessful();
        $this->assertDatabaseCount('v2_admin_audit_log', 1);
    }
}
