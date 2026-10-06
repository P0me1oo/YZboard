<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // 保留旧统计；新记录保存上报时的入口、倍率和真实扣费，不反推历史。
        Schema::create('v2_stat_user_route_source', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('entry_id');
            $table->unsignedInteger('server_id');
            $table->string('kind', 8);
            $table->string('rate', 32);
            $table->unsignedInteger('record_at');
            foreach (['u', 'd', 'billed_u', 'billed_d'] as $column) {
                $table->unsignedBigInteger($column)->default(0);
            }
            $table->unique(['user_id', 'entry_id', 'server_id', 'kind', 'rate', 'record_at'], 'stat_user_route_source_unique');
            $table->index(['record_at', 'kind', 'user_id'], 'stat_user_route_source_range');
            $table->index(['user_id', 'record_at'], 'stat_user_route_source_user');
        });
        Schema::create('v2_stat_node_hour', function (Blueprint $table) {
            $table->unsignedInteger('record_at')->primary();
            $table->unsignedBigInteger('u')->default(0);
            $table->unsignedBigInteger('d')->default(0);
        });
        foreach (['user_route_statistics_started_at', 'node_hourly_started_at'] as $name) {
            DB::table('v2_settings')->insertOrIgnore([
                'name' => $name, 'type' => 'integer', 'value' => (string) now()->timestamp,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_stat_user_route_source');
        Schema::dropIfExists('v2_stat_node_hour');
        DB::table('v2_settings')->whereIn('name', ['user_route_statistics_started_at', 'node_hourly_started_at'])->delete();
    }
};
