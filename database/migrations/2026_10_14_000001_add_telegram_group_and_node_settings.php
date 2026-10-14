<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_telegram_bot_configs', function (Blueprint $table) {
            $table->boolean('auto_approve_join')->default(false);
            $table->json('join_group_ids')->nullable();
            $table->boolean('notify_node_offline')->default(false);
            $table->unsignedInteger('node_offline_minutes')->default(30);
        });
        Schema::create('v2_telegram_node_states', function (Blueprint $table) {
            $table->unsignedBigInteger('server_id')->primary();
            $table->integer('last_seen_at');
            $table->foreign('server_id')->references('id')->on('v2_server')->cascadeOnDelete();
        });
        Schema::create('v2_telegram_node_incidents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('server_id');
            $table->integer('last_seen_at');
            $table->unique(['server_id', 'last_seen_at'], 'telegram_node_incident_unique');
            $table->foreign('server_id')->references('id')->on('v2_server')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_telegram_node_incidents');
        Schema::dropIfExists('v2_telegram_node_states');
        Schema::table('v2_telegram_bot_configs', function (Blueprint $table) {
            $table->dropColumn(['auto_approve_join', 'join_group_ids', 'notify_node_offline', 'node_offline_minutes']);
        });
    }
};
