<?php

namespace Tests\Feature\Admin;

use App\Http\Routes\V2\AdminRoute;
use App\Models\ServerMachine;
use App\Models\ServerMachineLoadHistory;
use App\Models\User;
use App\Services\DatabaseMaintenance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DatabaseMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/_tests/database-admin/database/';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
        // 独立路由集合，避免开发环境既有路由缓存遮住新增接口。
        $router = app('router');
        $router->setRoutes(new RouteCollection());
        admin_setting(['secure_path' => 'database-admin']);
        Route::group(['prefix' => '_tests'], fn () => (new AdminRoute())->map($router));
    }

    private function login(bool $admin = true): User
    {
        $user = User::create(['email' => Str::random(12) . '@database.example.invalid',
            'password' => password_hash('test-only', PASSWORD_DEFAULT), 'uuid' => Str::uuid(),
            'token' => Str::random(32), 'is_admin' => $admin, 'balance' => 1234, 'u' => 5678, 'd' => 9012]);
        Sanctum::actingAs($user);
        return $user;
    }

    private function audit(int $at): int
    {
        return DB::table('v2_admin_audit_log')->insertGetId(['admin_id' => 1, 'action' => 'test.history',
            'method' => 'POST', 'uri' => '/test', 'created_at' => $at, 'updated_at' => $at]);
    }

    private function preview(array $targets): array
    {
        return $this->postJson(self::URL . 'preview', ['targets' => $targets])->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->json('data');
    }

    private function cleanup(string $token)
    {
        return $this->postJson(self::URL . 'cleanup', ['token' => $token, 'confirmed' => true]);
    }

    public function test_every_endpoint_rejects_guests_and_non_admin_users(): void
    {
        foreach ([null, false] as $role) {
            if ($role !== null) $this->login(false);
            $this->getJson(self::URL . 'overview')->assertForbidden();
            $this->postJson(self::URL . 'preview', ['targets' => ['audit']])->assertForbidden();
            $this->cleanup(str_repeat('a', 48))->assertForbidden();
        }
    }

    public function test_overview_reports_current_database_tables_without_scanning_rows_or_exposing_connection(): void
    {
        $this->login();
        $queries = [];
        DB::listen(function ($query) use (&$queries) { $queries[] = strtolower($query->sql); });
        $response = $this->getJson(self::URL . 'overview')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $data = $response->json('data');
        $this->assertSame(DB::connection()->getDriverName(), $data['driver']);
        $this->assertGreaterThan(0, $data['total_bytes']);
        $this->assertSame(count($data['tables']), $data['table_count']);
        $tables = collect($data['tables'])->keyBy('name');
        $this->assertSame('v2_user', $tables['v2_user']['schema_name']);
        $this->assertSame('v2_admin_audit_log', $tables['v2_admin_audit_log']['schema_name']);
        $this->assertSame('audit', $tables['v2_admin_audit_log']['cleanup_target']);
        $this->assertNull($tables['v2_user']['cleanup_target']);
        $this->assertArrayNotHasKey('database', $data);
        $this->assertArrayNotHasKey('password', $data);
        $this->assertFalse(collect($queries)->contains(fn ($sql) => str_contains($sql, 'count(*)')));
        if ($data['driver'] === 'mysql') {
            $this->assertNull($data['free_bytes']);
            $this->assertSame(array_sum(array_column($data['tables'], 'total_bytes')), $data['total_bytes']);
        } else {
            $this->assertIsInt($data['free_bytes']);
            $this->assertNull($tables['v2_user']['rows']);
        }
    }

    public function test_overview_normalizes_prefixed_table_names_without_changing_cleanup_permissions(): void
    {
        $connection = DB::connection();
        $prefix = $connection->getTablePrefix();
        $connection->setTablePrefix('v2_');
        try {
            $tables = collect(app(DatabaseMaintenance::class)->overview()['tables'])->keyBy('name');
            $this->assertSame('user', $tables['v2_user']['schema_name']);
            $this->assertSame('admin_audit_log', $tables['v2_admin_audit_log']['schema_name']);
            $this->assertNull($tables['v2_admin_audit_log']['cleanup_target']);
            $this->assertSame('migrations', $tables['migrations']['schema_name']);
        } finally {
            $connection->setTablePrefix($prefix);
        }
    }

    public function test_preview_is_read_only_cleanup_preserves_boundary_and_new_rows_and_repeats_safely(): void
    {
        $admin = $this->login();
        $before = $admin->fresh()->getAttributes();
        $cutoff = strtotime('-3 month', now()->timestamp);
        $old = $this->audit($cutoff - 1);
        $boundary = $this->audit($cutoff);
        $recent = $this->audit(now()->timestamp);
        $preview = $this->preview(['audit']);
        $this->assertSame(1, $preview['items'][0]['count']);
        $this->assertArrayNotHasKey('max_id', $preview['items'][0]);
        $this->assertArrayNotHasKey('ids', $preview['items'][0]);
        $this->assertDatabaseHas('v2_admin_audit_log', ['id' => $old]);
        $newOld = $this->audit($cutoff - 10);
        $this->cleanup($preview['token'])->assertOk()->assertJsonPath('data.deleted', 1)->assertJsonPath('data.has_more', false);
        $this->assertDatabaseMissing('v2_admin_audit_log', ['id' => $old]);
        foreach ([$boundary, $recent, $newOld] as $id) $this->assertDatabaseHas('v2_admin_audit_log', ['id' => $id]);
        $this->app->forgetInstance(DatabaseMaintenance::class);
        $this->cleanup($preview['token'])->assertOk()->assertJsonPath('data.deleted', 1);
        $this->assertDatabaseHas('v2_admin_audit_log', ['id' => $newOld]);
        $this->assertSame($before, $admin->fresh()->getAttributes());
        $this->cleanup($this->preview(['audit'])['token'])->assertOk()->assertJsonPath('data.deleted', 1);
        $this->assertDatabaseHas('v2_admin_audit_log', ['id' => $boundary]);
    }

    public function test_invalid_targets_missing_confirmation_expiry_and_other_admin_cannot_delete(): void
    {
        $this->login();
        $old = $this->audit(strtotime('-4 month', now()->timestamp));
        foreach ([[], ['v2_user'], ['audit', 'audit'], ['audit; DROP TABLE v2_user'], ['audit' => 'audit']] as $targets) {
            $this->postJson(self::URL . 'preview', ['targets' => $targets])->assertUnprocessable();
        }
        $preview = $this->preview(['audit']);
        $this->postJson(self::URL . 'cleanup', ['token' => $preview['token']])->assertUnprocessable();
        $this->postJson(self::URL . 'cleanup', ['token' => $preview['token'], 'confirmed' => false])->assertUnprocessable();
        $this->login();
        $this->cleanup($preview['token'])->assertUnprocessable();
        $preview = $this->preview(['audit']);
        $this->travel(601)->seconds();
        $this->cleanup($preview['token'])->assertUnprocessable();
        $this->assertDatabaseHas('v2_admin_audit_log', ['id' => $old]);
    }

    public function test_each_run_is_bounded_and_a_fresh_preview_continues(): void
    {
        $this->login();
        $old = now()->subDays(8)->timestamp;
        $rows = [];
        for ($i = 0; $i < 503; $i++) $rows[] = ['bot_id' => 1, 'update_id' => $i,
            'telegram_id' => 1, 'action' => 'test', 'created_at' => $old, 'updated_at' => $old];
        foreach (array_chunk($rows, 100) as $chunk) DB::table('v2_telegram_bot_updates')->insert($chunk);
        $this->cleanup($this->preview(['telegram'])['token'])->assertOk()->assertJsonPath('data.deleted', 500)->assertJsonPath('data.has_more', true);
        $this->cleanup($this->preview(['telegram'])['token'])->assertOk()->assertJsonPath('data.deleted', 3)->assertJsonPath('data.has_more', false);
        $this->cleanup($this->preview(['telegram'])['token'])->assertOk()->assertJsonPath('data.deleted', 0);
    }

    public function test_retry_cannot_advance_to_another_batch_if_result_was_not_cached_after_commit(): void
    {
        $this->login();
        $old = now()->subDays(8)->timestamp;
        $rows = [];
        for ($i = 0; $i < 503; $i++) $rows[] = ['bot_id' => 1, 'update_id' => $i,
            'telegram_id' => 1, 'action' => 'test', 'created_at' => $old, 'updated_at' => $old];
        foreach (array_chunk($rows, 100) as $chunk) DB::table('v2_telegram_bot_updates')->insert($chunk);
        $preview = $this->preview(['telegram']);
        $this->cleanup($preview['token'])->assertOk()->assertJsonPath('data.deleted', 500);
        $connection = DB::connection();
        $key = 'yz:database-maintenance:' . hash('sha256', $connection->getName() . ':' . $connection->getDatabaseName()) . ':' . $preview['token'];
        $cached = Cache::get($key);
        unset($cached['result']);
        Cache::put($key, $cached, 600);
        $this->cleanup($preview['token'])->assertOk()->assertJsonPath('data.deleted', 0)->assertJsonPath('data.has_more', true);
        $this->assertDatabaseCount('v2_telegram_bot_updates', 3);
        $this->cleanup($this->preview(['telegram'])['token'])->assertOk()->assertJsonPath('data.deleted', 3);
    }

    public function test_failure_rolls_back_all_categories_and_same_preview_can_retry(): void
    {
        $this->login();
        $audit = $this->audit(strtotime('-4 month', now()->timestamp));
        $at = now()->subDays(8)->timestamp;
        DB::table('v2_telegram_bot_updates')->insert(['bot_id' => 1, 'update_id' => 1, 'telegram_id' => 1,
            'action' => 'test', 'created_at' => $at, 'updated_at' => $at]);
        $preview = $this->preview(['audit', 'telegram']);
        $this->failNextQueryMatching('/delete from [`"]v2_telegram_bot_updates[`"] /i');
        $response = $this->cleanup($preview['token'])->assertStatus(503);
        $this->assertStringNotContainsString('SQL', $response->getContent());
        $this->assertDatabaseHas('v2_admin_audit_log', ['id' => $audit]);
        $this->assertDatabaseCount('v2_telegram_bot_updates', 1);
        $this->cleanup($preview['token'])->assertOk()->assertJsonPath('data.deleted', 2);
    }

    public function test_access_and_machine_retention_preserves_live_machine_state(): void
    {
        $this->login();
        $machine = ServerMachine::create(['name' => '分析测试', 'token' => Str::random(32), 'is_active' => true,
            'last_seen_at' => now()->timestamp, 'load_status' => ['cpu' => 12]]);
        $before = $machine->fresh()->getAttributes();
        foreach ([now()->subHours(24)->subSecond()->timestamp, now()->subHours(24)->timestamp] as $at) {
            ServerMachineLoadHistory::create(['machine_id' => $machine->id, 'cpu' => 12, 'recorded_at' => $at]);
        }
        foreach ([now()->subDays(7)->subSecond()->timestamp, now()->subDays(7)->timestamp] as $position => $at) {
            DB::table('v2_proxy_access_log')->insert(['server_id' => 1, 'server_name' => 'test', 'batch_id' => str_repeat('a', 32),
                'position' => $position, 'user_id' => 1, 'connected_at' => $at, 'destination' => 'example.invalid', 'port' => 443]);
        }
        $preview = $this->preview(['access', 'machine']);
        $this->assertSame([1, 1], array_column($preview['items'], 'count'));
        $this->cleanup($preview['token'])->assertOk()->assertJsonPath('data.deleted', 2);
        $this->assertDatabaseCount('v2_proxy_access_log', 1);
        $this->assertDatabaseCount('v2_server_machine_load_history', 1);
        $this->assertSame($before, $machine->fresh()->getAttributes());
    }

    public function test_cleanup_lock_rejects_overlap_and_missing_table_is_explicit(): void
    {
        $admin = $this->login();
        $preview = $this->preview(['audit']);
        $connection = DB::connection();
        $key = 'yz:database-maintenance:' . hash('sha256', $connection->getName() . ':' . $connection->getDatabaseName()) . ':lock';
        $lock = Cache::lock($key, 30);
        $this->assertTrue($lock->get());
        try { $this->cleanup($preview['token'])->assertStatus(429); } finally { $lock->release(); }
        $this->cleanup($preview['token'])->assertOk();
        Schema::shouldReceive('hasTable')->with('v2_telegram_bot_updates')->once()->andReturn(false);
        $items = app(DatabaseMaintenance::class)->preview(['telegram'], $admin->id)['items'];
        $this->assertFalse($items[0]['available']);
        $this->assertSame(0, $items[0]['count']);
    }

    public function test_metadata_failures_are_explicit_and_sqlite_can_fall_back_without_table_sizes(): void
    {
        $this->login();
        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->failNextQueryMatching('/from dbstat/i');
            $response = $this->getJson(self::URL . 'overview')->assertOk()
                ->assertJsonPath('data.warnings', ['sqlite_table_sizes_unavailable']);
            $this->assertGreaterThan(0, $response->json('data.total_bytes'));
            foreach ($response->json('data.tables') as $table) $this->assertNull($table['total_bytes']);
        } else {
            $this->failNextQueryMatching('/from information_schema\.TABLES/i');
            $response = $this->getJson(self::URL . 'overview')->assertStatus(503);
            $this->assertStringNotContainsString('SQL', $response->getContent());
        }
    }

    public function test_confirmation_is_audited_without_persisting_preview_token(): void
    {
        $admin = $this->login();
        $preview = $this->preview(['audit']);
        $this->cleanup($preview['token'])->assertOk();
        $log = DB::table('v2_admin_audit_log')->where('admin_id', $admin->id)->where('uri', 'like', '%/database/cleanup')->first();
        $this->assertNotNull($log);
        $this->assertStringNotContainsString($preview['token'], $log->request_data);
        $this->assertSame(['confirmed' => true], json_decode($log->request_data, true));
    }
}
