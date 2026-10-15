<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_server_machine', function (Blueprint $table) {
            $table->unsignedBigInteger('traffic_limit')->default(0);
            $table->string('traffic_mode', 16)->default('outbound');
            $table->unsignedTinyInteger('traffic_reset_day')->default(1);
            $table->json('traffic_interfaces')->nullable();
            $table->json('traffic_state')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('v2_server_machine', fn (Blueprint $table) => $table->dropColumn([
            'traffic_limit', 'traffic_mode', 'traffic_reset_day', 'traffic_interfaces', 'traffic_state',
        ]));
    }
};
