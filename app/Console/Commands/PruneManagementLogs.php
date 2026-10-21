<?php

namespace App\Console\Commands;

use App\Services\AgentLog;
use App\Services\UserEventLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneManagementLogs extends Command
{
    protected $signature = 'management-log:prune';
    protected $description = '分批清理到期的 Agent 和用户日志';

    public function handle(): int
    {
        foreach (['v2_agent_log' => AgentLog::RETENTION_DAYS, 'v2_user_event_log' => UserEventLog::RETENTION_DAYS] as $table => $days) {
            $cutoff = now()->subDays($days)->timestamp;
            for ($round = 0; $round < 20; $round++) {
                $ids = DB::table($table)->where('occurred_at', '<', $cutoff)->orderBy('occurred_at')->limit(500)->pluck('id');
                if ($ids->isEmpty()) break;
                DB::table($table)->whereIn('id', $ids)->delete();
            }
        }
        for ($round = 0; $round < 20; $round++) {
            $ids = DB::table('v2_node_limit_receipt')->where('accepted_at', '<', now()->subDays(UserEventLog::RETENTION_DAYS)->timestamp)
                ->orderBy('accepted_at')->limit(500)->pluck('id');
            if ($ids->isEmpty()) break;
            DB::table('v2_node_limit_receipt')->whereIn('id', $ids)->delete();
        }
        return self::SUCCESS;
    }
}
