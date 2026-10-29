<?php

namespace App\Console\Commands;

use App\Services\UserEventLog;
use App\Services\LogSettings;
use App\Services\SystemLogPruner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PruneManagementLogs extends Command
{
    public const PENDING_SETTING = 'management_log_cleanup_pending';
    public const LOCK_KEY = 'runtime:management-log-prune';

    protected $signature = 'management-log:prune
        {--resume : 仅继续尚未完成的清理}
        {--batch=500 : 每批最多删除的数据库记录数}
        {--batches=100 : 单轮最多批次数}
        {--max-seconds=10 : 达到该时长后不再开始下一批}';
    protected $description = '按保存天数分批清理到期日志，达到预算或失败后继续执行';

    public function handle(): int
    {
        $lock = Cache::lock(self::LOCK_KEY, 1800);
        if (!$lock->get()) return self::SUCCESS;
        try {
            $pending = DB::table('v2_settings')->where('name', self::PENDING_SETTING)->first();
            if ($this->option('resume') && $pending === null) return self::SUCCESS;
            $cursor = (int) ($pending->value ?? 0) % 5;
            // 删除前登记待办；缓存重建、进程退出或某批失败都不会丢失续跑位置。
            $this->saveCursor($cursor);
            return $this->prune($cursor);
        } catch (\Throwable $exception) {
            Log::warning('管理日志清理未完成，将继续处理', ['exception' => get_class($exception)]);
            throw $exception;
        } finally {
            $lock->release();
        }
    }

    private function prune(int $cursor): int
    {
        $size = max(1, min(500, (int) $this->option('batch')));
        $batches = max(1, min(1000, (int) $this->option('batches')));
        $seconds = max(1, min(300, (int) $this->option('max-seconds')));
        $started = hrtime(true);
        $remaining = array_fill_keys(range(0, 4), true);
        $deleted = $files = 0;
        for ($round = 0; $round < $batches; $round++) {
            while (!isset($remaining[$cursor])) $cursor = ($cursor + 1) % 5;
            // 续跑和各批次读取当前保留期限，延长期限后不沿用旧的删除边界。
            $settings = LogSettings::all();
            if ($cursor === 4) {
                $count = app(SystemLogPruner::class)->prune($settings['system']['retention_days']);
                $files += $count;
                if ($count < 500) unset($remaining[$cursor]);
            } else {
                [$table, $column, $module] = [
                    ['v2_agent_log', 'occurred_at', 'agent'],
                    ['v2_user_event_log', 'occurred_at', 'user'],
                    ['v2_admin_audit_log', 'created_at', 'audit'],
                    ['v2_node_limit_receipt', 'accepted_at', 'user'],
                ][$cursor];
                $days = $settings[$module]['retention_days'];
                if ($cursor === 3) $days = max(UserEventLog::RETENTION_DAYS, $days);
                $count = $this->deleteBatch($table, $column, now()->timestamp - $days * 86400, $size);
                $deleted += $count;
                if ($count < $size) unset($remaining[$cursor]);
            }
            $cursor = ($cursor + 1) % 5;
            if ($remaining === []) {
                DB::table('v2_settings')->where('name', self::PENDING_SETTING)->delete();
                break;
            }
            // 轮转保存位置，让大表积压期间其他日志也能得到清理。
            $this->saveCursor($cursor);
            if (hrtime(true) - $started >= $seconds * 1_000_000_000) break;
        }
        $this->info("已清理 {$deleted} 条到期记录、{$files} 份系统日志。" . ($remaining === [] ? '' : '剩余待办将在下一分钟继续处理。'));
        return self::SUCCESS;
    }

    private function deleteBatch(string $table, string $column, int $cutoff, int $size): int
    {
        return DB::transaction(function () use ($table, $column, $cutoff, $size): int {
            $query = DB::table($table)->where($column, '<', $cutoff);
            $ids = (clone $query)->orderBy($column)->orderBy('id')->limit($size)->lockForUpdate()->pluck('id');
            return $ids->isEmpty() ? 0 : $query->whereIn('id', $ids)->delete();
        }, 3);
    }

    private function saveCursor(int $cursor): void
    {
        DB::table('v2_settings')->updateOrInsert(['name' => self::PENDING_SETTING], ['value' => (string) $cursor]);
    }
}
