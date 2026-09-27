<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_server_route', function (Blueprint $table) {
            // 与目标地址同时满足才命中；为空表示不限，已有路由的匹配结果不变。
            if (!Schema::hasColumn('v2_server_route', 'protocol')) {
                $table->text('protocol')->nullable()->after('match');
            }
            if (!Schema::hasColumn('v2_server_route', 'port')) {
                $table->string('port')->nullable()->after('protocol');
            }
            if (!Schema::hasColumn('v2_server_route', 'network')) {
                $table->string('network', 8)->nullable()->after('port');
            }
            // 已有路由一律关闭，升级后新建节点的默认路由保持为空。
            if (!Schema::hasColumn('v2_server_route', 'apply_to_new_nodes')) {
                $table->boolean('apply_to_new_nodes')->default(false)->after('action_value');
            }
        });
    }

    public function down(): void
    {
        Schema::table('v2_server_route', function (Blueprint $table) {
            foreach (['protocol', 'port', 'network', 'apply_to_new_nodes'] as $column) {
                if (Schema::hasColumn('v2_server_route', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
