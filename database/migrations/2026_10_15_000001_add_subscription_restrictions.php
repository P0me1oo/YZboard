<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_subscription_restrictions', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 16);
            $table->string('target', 45);
            $table->string('event_id', 26);
            $table->string('rule', 64);
            $table->unsignedInteger('count');
            $table->integer('triggered_at');
            $table->integer('expires_at')->index();
            $table->unique(['kind', 'target'], 'subscription_restriction_target');
        });
        Schema::table('v2_telegram_admin_notifications', function (Blueprint $table) {
            $table->integer('user_id')->nullable()->change();
            $table->string('subject', 64)->nullable();
            $table->unique(['subject', 'telegram_id', 'kind'], 'telegram_admin_subject_unique');
        });
    }

    public function down(): void
    {
        // 旧结构只能保存有用户的节点通知。
        \Illuminate\Support\Facades\DB::table('v2_telegram_admin_notifications')->whereNotNull('subject')->delete();
        Schema::table('v2_telegram_admin_notifications', function (Blueprint $table) {
            $table->dropUnique('telegram_admin_subject_unique');
            $table->dropColumn('subject');
            $table->integer('user_id')->nullable(false)->change();
        });
        Schema::dropIfExists('v2_subscription_restrictions');
    }
};
