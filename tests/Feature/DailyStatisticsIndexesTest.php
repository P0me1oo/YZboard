<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DailyStatisticsIndexesTest extends TestCase
{
    // MariaDB 的 DDL 隐式提交，迁移验证不放进 RefreshDatabase 的外层事务。
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

    private function migration(): object
    {
        return require database_path('migrations/2026_11_01_000001_reduce_daily_statistics_indexes.php');
    }

    public function test_migration_and_rollback_preserve_data_and_business_unique_keys(): void
    {
        $migration = $this->migration();
        DB::table('v2_stat_server')->insert(['server_id' => 1, 'server_type' => 'vless', 'u' => 12, 'd' => 34,
            'record_type' => 'd', 'record_at' => 1234567890, 'created_at' => 1234567890, 'updated_at' => 1234567890]);
        $before = DB::table('v2_stat_server')->get()->toJson();
        try {
            $migration->down();
            $this->assertTrue(Schema::hasIndex('v2_stat_server', 'v2_stat_server_u_index'));
            $this->assertTrue(Schema::hasIndex('v2_stat_user', 'v2_stat_user_d_index'));
            $migration->up();
            $migration->up();
            foreach (['v2_stat_server', 'v2_stat_user'] as $table) {
                foreach (['u', 'd'] as $column) $this->assertFalse(Schema::hasIndex($table, $table . '_' . $column . '_index'));
            }
            foreach (['server_id', 'record_at'] as $column) {
                $this->assertTrue(Schema::hasIndex('v2_stat_server', $column));
                $this->assertFalse(Schema::hasIndex('v2_stat_server', 'v2_stat_server_' . $column . '_index'));
            }
            $this->assertTrue(Schema::hasIndex('v2_stat_server', ['server_id', 'server_type', 'record_at'], 'unique'));
            if (DB::connection()->getDriverName() === 'mysql') {
                $this->assertTrue(Schema::hasIndex('v2_stat_user', ['server_rate', 'user_id', 'record_at'], 'unique'));
            }
            $this->assertSame($before, DB::table('v2_stat_server')->get()->toJson());
            $migration->down();
            $this->assertTrue(Schema::hasIndex('v2_stat_server', 'v2_stat_server_server_id_index'));
            $this->assertSame($before, DB::table('v2_stat_server')->get()->toJson());
        } finally {
            $migration->up();
        }
    }

    public function test_migration_keeps_the_only_remaining_server_index(): void
    {
        $migration = $this->migration();
        $migration->down();
        Schema::table('v2_stat_server', fn (Blueprint $table) => $table->dropIndex('server_id'));
        try {
            $migration->up();
            $this->assertTrue(Schema::hasIndex('v2_stat_server', 'v2_stat_server_server_id_index'));
        } finally {
            Schema::table('v2_stat_server', fn (Blueprint $table) => $table->index('server_id', 'server_id'));
            $migration->up();
        }
    }
}
