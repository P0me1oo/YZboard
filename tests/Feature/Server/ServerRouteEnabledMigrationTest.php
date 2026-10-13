<?php

namespace Tests\Feature\Server;

use App\Models\ServerRoute;
use App\Services\ServerService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ServerRouteEnabledMigrationTest extends TestCase
{
    // 修改表结构会在 MariaDB 中隐式提交，迁移验证不能放进事务隔离的用例。
    use DatabaseTruncation;

    protected function beforeTruncatingDatabase(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            RefreshDatabaseState::$migrated = false;
        }
    }

    public static function tearDownAfterClass(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDownAfterClass();
    }

    public function test_existing_routes_remain_enabled_after_repeatable_migration(): void
    {
        $migration = require database_path('migrations/2026_10_13_000004_add_enabled_to_v2_server_route.php');
        $migration->down();
        $id = DB::table('v2_server_route')->insertGetId([
            'remarks' => '升级前路由', 'match' => '["example.invalid"]', 'action' => 'block',
            'created_at' => time(), 'updated_at' => time(),
        ]);
        $migration->up();
        $migration->up();
        $this->assertTrue(ServerRoute::findOrFail($id)->enabled);
        $this->assertCount(1, ServerService::getRoutes([$id]));
        DB::table('v2_server_route')->where('id', $id)->update(['enabled' => false]);
        $migration->up();
        $this->assertFalse(ServerRoute::findOrFail($id)->enabled);
    }
}
