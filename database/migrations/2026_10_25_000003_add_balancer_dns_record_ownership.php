<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_balancer_dns_record', function (Blueprint $table) {
            $table->string('record_id', 64)->nullable()->change();
            $table->string('marker', 48)->nullable();
        });
    }

    public function down(): void
    {
        // 未确认的远端写入仍需保留归属，不能在回滚时丢弃。
        if (\Illuminate\Support\Facades\DB::table('v2_balancer_dns_record')->whereNull('record_id')->exists()) {
            throw new RuntimeException('请先完成前置池 DNS 同步或清理，再回滚记录归属迁移');
        }
        Schema::table('v2_balancer_dns_record', function (Blueprint $table) {
            $table->string('record_id', 64)->nullable(false)->change();
            $table->dropColumn('marker');
        });
    }
};
