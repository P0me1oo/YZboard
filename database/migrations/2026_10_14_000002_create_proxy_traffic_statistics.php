<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_server', function (Blueprint $table) {
            // 入口和中转分开采样，暂存有符号差值，不能逐批截断后再累计。
            $table->bigInteger('usage_u')->default(0);
            $table->bigInteger('usage_d')->default(0);
        });
        Schema::table('v2_server_machine', function (Blueprint $table) {
            $table->unsignedBigInteger('proxy_u')->default(0);
            $table->unsignedBigInteger('proxy_d')->default(0);
        });
        Schema::create('v2_stat_proxy_traffic', function (Blueprint $table) {
            $table->string('scope', 8);
            $table->string('unit', 8);
            $table->unsignedInteger('record_at');
            $table->unsignedInteger('entity_id');
            $table->string('entity_name');
            $table->bigInteger('u')->default(0);
            $table->bigInteger('d')->default(0);
            $table->primary(['scope', 'unit', 'record_at', 'entity_id'], 'proxy_traffic_key');
            $table->index('record_at', 'proxy_traffic_time');
        });
        DB::table('v2_settings')->insertOrIgnore([
            'name' => 'proxy_traffic_started_at', 'type' => 'integer', 'value' => (string) now()->timestamp,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_stat_proxy_traffic');
        Schema::table('v2_server', fn (Blueprint $table) => $table->dropColumn(['usage_u', 'usage_d']));
        Schema::table('v2_server_machine', fn (Blueprint $table) => $table->dropColumn(['proxy_u', 'proxy_d']));
        DB::table('v2_settings')->where('name', 'proxy_traffic_started_at')->delete();
    }
};
