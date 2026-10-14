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

    public function test_details_cache_repair_handles_multiple_batches_and_preserves_full_results(): void
    {
        $incomplete = json_encode(['asn' => 'AS64500', 'ip_properties' => ['usage_type' => null, 'is_proxy' => false]]);
        $complete = json_encode(['source' => 'ip2location.com', 'ip_properties' => ['usage_type' => 'ISP/MOB']]);
        $rows = [];
        foreach (range(1, 205) as $n) {
            $ip = '1.1.1.' . $n;
            $rows[] = ['ip' => $ip, 'ip_key' => bin2hex(str_pad(inet_pton($ip), 16, "\0")), 'ip_version' => 4,
                'web_data' => $incomplete, 'web_expires_at' => 2000000000, 'web_lookup_after' => 2000000000];
        }
        foreach (['8.8.8.8' => $complete, '9.9.9.9' => null] as $ip => $data) {
            $rows[] = ['ip' => $ip, 'ip_key' => bin2hex(str_pad(inet_pton($ip), 16, "\0")), 'ip_version' => 4,
                'web_data' => $data, 'web_expires_at' => 2000000000, 'web_lookup_after' => 2000000000];
        }
        DB::table('v2_inbound_ip')->insert($rows);
        // 即使原网络类型已知，旧摘要的细分检测项仍需重新补齐。
        $rows[0]['web_data'] = json_encode(['ip_properties' => ['usage_type' => 'DCH', 'is_proxy' => false]]);
        DB::table('v2_inbound_ip')->where('ip', $rows[0]['ip'])->update(['web_data' => $rows[0]['web_data']]);
        $unaffected = DB::table('v2_inbound_ip')->whereIn('ip', ['8.8.8.8', '9.9.9.9'])->orderBy('ip')->get()->toArray();
        $migration = require database_path('migrations/2026_10_14_000003_refresh_inbound_ip_web_details.php');
        $migration->up();
        $migration->up();
        $migration->down();
        $this->assertSame(205, DB::table('v2_inbound_ip')->where('web_expires_at', 0)->where('web_lookup_after', 0)->count());
        $this->assertSame(204, DB::table('v2_inbound_ip')->where('web_data', $incomplete)->count());
        $this->assertSame($rows[0]['web_data'], DB::table('v2_inbound_ip')->where('ip', $rows[0]['ip'])->value('web_data'));
        $this->assertEquals($unaffected, DB::table('v2_inbound_ip')->whereIn('ip', ['8.8.8.8', '9.9.9.9'])->orderBy('ip')->get()->toArray());
    }
}
