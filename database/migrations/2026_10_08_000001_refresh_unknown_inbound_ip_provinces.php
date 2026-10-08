<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // 已有归属地却未能分类的 IPv4 交由原本地查询重算，不等待 30 天缓存到期。
        DB::table('v2_inbound_ip')->where('ip_version', 4)->where('province', '未知')
            ->where('region', '<>', '未知')->update(['local_expires_at' => 0]);
    }

    public function down(): void
    {
        // 缓存失效无需回滚，不恢复错误分类，也不修改历史记录。
    }
};
