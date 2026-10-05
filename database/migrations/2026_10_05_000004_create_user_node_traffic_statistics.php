<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_stat_user_server', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('server_id');
            // 入口计入用户总量；落地只用于线路明细，不重复计费。
            $table->string('kind', 8);
            $table->unsignedInteger('record_at');
            $table->unsignedBigInteger('u')->default(0);
            $table->unsignedBigInteger('d')->default(0);
            $table->unsignedBigInteger('billed_u')->default(0);
            $table->unsignedBigInteger('billed_d')->default(0);
            $table->unique(['user_id', 'server_id', 'kind', 'record_at'], 'stat_user_server_day');
            $table->index(['record_at', 'kind', 'user_id'], 'stat_user_server_range');
        });
        DB::table('v2_settings')->insertOrIgnore([
            'name' => 'traffic_statistics_started_at', 'type' => 'integer',
            'value' => (string) now()->timestamp, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_stat_user_server');
        DB::table('v2_settings')->where('name', 'traffic_statistics_started_at')->delete();
    }
};
