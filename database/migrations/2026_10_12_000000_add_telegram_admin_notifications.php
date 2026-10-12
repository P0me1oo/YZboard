<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_telegram_bot_configs', function (Blueprint $table) {
            $table->boolean('notify_admin_device')->default(false);
            $table->boolean('notify_admin_connection')->default(false);
            $table->json('notify_admin_recipients')->nullable();
            $table->unsignedInteger('notify_admin_interval_minutes')->default(60);
        });
        Schema::create('v2_telegram_admin_notifications', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id');
            $table->bigInteger('telegram_id');
            $table->string('kind', 16);
            $table->string('status', 16)->default('pending');
            $table->json('details')->nullable();
            $table->integer('sent_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->integer('retry_at');
            $table->integer('created_at');
            $table->integer('updated_at');
            // 各接收人分别保存结果；设备与连接分别冷却，跨节点共用。
            $table->unique(['user_id', 'telegram_id', 'kind'], 'telegram_admin_notification_unique');
            $table->index(['status', 'retry_at'], 'telegram_admin_notification_pending');
            $table->foreign('user_id')->references('id')->on('v2_user')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_telegram_admin_notifications');
        Schema::table('v2_telegram_bot_configs', function (Blueprint $table) {
            $table->dropColumn(['notify_admin_device', 'notify_admin_connection',
                'notify_admin_recipients', 'notify_admin_interval_minutes']);
        });
    }
};
