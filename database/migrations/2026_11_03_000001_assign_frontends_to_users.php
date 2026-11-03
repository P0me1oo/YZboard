<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // 外部 DNS 无法在迁移事务内安全转换；先核对再改表，不删除线路或解析。
        if (DB::table('v2_balancer_pool')->where('mode', '!=', 'dns')->exists()
            || DB::table('v2_balancer_dns_record')->exists()
            || DB::table('v2_ddns_binding')->where('owner_type', 'pool')->exists()
            || DB::table('v2_server')->whereNotNull('balancer_role')->exists()
            || DB::table('v2_balancer_member')->where(fn ($q) => $q->whereNull('runtime_node_id')->orWhereColumn('runtime_node_id', '!=', 'source_node_id'))->exists()) {
            throw new RuntimeException('升级前请在旧版本将转发池转换为 PowerDNS 池并清理旧解析、重新保存 DNS 池以复用原节点；本次未删除任何池或线路');
        }
        if (DB::table('v2_balancer_pool')->leftJoin('v2_balancer_dns_preset as preset', 'preset.id', '=', 'v2_balancer_pool.dns_preset_id')
            ->where(fn ($q) => $q->whereNull('preset.id')->orWhere('preset.provider', '!=', 'powerdns'))->exists()) {
            throw new RuntimeException('升级前请为现有前置池配置 PowerDNS，保留原线路后再重试');
        }
        if (DB::table('v2_balancer_pool')->whereRaw('LENGTH(domain) > 218')->exists()) throw new RuntimeException('请先缩短过长的池域名，确保可以生成用户独立域名');
        Schema::table('v2_balancer_member', function (Blueprint $table) {
            $table->unsignedInteger('weight')->default(1);
            $table->string('dns_label', 32)->nullable();
        });
        Schema::table('v2_pool_dns_alias', function (Blueprint $table) {
            $table->unsignedBigInteger('member_id')->nullable()->index();
        });
        Schema::table('v2_balancer_pool', function (Blueprint $table) {
            $table->string('mode', 16)->default('dns')->change();
            $table->boolean('personal_domain_enabled')->default(true)->change();
        });
        DB::table('v2_balancer_pool')->update(['personal_domain_enabled' => true]);
    }

    public function down(): void
    {
        if (DB::table('v2_balancer_pool')->where('enabled', true)->exists()
            || DB::table('v2_pool_dns_alias')->whereNotNull('member_id')->exists()
            || DB::table('v2_powerdns_record')->where('owner_type', 'pool')->exists()) {
            throw new RuntimeException('回退前请停用前置池并同步清理用户和成员解析');
        }
        Schema::table('v2_pool_dns_alias', function (Blueprint $table) {
            $table->dropIndex(['member_id']);
            $table->dropColumn('member_id');
        });
        Schema::table('v2_balancer_member', fn (Blueprint $table) => $table->dropColumn(['weight', 'dns_label']));
        Schema::table('v2_balancer_pool', function (Blueprint $table) {
            $table->string('mode', 16)->default('legacy')->change();
            $table->boolean('personal_domain_enabled')->default(false)->change();
        });
    }
};
