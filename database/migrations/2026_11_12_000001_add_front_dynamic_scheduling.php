<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_balancer_member', function (Blueprint $table) {
            $table->decimal('bandwidth_mbps', 12, 2)->nullable();
            $table->text('carriers')->nullable();
        });
        Schema::table('v2_pool_dns_alias', fn (Blueprint $table) => $table->mediumText('assignment_state')->nullable());
    }

    public function down(): void
    {
        if (DB::table('v2_powerdns_record')->where('owner_type', 'pool')->exists()) {
            throw new RuntimeException('回退前请停用前置池并同步清理托管 DNS，避免丢失线路分配状态');
        }
        Schema::table('v2_pool_dns_alias', fn (Blueprint $table) => $table->dropColumn('assignment_state'));
        Schema::table('v2_balancer_member', fn (Blueprint $table) => $table->dropColumn(['bandwidth_mbps', 'carriers']));
    }
};
