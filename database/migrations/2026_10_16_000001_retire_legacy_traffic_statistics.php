<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // 仅移除旧口径专用汇总；用户账本、线路明细和运营概览的日记录继续保留。
        foreach (['v2_stat_traffic_hour', 'v2_stat_node_hour', 'v2_stat_node_hour_detail', 'v2_stat_node_minute_detail'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::table('v2_settings')->whereIn('name', ['traffic_hourly_started_at', 'node_hourly_started_at'])->delete();
    }

    public function down(): void
    {
        // 回退只重建空表，不从日总量伪造已删除的细分历史。
        foreach (['v2_stat_traffic_hour', 'v2_stat_node_hour'] as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->unsignedInteger('record_at')->primary();
                $table->unsignedBigInteger('u')->default(0);
                $table->unsignedBigInteger('d')->default(0);
            });
        }
        foreach (['hour', 'minute'] as $unit) {
            Schema::create('v2_stat_node_' . $unit . '_detail', function (Blueprint $table) use ($unit) {
                $table->unsignedInteger('record_at');
                $table->unsignedInteger('server_id');
                $table->unsignedBigInteger('u')->default(0);
                $table->unsignedBigInteger('d')->default(0);
                $table->primary(['record_at', 'server_id'], 'node_' . $unit . '_key');
            });
        }
        foreach (['traffic_hourly_started_at', 'node_hourly_started_at'] as $name) {
            DB::table('v2_settings')->insertOrIgnore([
                'name' => $name, 'type' => 'integer', 'value' => (string) now()->timestamp,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }
};
