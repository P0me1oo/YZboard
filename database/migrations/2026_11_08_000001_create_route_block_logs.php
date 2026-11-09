<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('v2_route_block_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('node_id');
            $table->string('node_name');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('rule_id');
            $table->string('rule_name')->nullable();
            $table->string('destination', 253);
            $table->unsignedSmallInteger('port');
            $table->string('network', 3);
            $table->unsignedInteger('occurred_at');
            $table->char('batch_id', 32);
            $table->unsignedSmallInteger('position');
            $table->unique(['node_id', 'batch_id', 'position'], 'route_block_batch');
            $table->index(['occurred_at', 'id'], 'route_block_time');
            $table->index(['user_id', 'occurred_at', 'id'], 'route_block_user');
            $table->index(['node_id', 'occurred_at', 'id'], 'route_block_node');
            $table->index(['rule_id', 'occurred_at', 'id'], 'route_block_rule');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_route_block_log');
    }
};
