<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneNodeHealth extends Command
{
    protected $signature = 'node-health:prune';
    protected $description = '清理超过一天的链路检查记录';
    public function handle(): int
    {
        do {
            $ids = DB::table('v2_node_health_sample')->where('checked_at', '<', now()->timestamp - 86400)->limit(1000)->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }DB::table('v2_node_health_sample')->whereIn('id', $ids)->delete();
        } while ($ids->count() === 1000);
        return self::SUCCESS;
    }
}
