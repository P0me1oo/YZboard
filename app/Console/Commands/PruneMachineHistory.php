<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneMachineHistory extends Command
{
    protected $signature = 'machine:prune-history
        {--batch=500 : 每批最多删除的历史采样数}
        {--batches=100 : 单轮最多批次数}
        {--max-seconds=20 : 达到该时长后不再开始下一批}';

    protected $description = '分批清理超过 24 小时的机器监控历史';

    public function handle(): int
    {
        $size = max(1, min(500, (int) $this->option('batch')));
        $batches = max(1, min(1000, (int) $this->option('batches')));
        $seconds = max(1, min(300, (int) $this->option('max-seconds')));
        $started = hrtime(true);
        $cutoff = now()->subHours(24)->timestamp;
        $deleted = 0;

        for ($batch = 0; $batch < $batches; $batch++) {
            $count = DB::transaction(function () use ($size, $cutoff): int {
                $query = DB::table('v2_server_machine_load_history')->where('recorded_at', '<', $cutoff);
                $ids = (clone $query)->orderBy('recorded_at')->orderBy('id')
                    ->limit($size)->lockForUpdate()->pluck('id');
                return $ids->isEmpty() ? 0 : $query->whereIn('id', $ids)->delete();
            }, 3);
            $deleted += $count;
            if ($count < $size || hrtime(true) - $started >= $seconds * 1_000_000_000) {
                break;
            }
        }

        $this->info("已清理 {$deleted} 条超过 24 小时的机器监控历史。");
        return self::SUCCESS;
    }
}
