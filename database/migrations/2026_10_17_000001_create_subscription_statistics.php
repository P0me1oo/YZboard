<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_subscription_stat', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->char('identity', 64)->unique();
            $table->unsignedInteger('day')->index();
            $table->unsignedInteger('user_id');
            $table->string('ip', 45);
            $table->string('client', 32);
            $table->string('client_version', 32);
            $table->char('client_key', 64);
            $table->string('format', 32);
            $table->unsignedSmallInteger('status');
            $table->unsignedBigInteger('count')->default(0);
            $table->unsignedInteger('first_at');
            $table->unsignedInteger('last_at');
            $table->index(['user_id', 'day']);
            $table->index(['ip', 'day']);
        });
        Schema::create('v2_subscription_block', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('stat_id')->index();
            $table->unsignedInteger('day')->index();
            $table->unsignedInteger('first_at');
            $table->unsignedInteger('last_at');
            $table->unsignedSmallInteger('count');
            $table->unsignedTinyInteger('codec');
            $table->char('checksum', 64);
            $table->text('payload');
        });
        Schema::create('v2_subscription_batch', function (Blueprint $table): void {
            $table->char('id', 32)->primary();
            $table->unsignedInteger('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_subscription_block');
        Schema::dropIfExists('v2_subscription_batch');
        Schema::dropIfExists('v2_subscription_stat');
    }
};
