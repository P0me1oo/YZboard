<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('v2_user_event_log', function (Blueprint $table) {
            $table->id();
            $table->char('event_key', 64)->nullable()->unique();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('node_id')->nullable();
            $table->unsignedBigInteger('machine_id')->nullable();
            $table->string('node_name', 255)->nullable();
            $table->string('event', 32);
            $table->string('result', 32);
            $table->string('source_ip', 45)->nullable();
            $table->unsignedBigInteger('count')->default(1);
            $table->unsignedBigInteger('limit_value')->nullable();
            $table->unsignedBigInteger('observed')->nullable();
            $table->unsignedInteger('occurred_at');
            $table->unsignedInteger('last_at');
            $table->index(['occurred_at', 'id']);
            $table->index(['user_id', 'occurred_at', 'id']);
            $table->index(['node_id', 'occurred_at', 'id']);
        });
        Schema::create('v2_agent_log', function (Blueprint $table) {
            $table->id();
            $table->char('event_key', 64)->unique();
            $table->unsignedBigInteger('machine_id')->nullable();
            $table->unsignedBigInteger('node_id')->nullable();
            $table->string('machine_name', 255)->nullable();
            $table->string('node_name', 255)->nullable();
            $table->string('source', 16);
            $table->string('level', 16);
            $table->text('message');
            $table->unsignedInteger('occurred_at');
            $table->index(['occurred_at', 'id']);
            $table->index(['machine_id', 'occurred_at', 'id']);
            $table->index(['node_id', 'occurred_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_agent_log');
        Schema::dropIfExists('v2_user_event_log');
    }
};
