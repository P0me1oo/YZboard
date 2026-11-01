<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_balancer_dns_preset', function (Blueprint $table) {
            $table->string('provider', 16)->default('cloudflare');
            $table->string('api_url', 253)->nullable();
            $table->string('server_id', 100)->default('localhost');
        });
        Schema::table('v2_balancer_pool', function (Blueprint $table) {
            // 旧池继续工作，管理员准备好委托和端口后再显式转换。
            $table->string('mode', 16)->default('legacy');
            $table->unsignedInteger('dns_ttl')->default(60);
            $table->boolean('ddns_enabled')->default(false);
            $table->boolean('personal_domain_enabled')->default(false);
            $table->boolean('follow_user_status')->default(false);
        });
        Schema::table('v2_balancer_member', function (Blueprint $table) {
            $table->json('dns_addresses')->nullable();
            $table->json('healthy_addresses')->nullable();
            $table->timestamp('checked_at')->nullable();
        });
        Schema::table('v2_ddns_binding', function (Blueprint $table) {
            $table->boolean('enabled')->default(false)->change();
            $table->boolean('personal_enabled')->default(false);
            $table->boolean('follow_user_status')->default(false);
            $table->unsignedInteger('ttl')->default(60);
        });
        // 仅保留迁移前已明确启用的旧行为；新绑定不由 DDNS 推导其他开关。
        DB::table('v2_ddns_binding')->update(['follow_user_status' => true]);
        DB::table('v2_ddns_binding')->where('enabled', true)->update(['personal_enabled' => true]);
        Schema::create('v2_powerdns_record', function (Blueprint $table) {
            $table->id();
            $table->string('owner_type', 16);
            $table->unsignedBigInteger('owner_id');
            $table->index(['owner_type', 'owner_id']);
            $table->unsignedBigInteger('preset_id');
            $table->string('name', 253);
            $table->string('type', 5);
            $table->json('contents');
            $table->string('marker', 48);
            $table->boolean('confirmed')->default(false);
            $table->unique(['preset_id', 'name', 'type']);
        });
        Schema::create('v2_pool_dns_alias', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pool_id');
            $table->unsignedBigInteger('user_id');
            $table->string('label', 32);
            $table->unique(['pool_id', 'user_id']);
            $table->unique(['pool_id', 'label']);
        });
    }

    public function down(): void
    {
        // 已切换的池不能回退为缺失转发入口的旧配置。
        if (DB::table('v2_balancer_pool')->where('mode', 'dns')->exists()
            || DB::table('v2_powerdns_record')->exists() || DB::table('v2_balancer_dns_preset')->where('provider', 'powerdns')->exists()) {
            throw new RuntimeException('请先停用并清理 DNS 调度池及 PowerDNS 托管记录，再回退迁移');
        }
        Schema::dropIfExists('v2_pool_dns_alias');
        Schema::dropIfExists('v2_powerdns_record');
        Schema::table('v2_ddns_binding', function (Blueprint $table) {
            $table->boolean('enabled')->default(true)->change();
            $table->dropColumn(['personal_enabled', 'follow_user_status', 'ttl']);
        });
        Schema::table('v2_balancer_member', fn (Blueprint $table) => $table->dropColumn(['dns_addresses', 'healthy_addresses', 'checked_at']));
        Schema::table('v2_balancer_pool', fn (Blueprint $table) => $table->dropColumn(['mode', 'dns_ttl', 'ddns_enabled', 'personal_domain_enabled', 'follow_user_status']));
        Schema::table('v2_balancer_dns_preset', fn (Blueprint $table) => $table->dropColumn(['provider', 'api_url', 'server_id']));
    }
};
