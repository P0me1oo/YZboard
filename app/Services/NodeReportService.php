<?php

namespace App\Services;

use App\Jobs\ProcessNodeReportBatch;
use App\Models\NodeReportBatch;
use App\Models\Server;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class NodeReportService
{
    /** 长连接接收确认只在原有持久化入口成功返回后发出。 */
    public function acceptRealtime(Server $node, array $payload): array
    {
        $data = \Illuminate\Support\Facades\Validator::make($payload, [
            'report_id' => 'required|string|max:128',
            'traffic' => 'sometimes|array',
            'relay_traffic' => 'sometimes|array',
            'relay_user_traffic' => 'sometimes|array',
            'limit_events' => 'sometimes|array',
        ])->validate();
        $batch = $this->accept(
            $node, $data['report_id'], $data['traffic'] ?? [],
            $data['relay_traffic'] ?? [], $data['relay_user_traffic'] ?? []
        );
        ServerService::touchNode($node);
        ServerService::touchPush($node);
        if (!empty($data['limit_events']) && (!$batch || $batch->wasRecentlyCreated)) {
            ServerService::processLimitEvents($node, $data['limit_events']);
        }
        return ['report_id' => $data['report_id'], 'accepted' => true];
    }

    public function accept(
        Server $node,
        ?string $reportId,
        array $traffic,
        array $relayTraffic,
        array $relayUserTraffic = []
    ): ?NodeReportBatch {
        $traffic = $this->normalizeTraffic($traffic);
        $rawTraffic = $traffic;
        $serverSnapshot = ['id' => (int) $node->id, 'rate' => $node->getEffectiveRate(), 'type' => $node->type];
        $protocol = $node->type;

        if ($traffic !== []) {
            [$serverSnapshot, $protocol, $traffic] = (new UserService())->prepareTraffic(
                $node,
                $node->type,
                $traffic
            );
            $serverSnapshot = [
                'id' => (int) ($serverSnapshot['id'] ?? $node->id),
                'rate' => (float) ($serverSnapshot['rate'] ?? 1),
                'type' => (string) ($serverSnapshot['type'] ?? $protocol),
            ];
            $traffic = $this->normalizeTraffic((array) $traffic);
        }

        $relayTraffic = ServerService::normalizeRelayTraffic($node, $relayTraffic);
        $relayUserTraffic = ServerService::normalizeRelayUserTraffic($node, $relayUserTraffic);
        $relayRates = app(RelayTrafficBilling::class)->snapshotRates($node, $relayUserTraffic);
        if ($relayRates !== []) {
            $serverSnapshot['relay_rates'] = $relayRates;
        }
        if ($traffic === [] && $rawTraffic === [] && $relayTraffic === [] && $relayUserTraffic === []) {
            return null;
        }

        $serverSnapshot['proxy_traffic'] = app(ProxyTrafficRecorder::class)->snapshot($node, $relayTraffic, $relayUserTraffic);

        $reportId = trim((string) $reportId);
        if ($reportId === '') {
            // 兼容未携带 report_id 的旧 Node；这类请求无法跨 HTTP 重试去重。
            $reportId = 'legacy-' . Str::uuid()->toString();
        }

        return DB::transaction(function () use ($node, $reportId, $protocol, $serverSnapshot, $traffic, $rawTraffic, $relayTraffic, $relayUserTraffic) {
            // 先尝试插入，避免 MariaDB 可重复读下先查后建形成旧快照，
            // 导致并发重复报告插入冲突后仍无法读到已经提交的那一批。
            $batch = NodeReportBatch::query()->lockForUpdate()->createOrFirst(
                [
                    'server_id' => (int) $node->id,
                    'report_key' => hash('sha256', $reportId),
                ],
                [
                    'report_id' => mb_substr($reportId, 0, 128),
                    'server_type' => (string) $protocol,
                    'server_snapshot' => $serverSnapshot,
                    'traffic' => $traffic,
                    'relay_traffic' => $relayTraffic,
                    'relay_user_traffic' => $relayUserTraffic,
                    'record_at' => strtotime(date('Y-m-d')),
                    'status' => NodeReportBatch::STATUS_PENDING,
                    'attempts' => 0,
                ]
            );

            if ($batch->wasRecentlyCreated) {
                $usage = app(ProxyTrafficRecorder::class)->machineUsage($serverSnapshot['proxy_traffic'], $rawTraffic, $relayTraffic);
                foreach ($usage as $id => [$u, $d]) {
                    if ($u > 0 || $d > 0) {
                        MachineTrafficService::recordProxyUsage((int) $id, $u, $d);
                    }
                }
            }
            if ($batch->wasRecentlyCreated || $batch->status === NodeReportBatch::STATUS_FAILED) {
                DB::afterCommit(fn () => ProcessNodeReportBatch::dispatch((int) $batch->id));
            }

            return $batch;
        }, 5);
    }

    /**
     * @return array<int, array{0: int, 1: int}>
     */
    private function normalizeTraffic(array $traffic): array
    {
        $normalized = [];
        foreach ($traffic as $userId => $value) {
            if (!is_numeric($userId) || !is_array($value) || count($value) !== 2) {
                continue;
            }
            if (!isset($value[0], $value[1]) || !is_numeric($value[0]) || !is_numeric($value[1])) {
                continue;
            }

            $u = max(0, (int) $value[0]);
            $d = max(0, (int) $value[1]);
            if ($u === 0 && $d === 0) {
                continue;
            }
            $normalized[(int) $userId] = [$u, $d];
        }

        return $normalized;
    }
}
