<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_telegram_bot_configs', function (Blueprint $table) {
            $table->boolean('remind_expiring')->default(false);
            $table->boolean('remind_expired')->default(false);
            $table->boolean('remind_device')->default(false);
            $table->boolean('remind_connection')->default(false);
            $table->unsignedInteger('remind_days')->default(3);
            $table->unsignedInteger('remind_interval_minutes')->default(60);
            $table->integer('remind_expired_since')->nullable();
        });
        Schema::create('v2_telegram_bot_reminders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('binding_id');
            $table->string('kind', 16);
            // 到期类按到期时间防重；超限类使用 0，复用同一条冷却记录。
            $table->integer('cycle');
            $table->string('status', 16)->default('pending');
            $table->json('details')->nullable();
            $table->integer('sent_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->integer('retry_at');
            $table->integer('created_at');
            $table->integer('updated_at');
            $table->unique(['binding_id', 'kind', 'cycle'], 'telegram_reminder_unique');
            $table->index(['status', 'retry_at'], 'telegram_reminder_pending');
            $table->foreign('binding_id')->references('id')->on('v2_telegram_bot_bindings')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_telegram_bot_reminders');
        Schema::table('v2_telegram_bot_configs', function (Blueprint $table) {
            $table->dropColumn(['remind_expiring', 'remind_expired', 'remind_device', 'remind_connection',
                'remind_days', 'remind_interval_minutes', 'remind_expired_since']);
        });
    }
};
