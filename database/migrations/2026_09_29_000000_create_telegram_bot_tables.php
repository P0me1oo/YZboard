<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_telegram_bot_configs', function (Blueprint $table) {
            $table->integer('id')->primary();
            $table->text('token')->nullable();
            $table->text('webhook_secret')->nullable();
            $table->string('webhook_url', 2048)->nullable();
            $table->bigInteger('bot_id')->nullable();
            $table->string('bot_username')->nullable();
            $table->boolean('enabled')->default(false);
            $table->boolean('webhook_registered')->default(false);
            $table->boolean('webhook_matches')->nullable();
            $table->integer('pending_update_count')->nullable();
            $table->integer('last_checked_at')->nullable();
            $table->integer('last_received_at')->nullable();
            $table->string('last_error')->nullable();
            $table->integer('created_at');
            $table->integer('updated_at');
        });
        DB::table('v2_telegram_bot_configs')->insert([
            'id' => 1, 'created_at' => time(), 'updated_at' => time(),
        ]);

        Schema::create('v2_telegram_bot_bindings', function (Blueprint $table) {
            $table->id();
            // 用户主键是有符号 integer，必须保持外键类型一致。
            $table->integer('user_id')->unique();
            $table->bigInteger('telegram_id')->unique();
            $table->text('unbind_token')->nullable();
            $table->integer('unbind_expires_at')->nullable();
            $table->integer('created_at');
            $table->integer('updated_at');
            $table->foreign('user_id')->references('id')->on('v2_user')->cascadeOnDelete();
        });

        Schema::create('v2_telegram_bot_updates', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('bot_id');
            $table->bigInteger('update_id');
            $table->bigInteger('telegram_id');
            $table->string('action', 32);
            // 不设绑定外键：解绑后仍需保留消息防重记录。
            $table->unsignedBigInteger('binding_id')->nullable();
            $table->integer('sent_at')->nullable();
            $table->integer('created_at')->index();
            $table->integer('updated_at');
            $table->unique(['bot_id', 'update_id'], 'telegram_bot_update_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_telegram_bot_updates');
        Schema::dropIfExists('v2_telegram_bot_bindings');
        Schema::dropIfExists('v2_telegram_bot_configs');
    }
};
