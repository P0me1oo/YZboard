<?php

namespace Tests\Feature\Server;

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LandingDdnsMigrationTest extends TestCase
{
    use DatabaseTruncation;

    protected function beforeTruncatingDatabase(): void
    {
        if (DB::getDriverName() === 'sqlite') RefreshDatabaseState::$migrated = false;
    }

    public static function tearDownAfterClass(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDownAfterClass();
    }

    public function test_existing_binding_is_preserved_with_manual_entry_defaults(): void
    {
        // DDL 独立执行，避免 MariaDB 隐式提交破坏事务测试隔离。
        $migration = require database_path('migrations/2026_11_25_000001_add_landing_ddns.php');
        $migration->down();
        $id = DB::table('v2_ddns_binding')->insertGetId(['owner_type' => 'node', 'owner_id' => 1, 'preset_id' => 1,
            'prefix' => 'existing', 'domain' => 'existing.example.test', 'enabled' => true]);
        $migration->up();
        $binding = DB::table('v2_ddns_binding')->find($id);
        $this->assertSame('entry', $binding->scope);
        $this->assertSame('manual', $binding->address_source);
        $this->assertNull($binding->confirmed_address);
        $this->assertSame(1, (int) $binding->enabled);
        $this->assertSame('existing.example.test', $binding->domain);
    }
}
