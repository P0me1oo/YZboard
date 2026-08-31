<?php

namespace App\Console\Commands;

use App\Jobs\ProcessNodeReportBatch;
use App\Models\NodeReportBatch;
use Illuminate\Console\Command;

class RetryNodeReports extends Command
{
    protected $signature = 'node:retry-reports {--limit=100 : 单次最多重新派发的报告数}';

    protected $description = '重新派发未完成的节点报告，并清理已过幂等保留期的批次';

    public function handle(): int
    {
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $pendingBefore = now()->subMinutes(2);
        $failedBefore = now()->subMinutes(5);

        $ids = NodeReportBatch::query()
            ->where(function ($query) use ($pendingBefore, $failedBefore): void {
                $query->where(function ($query) use ($pendingBefore): void {
                    $query->whereIn('status', [
                        NodeReportBatch::STATUS_PENDING,
                        NodeReportBatch::STATUS_PROCESSING,
                    ])->where('updated_at', '<=', $pendingBefore);
                })->orWhere(function ($query) use ($failedBefore): void {
                    $query->where('status', NodeReportBatch::STATUS_FAILED)
                        ->where('updated_at', '<=', $failedBefore);
                });
            })
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        foreach ($ids as $id) {
            ProcessNodeReportBatch::dispatch((int) $id);
        }

        NodeReportBatch::query()
            ->where('status', NodeReportBatch::STATUS_PROCESSED)
            ->where('processed_at', '<', now()->subDays(7))
            ->delete();

        $this->info("已重新派发 {$ids->count()} 个节点报告批次。");
        return self::SUCCESS;
    }
}
