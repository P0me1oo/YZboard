<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_server_machine', function (Blueprint $table) {
            $table->boolean('agent_log_disabled')->default(false);
        });
    }

    public function down(): void
    {
        if (DB::table('v2_server_machine')->where('agent_log_disabled', true)->exists()) {
            throw new RuntimeException('已有机器永久停用运行日志，不能回滚此设置');
        }
        Schema::table('v2_server_machine', fn (Blueprint $table) => $table->dropColumn('agent_log_disabled'));
    }
};
