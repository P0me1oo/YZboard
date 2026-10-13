<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_inbound_ip', function (Blueprint $table) {
            $table->text('ip_properties')->nullable();
        });
        // 保留现有地区和 ASN，属性由原有的限额、分批查询逐步补齐。
        DB::table('v2_inbound_ip')->update(['lookup_after' => 0]);
    }

    public function down(): void
    {
        Schema::table('v2_inbound_ip', function (Blueprint $table) {
            $table->dropColumn('ip_properties');
        });
    }
};
