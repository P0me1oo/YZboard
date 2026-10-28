<?php

namespace App\Console\Commands;

use App\Services\UserEventLog;
use App\Services\LogSettings;
use App\Services\SystemLogPruner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneManagementLogs extends Command
{
    protected $signature = 'management-log:prune';
    protected $description = '按保存天数分批清理到期的管理日志和系统日志';

    public function handle(): int
    {
        $settings = LogSettings::all();
        foreach (['v2_agent_log' => 'agent', 'v2_user_event_log' => 'user', 'v2_admin_audit_log' => 'audit'] as $table => $module) {
            $days = $settings[$module]['retention_days'];
            $cutoff = now()->timestamp - $days * 86400;
            $at = $module === 'audit' ? 'created_at' : 'occurred_at';
            for ($round = 0; $round < 20; $round++) {
                $ids = DB::table($table)->where($at, '<', $cutoff)->orderBy($at)->limit(500)->pluck('id');
                if ($ids->isEmpty()) break;
                DB::table($table)->whereIn('id', $ids)->delete();
            }
        }
        for ($round = 0; $round < 20; $round++) {
            // 限制通知的防重记录至少保留原来的 30 天，不随关闭记录或缩短期限而提前删除。
            $ids = DB::table('v2_node_limit_receipt')->where('accepted_at', '<', now()->timestamp - max(UserEventLog::RETENTION_DAYS, $settings['user']['retention_days']) * 86400)
                ->orderBy('accepted_at')->limit(500)->pluck('id');
            if ($ids->isEmpty()) break;
            DB::table('v2_node_limit_receipt')->whereIn('id', $ids)->delete();
        }
        app(SystemLogPruner::class)->prune($settings['system']['retention_days']);
        return self::SUCCESS;
    }
}
