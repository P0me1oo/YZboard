<?php

namespace App\Console\Commands;

use App\Models\NodeReportBatch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneNodeReports extends Command
{
    protected $signature = 'node:prune-reports
        {--batch=500 : 每批最多处理的报告数}
        {--batches=100 : 单轮最多批次数}
        {--max-seconds=20 : 达到该时长后不再开始下一批}';

    protected $description = '分批清空到期节点报告的详细内容，永久保留防重编号';

    public function handle(): int
    {
        $size = max(1, min(500, (int) $this->option('batch')));
        $batches = max(1, min(1000, (int) $this->option('batches')));
        $seconds = max(1, min(300, (int) $this->option('max-seconds')));
        $started = hrtime(true);
        $cutoff = now()->timestamp;
        $registered = $cleared = 0;

        for ($batch = 0; $batch < $batches; $batch++) {
            // 先消化已到期报告，再推进旧记录登记，避免旧数据整理阻塞日常清理。
            $pruned = $this->pruneBatch($size, $cutoff);
            $prepared = $this->registerLegacyBatch($size);
            $cleared += $pruned;
            $registered += $prepared;
            if (($pruned === 0 && $prepared === 0) || hrtime(true) - $started >= $seconds * 1_000_000_000) {
                break;
            }
        }

        $this->info("已登记 {$registered} 条旧报告，清空 {$cleared} 条报告详细内容；防重编号保持不变。");
        return self::SUCCESS;
    }

    private function registerLegacyBatch(int $size): int
    {
        return DB::transaction(function () use ($size): int {
            $reports = NodeReportBatch::query()
                ->where('status', NodeReportBatch::STATUS_PROCESSED)
                ->where('payload_cleanup_at', 0)
                ->whereNotNull('processed_at')
                ->orderBy('id')->limit($size)->lockForUpdate()
                ->select(['id', 'processed_at'])
                // 只读取有无详细内容，不把历史 JSON 下载到 PHP 内存中。
                ->selectRaw('(server_snapshot IS NOT NULL OR traffic IS NOT NULL OR relay_traffic IS NOT NULL OR relay_user_traffic IS NOT NULL) AS has_payload')
                ->get();
            if ($reports->isEmpty()) {
                return 0;
            }

            $cases = [];
            foreach ($reports as $report) {
                $at = $report->has_payload
                    ? (string) $report->processed_at->copy()->addDays(NodeReportBatch::PAYLOAD_RETENTION_DAYS)->timestamp
                    : 'NULL';
                // CASE 仅拼接数据库整数主键和计算得到的整数时间，不插入报告内容。
                $cases[] = 'WHEN ' . (int) $report->id . ' THEN ' . $at;
            }

            return DB::table('v2_node_report_batch')
                ->whereIn('id', $reports->modelKeys())
                ->where('status', NodeReportBatch::STATUS_PROCESSED)
                ->where('payload_cleanup_at', 0)
                ->update(['payload_cleanup_at' => DB::raw('CASE id ' . implode(' ', $cases) . ' END')]);
        }, 3);
    }

    private function pruneBatch(int $size, int $cutoff): int
    {
        return DB::transaction(function () use ($size, $cutoff): int {
            $query = DB::table('v2_node_report_batch')
                ->where('status', NodeReportBatch::STATUS_PROCESSED)
                ->where('payload_cleanup_at', '>', 0)
                ->where('payload_cleanup_at', '<=', $cutoff);
            $ids = (clone $query)->orderBy('payload_cleanup_at')->orderBy('id')
                ->limit($size)->lockForUpdate()->pluck('id');
            if ($ids->isEmpty()) {
                return 0;
            }

            // 内容与清理标记一起更新；失败回滚后仍可重试，不改变结算状态和防重键。
            return $query->whereIn('id', $ids)->update([
                'server_snapshot' => null,
                'traffic' => null,
                'relay_traffic' => null,
                'relay_user_traffic' => null,
                'payload_cleanup_at' => null,
            ]);
        }, 3);
    }
}
