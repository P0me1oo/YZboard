<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_node_report_batch', function (Blueprint $table): void {
            // 保留默认值 0，回退期间由旧程序写入的报告也能在再次升级后分批登记。
            $table->unsignedBigInteger('payload_cleanup_at')->nullable()->default(0);
            $table->index(['status', 'payload_cleanup_at', 'id'], 'node_report_payload_cleanup_index');
        });

        Schema::table('v2_server_machine_load_history', function (Blueprint $table): void {
            $table->index(['recorded_at', 'id'], 'machine_history_cleanup_index');
        });
    }

    public function down(): void
    {
        Schema::table('v2_server_machine_load_history', function (Blueprint $table): void {
            $table->dropIndex('machine_history_cleanup_index');
        });
        Schema::table('v2_node_report_batch', function (Blueprint $table): void {
            $table->dropIndex('node_report_payload_cleanup_index');
            $table->dropColumn('payload_cleanup_at');
        });
    }
};
