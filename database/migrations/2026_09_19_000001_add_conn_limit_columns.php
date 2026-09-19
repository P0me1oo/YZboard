<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * 为套餐和用户增加连接数限制字段。
     *
     * conn_limit 是并发连接数上限，conn_rate_limit 是每秒新建连接数上限。
     * 两者都为空或 0 表示不限制，节点侧保持原有行为。
     */
    public function up(): void
    {
        Schema::table('v2_plan', function (Blueprint $table) {
            if (!Schema::hasColumn('v2_plan', 'conn_limit')) {
                $table->unsignedInteger('conn_limit')->nullable()->after('device_limit')->comment('并发连接数上限，空或 0 表示不限制');
            }
            if (!Schema::hasColumn('v2_plan', 'conn_rate_limit')) {
                $table->unsignedInteger('conn_rate_limit')->nullable()->after('conn_limit')->comment('每秒新建连接数上限，空或 0 表示不限制');
            }
        });

        Schema::table('v2_user', function (Blueprint $table) {
            if (!Schema::hasColumn('v2_user', 'conn_limit')) {
                $table->integer('conn_limit')->nullable()->after('device_limit')->comment('并发连接数上限，空或 0 表示不限制');
            }
            if (!Schema::hasColumn('v2_user', 'conn_rate_limit')) {
                $table->integer('conn_rate_limit')->nullable()->after('conn_limit')->comment('每秒新建连接数上限，空或 0 表示不限制');
            }
        });
    }

    public function down(): void
    {
        Schema::table('v2_user', function (Blueprint $table) {
            $table->dropColumn(['conn_limit', 'conn_rate_limit']);
        });
        Schema::table('v2_plan', function (Blueprint $table) {
            $table->dropColumn(['conn_limit', 'conn_rate_limit']);
        });
    }
};
