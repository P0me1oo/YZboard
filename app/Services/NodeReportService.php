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
        $this->accept(
            $node, $data['report_id'], $data['traffic'] ?? [],
            $data['relay_traffic'] ?? [], $data['relay_user_traffic'] ?? []
        );
        ServerService::touchNode($node);
        ServerService::touchPush($node);
        if (!empty($data['limit_events'])) {
            // 限制事件自己登记接收状态；流量已接收不代表通知写入也成功。
            ServerService::processLimitEvents($node, $data['limit_events'], $data['report_id']);
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
        // 已接收批次按原快照重试；撤权或宽限到期不能令确认丢失后的重传永远失败。
        $reportId = trim((string) $reportId);
        if ($reportId !== '') {
            $existing = NodeReportBatch::where('server_id', $node->id)->where('report_key', hash('sha256', $reportId))->first();
            if ($existing) {
                if ($existing->status === NodeReportBatch::STATUS_FAILED) DB::afterCommit(fn () => ProcessNodeReportBatch::dispatch((int) $existing->id));
                return $existing;
            }
        }
        $traffic = TrafficPayload::normalize($traffic);
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
            $traffic = TrafficPayload::normalize((array) $traffic);
        }
        TrafficPayload::validateRate($traffic, $serverSnapshot['rate']);

        $relayTraffic = ServerService::normalizeRelayTraffic($node, $relayTraffic);
        $relayUserTraffic = ServerService::normalizeRelayUserTraffic($node, $relayUserTraffic);
        app(NodeTrafficAuthorization::class)->assertAllowed($node, array_unique([
            ...array_keys($rawTraffic), ...array_keys($traffic), ...array_keys($relayUserTraffic),
        ]));
        $relayRates = app(RelayTrafficBilling::class)->snapshotRates($node, $relayUserTraffic);
        if ($relayRates !== []) {
            TrafficPayload::validateRate($traffic, max($serverSnapshot['rate'], ...array_values($relayRates)));
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

}
