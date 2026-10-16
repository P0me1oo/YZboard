<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_server_machine_load_history', function (Blueprint $table): void {
            $table->index(['machine_id', 'recorded_at', 'id'], 'machine_history_machine_time');
        });
        Schema::table('v2_stat_user_route_source', function (Blueprint $table): void {
            $table->index(['user_id', 'record_at', 'kind', 'server_id'], 'route_source_user_time_kind');
        });
        foreach (['hour', 'minute'] as $unit) {
            Schema::table('v2_stat_route_' . $unit, function (Blueprint $table) use ($unit): void {
                $table->index(['user_id', 'record_at', 'kind'], 'route_' . $unit . '_user_time_kind');
            });
        }
        Schema::table('v2_admin_audit_log', function (Blueprint $table): void {
            $table->index(['created_at', 'id'], 'admin_audit_cleanup_time');
        });
    }

    public function down(): void
    {
        Schema::table('v2_admin_audit_log', fn (Blueprint $table) => $table->dropIndex('admin_audit_cleanup_time'));
        foreach (['hour', 'minute'] as $unit) {
            Schema::table('v2_stat_route_' . $unit, fn (Blueprint $table) => $table->dropIndex('route_' . $unit . '_user_time_kind'));
        }
        Schema::table('v2_stat_user_route_source', fn (Blueprint $table) => $table->dropIndex('route_source_user_time_kind'));
        Schema::table('v2_server_machine_load_history', fn (Blueprint $table) => $table->dropIndex('machine_history_machine_time'));
    }
};
