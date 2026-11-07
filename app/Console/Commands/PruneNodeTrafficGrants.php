<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneNodeTrafficGrants extends Command
{
    protected $signature = 'node:prune-traffic-grants';
    protected $description = '清理已过期的节点流量补报授权';

    public function handle(): int
    {
        // 单次有界删除，下一分钟继续，避免大表清理长期持锁。
        $rows = DB::table('v2_node_traffic_grant')->where('expires_at', '<=', now()->timestamp)->limit(1000)->get();
        foreach ($rows as $row) {
            DB::table('v2_node_traffic_grant')->where('server_id', $row->server_id)->where('machine_id', $row->machine_id)
                ->where('user_id', $row->user_id)->where('expires_at', '<=', now()->timestamp)->delete();
        }
        return self::SUCCESS;
    }
}
