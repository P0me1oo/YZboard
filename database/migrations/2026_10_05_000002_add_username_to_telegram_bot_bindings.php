<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_telegram_bot_bindings', function (Blueprint $table) {
            $table->string('telegram_username', 32)->nullable()->comment('最近一次私聊互动时的 Telegram 用户名');
        });
    }

    public function down(): void
    {
        Schema::table('v2_telegram_bot_bindings', function (Blueprint $table) {
            $table->dropColumn('telegram_username');
        });
    }
};
