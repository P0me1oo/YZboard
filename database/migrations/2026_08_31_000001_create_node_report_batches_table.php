<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_node_report_batch', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('server_id');
            $table->string('report_id', 128);
            $table->char('report_key', 64);
            $table->string('server_type', 32);
            $table->json('server_snapshot')->nullable();
            $table->json('traffic')->nullable();
            $table->json('relay_traffic')->nullable();
            $table->unsignedInteger('record_at');
            $table->string('status', 16)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['server_id', 'report_key'], 'node_report_server_key_unique');
            $table->index(['status', 'updated_at'], 'node_report_retry_index');
            $table->index('processed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_node_report_batch');
    }
};
