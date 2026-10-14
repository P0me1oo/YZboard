<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // 旧网页摘要缺少逐项检测结果，交回原后台任务分批补查，保留已有数据和历史。
        DB::table('v2_inbound_ip')->whereNotNull('web_data')->where('web_expires_at', '>', 0)
            ->select(['ip', 'web_data', 'web_expires_at', 'web_lookup_after'])
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    $data = json_decode($row->web_data, true);
                    if (is_array($data) && ($data['source'] ?? null) === 'ip2location.com') continue;
                    DB::table('v2_inbound_ip')->where('ip', $row->ip)->where('web_data', $row->web_data)
                        ->where('web_expires_at', $row->web_expires_at)->where('web_lookup_after', $row->web_lookup_after)
                        ->update(['web_expires_at' => 0, 'web_lookup_after' => 0]);
                }
            }, 'ip');
    }

    public function down(): void
    {
        // 不恢复旧摘要结果的有效期，也不修改地区、ASN 或历史记录。
    }
};
