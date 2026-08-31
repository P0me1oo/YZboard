<?php

namespace App\Jobs;

use App\Models\NodeReportBatch;
use App\Models\StatServer;
use App\Models\StatUser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

class ProcessNodeReportBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public int $timeout = 300;
    public int $maxExceptions = 5;

    public function __construct(private readonly int $batchId)
    {
        $this->onQueue('traffic_fetch');
    }

    public function backoff(): array
    {
        return [5, 15, 30, 60, 120];
    }

    public function handle(): void
    {
        NodeReportBatch::query()
            ->whereKey($this->batchId)
            ->where('status', '!=', NodeReportBatch::STATUS_PROCESSED)
            ->update([
                'status' => NodeReportBatch::STATUS_PROCESSING,
                'last_error' => null,
                'attempts' => DB::raw('attempts + 1'),
                'updated_at' => now(),
            ]);

        DB::transaction(function (): void {
            $batch = NodeReportBatch::query()->lockForUpdate()->find($this->batchId);
            if (!$batch || $batch->status === NodeReportBatch::STATUS_PROCESSED) {
                return;
            }

            $userIds = $this->applyUserTraffic($batch);
            $this->applyRelayTraffic($batch);

            // Redis 集合写入可重复执行。放在数据库提交前，失败时整批回滚并由队列重试。
            if ($userIds !== []) {
                Redis::sadd('traffic:pending_check', ...$userIds);
            }

            $batch->forceFill([
                'status' => NodeReportBatch::STATUS_PROCESSED,
                'processed_at' => now(),
                'last_error' => null,
            ])->save();
        }, 5);
    }

    public function failed(?Throwable $exception): void
    {
        NodeReportBatch::query()
            ->whereKey($this->batchId)
            ->where('status', '!=', NodeReportBatch::STATUS_PROCESSED)
            ->update([
                'status' => NodeReportBatch::STATUS_FAILED,
                'last_error' => mb_substr((string) $exception?->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
    }

    /**
     * @return int[]
     */
    private function applyUserTraffic(NodeReportBatch $batch): array
    {
        $traffic = (array) $batch->traffic;
        if ($traffic === []) {
            return [];
        }

        $server = (array) $batch->server_snapshot;
        $serverId = (int) ($server['id'] ?? $batch->server_id);
        $serverType = (string) ($batch->server_type ?: ($server['type'] ?? ''));
        $rate = max(0.0, (float) ($server['rate'] ?? 1));
        $recordAt = (int) $batch->record_at;
        $now = time();
        $totalU = 0;
        $totalD = 0;
        $userIds = [];

        foreach ($traffic as $userId => $value) {
            if (!is_numeric($userId) || !is_array($value) || count($value) !== 2) {
                continue;
            }

            $u = max(0, (int) ($value[0] ?? 0));
            $d = max(0, (int) ($value[1] ?? 0));
            if ($u === 0 && $d === 0) {
                continue;
            }

            $userId = (int) $userId;
            $ratedU = (int) ($u * $rate);
            $ratedD = (int) ($d * $rate);

            DB::table('v2_user')
                ->where('id', $userId)
                ->incrementEach(['u' => $ratedU, 'd' => $ratedD], ['t' => $now]);

            $this->incrementUserStat($userId, $rate, $recordAt, $ratedU, $ratedD, $now);
            $totalU += $u;
            $totalD += $d;
            $userIds[] = $userId;
        }

        if ($totalU > 0 || $totalD > 0) {
            $this->incrementServerStat($serverId, $serverType, $recordAt, $totalU, $totalD, $now);
            DB::table('v2_server')
                ->where('id', $serverId)
                ->incrementEach(['u' => $totalU, 'd' => $totalD], ['updated_at' => now()]);
        }

        return array_values(array_unique($userIds));
    }

    private function applyRelayTraffic(NodeReportBatch $batch): void
    {
        $now = time();
        foreach ((array) $batch->relay_traffic as $item) {
            if (!is_array($item)) {
                continue;
            }

            $serverId = (int) ($item['server_id'] ?? 0);
            $serverType = (string) ($item['server_type'] ?? '');
            $u = max(0, (int) ($item['u'] ?? 0));
            $d = max(0, (int) ($item['d'] ?? 0));
            if ($serverId <= 0 || ($u === 0 && $d === 0)) {
                continue;
            }

            $this->incrementServerStat(
                $serverId,
                $serverType,
                (int) $batch->record_at,
                $u,
                $d,
                $now
            );
            DB::table('v2_server')
                ->where('id', $serverId)
                ->incrementEach(['u' => $u, 'd' => $d], ['updated_at' => now()]);
        }
    }

    private function incrementUserStat(
        int $userId,
        float $rate,
        int $recordAt,
        int $u,
        int $d,
        int $now
    ): void {
        $keys = [
            'user_id' => $userId,
            'server_rate' => $rate,
            'record_at' => $recordAt,
        ];
        $stat = StatUser::query()->where($keys)->lockForUpdate()->first();

        if ($stat) {
            $stat->forceFill([
                'u' => (int) $stat->u + $u,
                'd' => (int) $stat->d + $d,
                'updated_at' => $now,
            ])->save();
            return;
        }

        StatUser::query()->create($keys + [
            'record_type' => 'd',
            'u' => $u,
            'd' => $d,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function incrementServerStat(
        int $serverId,
        string $serverType,
        int $recordAt,
        int $u,
        int $d,
        int $now
    ): void {
        $keys = [
            'server_id' => $serverId,
            'server_type' => $serverType,
            'record_at' => $recordAt,
        ];
        $stat = StatServer::query()->where($keys)->lockForUpdate()->first();

        if ($stat) {
            $stat->forceFill([
                'u' => (int) $stat->u + $u,
                'd' => (int) $stat->d + $d,
                'updated_at' => $now,
            ])->save();
            return;
        }

        StatServer::query()->create($keys + [
            'record_type' => 'd',
            'u' => $u,
            'd' => $d,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
