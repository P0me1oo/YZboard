<?php

namespace App\Jobs;

use App\Models\NodeReportBatch;
use App\Models\StatServer;
use App\Models\StatUser;
use App\Services\Plugin\HookManager;
use App\Services\TrafficStatisticsRecorder;
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
            app(\App\Services\UserRouteTraffic::class)->recordBatch($batch);
            app(\App\Services\ProxyTrafficRecorder::class)->recordBatch($batch);

            $relayRows = [];
            foreach ((array) $batch->relay_user_traffic as $userId => $nodes) {
                foreach ($nodes as $serverId => $traffic) {
                    $relayRows[] = [
                        (int) $userId, (int) $serverId, 'relay', (int) $batch->record_at,
                        (int) $traffic[0], (int) $traffic[1], 0, 0, null,
                    ];
                }
            }
            app(TrafficStatisticsRecorder::class)->addMany($relayRows);

            if ((array) $batch->relay_user_traffic !== []) {
                HookManager::call('traffic.relay_user.processed', [
                    'batch_id' => (int) $batch->id,
                    'entry_server_id' => (int) $batch->server_id,
                    'record_at' => (int) $batch->record_at,
                    'traffic' => (array) $batch->relay_user_traffic,
                ]);
            }

            // Redis 集合写入可重复执行。放在数据库提交前，失败时整批回滚并由队列重试。
            if ($userIds !== []) {
                Redis::sadd('traffic:pending_check', ...$userIds);
            }

            $processedAt = now();
            $batch->forceFill([
                'status' => NodeReportBatch::STATUS_PROCESSED,
                'processed_at' => $processedAt,
                'payload_cleanup_at' => $processedAt->copy()->addDays(NodeReportBatch::PAYLOAD_RETENTION_DAYS)->timestamp,
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
        $billing = !empty($server['relay_rates'])
            ? app(\App\Services\RelayTrafficBilling::class)->lines($batch) : null;
        $recordAt = (int) $batch->record_at;
        $now = time();
        $totalU = 0;
        $totalD = 0;
        $userIds = [];
        $statistics = [];
        $batchStatistics = $this->canBatchStatistics();

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
            if ($billing !== null) {
                $lines = $billing[$userId] ?? [];
                $ratedU = array_sum(array_column($lines, 'billed_u'));
                $ratedD = array_sum(array_column($lines, 'billed_d'));
                $statLines = $lines;
                if ($batchStatistics) {
                    $statLines = [];
                    // 线路已经按原顺序算好整数扣费，只合并同一用户、同一倍率的日账本增量。
                    foreach ($lines as $line) {
                        $key = (string) $line['rate'];
                        $statLines[$key] ??= ['rate' => $line['rate'], 'billed_u' => 0, 'billed_d' => 0];
                        $statLines[$key]['billed_u'] += $line['billed_u'];
                        $statLines[$key]['billed_d'] += $line['billed_d'];
                    }
                }
                foreach ($statLines as $line) {
                    $this->incrementUserStat($userId, $line['rate'], $recordAt, $line['billed_u'], $line['billed_d'], $now);
                }
            } else {
                $this->incrementUserStat($userId, $rate, $recordAt, $ratedU, $ratedD, $now);
            }

            DB::table('v2_user')
                ->where('id', $userId)
                ->incrementEach(['u' => $ratedU, 'd' => $ratedD], ['t' => $now]);

            $item = [$userId, $serverId, 'entry', $recordAt, $u, $d, $ratedU, $ratedD, $batch->created_at?->timestamp];
            if ($batchStatistics) $statistics[] = $item;
            else app(TrafficStatisticsRecorder::class)->add(...$item);
            $totalU += $u;
            $totalD += $d;
            $userIds[] = $userId;
        }
        if ($batchStatistics) app(TrafficStatisticsRecorder::class)->addMany($statistics);

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

    /** 模型事件或扩展服务可读取中间结果；存在这些扩展时保留原逐项写入顺序。 */
    private function canBatchStatistics(): bool
    {
        foreach ([TrafficStatisticsRecorder::class, \App\Services\FineTrafficStatistics::class] as $class) {
            if (get_class(app($class)) !== $class) return false;
        }
        foreach ([new StatUser(), new StatServer()] as $model) {
            foreach ($model->getObservableEvents() as $event) {
                if ($model->getEventDispatcher()?->hasListeners('eloquent.' . $event . ': ' . get_class($model))) return false;
            }
        }
        return true;
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
