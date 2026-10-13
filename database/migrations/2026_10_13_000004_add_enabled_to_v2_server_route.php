<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('v2_server_route', 'enabled')) {
            Schema::table('v2_server_route', function (Blueprint $table) {
                // 已有路由保持生效，升级不改变节点原有的流量处理。
                $table->boolean('enabled')->default(true)->after('action_value');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('v2_server_route', 'enabled')) {
            Schema::table('v2_server_route', function (Blueprint $table) {
                $table->dropColumn('enabled');
            });
        }
    }
};
