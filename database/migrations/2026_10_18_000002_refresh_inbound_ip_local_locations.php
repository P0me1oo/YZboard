<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 只让旧位置缓存到期，历史出现时间、网络属性和记录保持原值。
        DB::table('v2_inbound_ip')->update(['local_expires_at' => 0]);
    }

    public function down(): void
    {
        DB::table('v2_inbound_ip')->update(['local_expires_at' => 0]);
        // 旧代码依赖外部服务提供 IPv6 地区，回退时让其重新查询。
        DB::table('v2_inbound_ip')->where('ip_version', 6)
            ->update(['external_expires_at' => 0, 'lookup_after' => 0]);
    }
};
