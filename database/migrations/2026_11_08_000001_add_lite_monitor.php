<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_lite_connection', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('url', 1024)->default('');
            $table->text('api_key')->nullable();
            $table->boolean('enabled')->default(false);
            $table->timestamps();
        });
        Schema::create('v2_lite_machine', function (Blueprint $table) {
            $table->unsignedBigInteger('machine_id')->primary();
            $table->uuid('uuid')->unique();
            $table->foreign('machine_id')->references('id')->on('v2_server_machine')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_lite_machine');
        Schema::dropIfExists('v2_lite_connection');
    }
};
