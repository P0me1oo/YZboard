<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PanelBugFixMigrationTest extends TestCase
{
    // MariaDB 修改表结构会隐式提交，迁移检查不使用事务隔离。
    use DatabaseTruncation;

    protected function beforeTruncatingDatabase(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') RefreshDatabaseState::$migrated = false;
    }

    public static function tearDownAfterClass(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDownAfterClass();
    }

    public function test_limit_receipt_migration_preserves_existing_logs_on_rollback(): void
    {
        DB::table('v2_user_event_log')->insert(['user_id' => 101, 'event' => 'login', 'result' => 'success',
            'count' => 1, 'occurred_at' => now()->timestamp, 'last_at' => now()->timestamp]);
        $migration = require database_path('migrations/2026_10_21_000004_create_node_limit_receipts.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('v2_node_limit_receipt'));
        $this->assertDatabaseCount('v2_user_event_log', 1);
        $migration->up();
        $this->assertTrue(Schema::hasTable('v2_node_limit_receipt'));
        $this->assertDatabaseCount('v2_node_limit_receipt', 0);
        $this->assertDatabaseCount('v2_user_event_log', 1);
    }
}
