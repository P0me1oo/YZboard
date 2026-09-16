<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * 为管理员两步验证（TOTP）增加字段。
     *
     * 密钥与恢复码只在管理员主动绑定后写入，普通用户不使用这些字段。
     */
    public function up(): void
    {
        Schema::table('v2_user', function (Blueprint $table) {
            if (!Schema::hasColumn('v2_user', 'totp_secret')) {
                $table->text('totp_secret')->nullable()->after('is_admin')->comment('两步验证密钥（加密存储）');
            }
            if (!Schema::hasColumn('v2_user', 'totp_enabled_at')) {
                $table->integer('totp_enabled_at')->nullable()->after('totp_secret')->comment('两步验证启用时间');
            }
            if (!Schema::hasColumn('v2_user', 'totp_recovery_codes')) {
                $table->text('totp_recovery_codes')->nullable()->after('totp_enabled_at')->comment('两步验证恢复码（哈希后存储）');
            }
        });
    }

    public function down(): void
    {
        Schema::table('v2_user', function (Blueprint $table) {
            $table->dropColumn(['totp_secret', 'totp_enabled_at', 'totp_recovery_codes']);
        });
    }
};
