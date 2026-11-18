<?php

namespace App\Console\Commands;

use App\Jobs\ProcessNodeReportBatch;
use App\Models\NodeReportBatch;
use Illuminate\Console\Command;

class RetryNodeReports extends Command
{
    protected $signature = 'node:retry-reports {--limit=100 : 单次最多重新派发的报告数}';

    protected $description = '重新派发未完成的节点报告';

    public function handle(): int
    {
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $pendingBefore = now()->subMinutes(10);
        $failedBefore = now()->subMinutes(5);

        $ids = NodeReportBatch::query()
            ->where('attempts', '<', NodeReportBatch::MAX_ATTEMPTS)
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
            ->get(['id', 'updated_at']);

        $dispatched = 0;
        foreach ($ids as $batch) {
            $claimed = NodeReportBatch::whereKey($batch->id)->where('updated_at', $batch->updated_at)
                ->where('status', '!=', NodeReportBatch::STATUS_PROCESSED)
                ->where('attempts', '<', NodeReportBatch::MAX_ATTEMPTS)->update(['updated_at' => now()]);
            if (!$claimed) continue;
            ProcessNodeReportBatch::dispatch((int) $batch->id);
            $dispatched++;
        }

        $this->info("已重新派发 {$dispatched} 个节点报告批次。");
        return self::SUCCESS;
    }
}
