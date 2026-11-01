<?php

namespace Tests\Feature\Server;

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DnsSchedulingMigrationTest extends TestCase
{
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

    public function test_old_flags_are_preserved_but_new_bindings_default_off_and_rollback_is_guarded(): void
    {
        $migration = require database_path('migrations/2026_11_01_000002_add_dns_scheduling.php');
        $migration->down();
        $base = ['owner_type' => 'node', 'owner_id' => 1, 'preset_id' => 1, 'prefix' => 'old', 'domain' => 'old.example.com'];
        $active = DB::table('v2_ddns_binding')->insertGetId($base + ['enabled' => true]);
        $retained = DB::table('v2_ddns_binding')->insertGetId([...$base, 'owner_id' => 2, 'domain' => 'retained.example.com', 'enabled' => false]);
        $migration->up();
        $this->assertDatabaseHas('v2_ddns_binding', ['id' => $active, 'enabled' => true, 'personal_enabled' => true, 'follow_user_status' => true]);
        $this->assertDatabaseHas('v2_ddns_binding', ['id' => $retained, 'enabled' => false, 'personal_enabled' => false, 'follow_user_status' => true]);
        $new = DB::table('v2_ddns_binding')->insertGetId([...$base, 'owner_id' => 3, 'domain' => 'new.example.com']);
        $this->assertDatabaseHas('v2_ddns_binding', ['id' => $new, 'enabled' => false, 'personal_enabled' => false, 'follow_user_status' => false]);
        DB::table('v2_powerdns_record')->insert(['owner_type' => 'pool', 'owner_id' => 1, 'preset_id' => 1,
            'name' => 'pool.example.com', 'type' => 'A', 'contents' => '["192.0.2.1"]', 'marker' => 'YZDNS:test']);
        try { $migration->down(); $this->fail('待处理的托管记录不能丢失'); }
        catch (\RuntimeException) { $this->assertTrue(Schema::hasTable('v2_powerdns_record')); }
        DB::table('v2_powerdns_record')->delete();
        $migration->down();
        $migration->up();
    }
}
