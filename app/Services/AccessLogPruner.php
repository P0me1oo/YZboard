<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AccessLogPruner
{
    public const PENDING_SETTING = 'access_log_cleanup_pending';
    public const LOCK_KEY = 'runtime:access-log-prune';

    /** 到期边界每批重读；待办保存在数据库中，缓存丢失和进程退出后仍可续跑。 */
    public function run(bool $resume = false, int $batchSize = 500, int $maxBatches = 100, int $maxSeconds = 10): array
    {
        $lock = Cache::lock(self::LOCK_KEY, 1800);
        if (!$lock->get()) return ['deleted' => 0, 'pending' => true, 'busy' => true];
        try {
            $pending = DB::table('v2_settings')->where('name', self::PENDING_SETTING);
            if ($resume && !$pending->exists()) return ['deleted' => 0, 'pending' => false, 'busy' => false];
            // 先登记，再开始删除。失败时保留待办，不在异常路径重新依赖一次写入。
            DB::table('v2_settings')->updateOrInsert(['name' => self::PENDING_SETTING], ['value' => '1']);
            $batchSize = max(1, min(500, $batchSize));
            $maxBatches = max(1, min(1000, $maxBatches));
            $maxSeconds = max(1, min(300, $maxSeconds));
            $started = hrtime(true);
            $deleted = 0;
            for ($batch = 0; $batch < $maxBatches; $batch++) {
                $count = $this->deleteBatch($batchSize);
                $deleted += $count;
                if ($count < $batchSize) {
                    $pending->delete();
                    return ['deleted' => $deleted, 'pending' => false, 'busy' => false];
                }
                // 时间预算不打断正在提交的批次，只阻止启动下一批。
                if (hrtime(true) - $started >= $maxSeconds * 1_000_000_000) break;
            }
            return ['deleted' => $deleted, 'pending' => true, 'busy' => false];
        } catch (\Throwable $exception) {
            Log::warning('访问日志清理未完成，将继续处理', ['exception' => get_class($exception)]);
            throw $exception;
        } finally {
            $lock->release();
        }
    }

    protected function deleteBatch(int $size): int
    {
        return DB::transaction(function () use ($size): int {
            $cutoff = now()->timestamp - LogSettings::days('access') * 86400;
            $query = DB::table('v2_proxy_access_log')->where('connected_at', '<', $cutoff);
            $ids = (clone $query)->orderBy('connected_at')->orderBy('id')->limit($size)->lockForUpdate()->pluck('id');
            return $ids->isEmpty() ? 0 : $query->whereIn('id', $ids)->delete();
        }, 3);
    }
}
