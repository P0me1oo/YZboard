<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        foreach (['hour', 'minute'] as $unit) {
            Schema::create('v2_stat_node_' . $unit . '_detail', function (Blueprint $table) use ($unit) {
                $table->unsignedInteger('record_at');
                $table->unsignedInteger('server_id');
                $table->unsignedBigInteger('u')->default(0);
                $table->unsignedBigInteger('d')->default(0);
                $table->primary(['record_at', 'server_id'], 'node_' . $unit . '_key');
            });
            Schema::create('v2_stat_route_' . $unit, function (Blueprint $table) use ($unit) {
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
                $table->unique(['user_id', 'entry_id', 'server_id', 'kind', 'rate', 'record_at'], 'route_' . $unit . '_key');
                $table->index('record_at', 'route_' . $unit . '_time');
            });
        }
        DB::table('v2_settings')->insertOrIgnore([
            'name' => 'fine_traffic_started_at', 'type' => 'integer', 'value' => (string) now()->timestamp,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        foreach (['hour', 'minute'] as $unit) {
            Schema::dropIfExists('v2_stat_node_' . $unit . '_detail');
            Schema::dropIfExists('v2_stat_route_' . $unit);
        }
        DB::table('v2_settings')->where('name', 'fine_traffic_started_at')->delete();
    }
};
