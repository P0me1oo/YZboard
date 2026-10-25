<?php

namespace Tests\Feature\Server;

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DnsOwnershipMigrationTest extends TestCase
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

    public function test_upgrade_preserves_legacy_ownership_and_rollback_refuses_pending_writes(): void
    {
        $migration = require database_path('migrations/2026_10_25_000003_add_balancer_dns_record_ownership.php');
        $migration->down();
        $legacy = ['pool_id' => 1, 'record_id' => 'legacy-owned-record', 'zone_id' => str_repeat('a', 32),
            'name' => 'entry.example.com', 'type' => 'A', 'content' => '192.0.2.10'];
        $id = DB::table('v2_balancer_dns_record')->insertGetId($legacy);
        $migration->up();
        $this->assertDatabaseHas('v2_balancer_dns_record', [...$legacy, 'id' => $id, 'marker' => null]);
        $pendingId = DB::table('v2_balancer_dns_record')->insertGetId([...$legacy, 'pool_id' => 2,
            'record_id' => null, 'name' => 'pending.example.com', 'marker' => 'YZPOOL:' . bin2hex(random_bytes(16))]);
        try { $migration->down(); $this->fail('待确认写入必须保留归属'); }
        catch (\RuntimeException $error) { $this->assertStringContainsString('先完成前置池 DNS 同步或清理', $error->getMessage()); }
        $this->assertTrue(Schema::hasColumn('v2_balancer_dns_record', 'marker'));
        $this->assertDatabaseCount('v2_balancer_dns_record', 2);
        DB::table('v2_balancer_dns_record')->where('id', $pendingId)->update(['record_id' => 'confirmed-record']);
        $migration->down();
        $this->assertFalse(Schema::hasColumn('v2_balancer_dns_record', 'marker'));
        $this->assertDatabaseHas('v2_balancer_dns_record', $legacy);
        $migration->up();
    }
}
