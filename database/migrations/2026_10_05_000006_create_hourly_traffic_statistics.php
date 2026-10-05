<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_stat_traffic_hour', function (Blueprint $table) {
            $table->unsignedInteger('record_at')->primary();
            $table->unsignedBigInteger('u')->default(0);
            $table->unsignedBigInteger('d')->default(0);
        });
        DB::table('v2_settings')->insertOrIgnore([
            'name' => 'traffic_hourly_started_at', 'type' => 'integer',
            'value' => (string) now()->timestamp, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_stat_traffic_hour');
        DB::table('v2_settings')->where('name', 'traffic_hourly_started_at')->delete();
    }
};
