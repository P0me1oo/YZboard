<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // 旧结果未提取扩展属性，沿用后台额度分批补查；不删除已有数据或出现历史。
        DB::table('v2_inbound_ip')->whereNotNull('web_data')->where('web_expires_at', '>', 0)
            ->select(['ip', 'web_data', 'web_expires_at', 'web_lookup_after'])
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    $data = json_decode($row->web_data, true);
                    if (is_array($data) && ($data['properties_version'] ?? null) === 2) continue;
                    DB::table('v2_inbound_ip')->where('ip', $row->ip)->where('web_data', $row->web_data)
                        ->where('web_expires_at', $row->web_expires_at)->where('web_lookup_after', $row->web_lookup_after)
                        ->update(['web_expires_at' => 0, 'web_lookup_after' => 0]);
                }
            }, 'ip');
    }

    public function down(): void
    {
        // 不恢复旧缓存有效期，不修改属性、地区、ASN 或出现历史。
    }
};
