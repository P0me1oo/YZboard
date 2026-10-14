<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_telegram_bot_configs', function (Blueprint $table) {
            $table->boolean('notify_ticket')->default(false);
            $table->boolean('allow_ticket_reply')->default(false);
            $table->boolean('notify_payment')->default(false);
        });
        Schema::create('v2_telegram_business_notifications', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('bot_id');
            $table->unsignedBigInteger('binding_id');
            $table->bigInteger('telegram_id');
            $table->string('kind', 16);
            $table->unsignedBigInteger('event_id');
            $table->string('status', 16)->default('pending');
            $table->bigInteger('telegram_message_id')->nullable();
            $table->integer('sent_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->integer('retry_at');
            $table->integer('created_at');
            $table->integer('updated_at');
            $table->unique(['bot_id', 'binding_id', 'kind', 'event_id'], 'telegram_business_event_unique');
            $table->index(['status', 'retry_at'], 'telegram_business_pending');
            $table->index(['bot_id', 'telegram_id', 'telegram_message_id'], 'telegram_business_reply');
            // 保留原关系编号用于防重和回复溯源；解绑后旧任务不能转给新绑定。
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_telegram_business_notifications');
        Schema::table('v2_telegram_bot_configs', function (Blueprint $table) {
            $table->dropColumn(['notify_ticket', 'allow_ticket_reply', 'notify_payment']);
        });
    }
};
