<?php

namespace Tests\Feature\Server;

use App\Models\ServerMachine;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class AgentLogPolicyMigrationTest extends TestCase
{
    // MariaDB 修改表结构会隐式提交，迁移用例采用清理表数据的隔离方式。
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

    public function test_empty_migration_can_rollback_and_reapply_without_enabling_privacy(): void
    {
        $migration = require database_path('migrations/2026_10_27_000001_add_agent_log_disabled_to_machines.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('v2_server_machine', 'agent_log_disabled'));
        $migration->up();
        $machine = ServerMachine::create(['name' => '日志迁移测试', 'token' => Str::random(32), 'is_active' => true]);
        $this->assertFalse($machine->agent_log_disabled);
    }
}
