<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_telegram_bot_bindings', function (Blueprint $table) {
            $table->text('reset_token')->nullable()->comment('订阅重置确认（加密存储）');
            $table->integer('reset_expires_at')->nullable()->comment('订阅重置确认到期时间');
        });
    }

    public function down(): void
    {
        Schema::table('v2_telegram_bot_bindings', function (Blueprint $table) {
            $table->dropColumn(['reset_token', 'reset_expires_at']);
        });
    }
};
