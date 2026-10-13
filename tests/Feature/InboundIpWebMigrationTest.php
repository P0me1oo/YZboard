<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class InboundIpWebMigrationTest extends TestCase
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

    public function test_upgrade_and_rollback_preserve_asn_cache_and_observed_history(): void
    {
        $migration = require database_path('migrations/2026_10_14_000001_add_inbound_ip_web_cache.php');
        $migration->down();
        DB::table('v2_inbound_ip')->insert(['ip' => '8.8.8.8', 'ip_key' => bin2hex(str_pad(inet_pton('8.8.8.8'), 16, "\0")), 'ip_version' => 4, 'region' => '测试地区',
            'province' => '测试省份', 'asn' => 'AS64500', 'as_name' => 'Test Network',
            'lookup_after' => 2000000000, 'external_expires_at' => 2000000000,
            'ip_properties' => '{"is_proxy":false}']);
        DB::table('v2_stat_user_inbound_ip')->insert(['user_id' => 424242, 'ip' => '8.8.8.8',
            'record_at' => 1900000000, 'first_seen_at' => 1900000001, 'last_seen_at' => 1900000020]);
        $before = (array) DB::table('v2_inbound_ip')->first();
        $history = (array) DB::table('v2_stat_user_inbound_ip')->first();
        $migration->up();
        $row = (array) DB::table('v2_inbound_ip')->first();
        foreach ($before as $field => $value) $this->assertSame($value, $row[$field]);
        $this->assertNull($row['web_data']);
        $this->assertSame(0, (int) $row['web_expires_at']);
        $this->assertSame(0, (int) $row['web_lookup_after']);
        DB::table('v2_inbound_ip')->update(['web_data' => '{"asn":"AS64501"}', 'web_expires_at' => 2000000000]);
        $migration->down();
        $this->assertFalse(Schema::hasColumn('v2_inbound_ip', 'web_data'));
        $this->assertSame($before, (array) DB::table('v2_inbound_ip')->first());
        $this->assertSame($history, (array) DB::table('v2_stat_user_inbound_ip')->first());
        $migration->up();
    }
}
