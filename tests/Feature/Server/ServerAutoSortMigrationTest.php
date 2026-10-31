<?php

namespace Tests\Feature\Server;

use App\Models\Server;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ServerAutoSortMigrationTest extends TestCase
{
    // 修改表结构会在 MariaDB 隐式提交，使用与项目其他迁移用例一致的隔离方式。
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

    public function test_upgrade_preserves_old_sort_and_excludes_existing_nodes(): void
    {
        $node = Server::create([
            'name' => '香港旧节点', 'sort' => 37, 'type' => Server::TYPE_VMESS,
            'host' => '127.0.0.1', 'port' => 443, 'server_port' => 443, 'rate' => 1,
        ]);
        $migration = require database_path('migrations/2026_10_28_000001_add_auto_sort_pending_to_servers.php');
        $migration->down();
        admin_setting(['server_auto_sort_enable' => true]);
        $migration->up();
        $this->assertSame(37, (int) $node->fresh()->sort);
        $this->assertFalse($node->fresh()->auto_sort_pending);
        \App\Services\ServerAutoSort::onReport($node->fresh());
        $this->assertSame(37, (int) $node->fresh()->sort);
    }
}
