<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_proxy_access_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('server_id');
            $table->string('server_name');
            $table->char('batch_id', 32);
            $table->unsignedSmallInteger('position');
            $table->unsignedInteger('user_id');
            $table->unsignedBigInteger('connected_at');
            $table->string('destination', 253);
            $table->unsignedSmallInteger('port');
            $table->unique(['server_id', 'batch_id', 'position'], 'proxy_access_batch');
            $table->index(['connected_at', 'id'], 'proxy_access_time');
            $table->index(['user_id', 'connected_at'], 'proxy_access_user');
            $table->index(['server_id', 'connected_at'], 'proxy_access_server');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_proxy_access_log');
    }
};
