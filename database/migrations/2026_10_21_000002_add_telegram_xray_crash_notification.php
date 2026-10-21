<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('v2_telegram_bot_configs', function (Blueprint $table) {
            $table->boolean('notify_xray_crash')->default(false);
        });
        Schema::table('v2_agent_log', function (Blueprint $table) {
            $table->string('event', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('v2_agent_log', fn (Blueprint $table) => $table->dropColumn('event'));
        Schema::table('v2_telegram_bot_configs', fn (Blueprint $table) => $table->dropColumn('notify_xray_crash'));
    }
};
