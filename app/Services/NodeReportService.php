<?php

namespace App\Services;

use App\Jobs\ProcessNodeReportBatch;
use App\Models\NodeReportBatch;
use App\Models\Server;
use Illuminate\Support\Str;

class NodeReportService
{
    public function accept(
        Server $node,
        ?string $reportId,
        array $traffic,
        array $relayTraffic,
        array $relayUserTraffic = []
    ): ?NodeReportBatch {
        $traffic = $this->normalizeTraffic($traffic);
        $serverSnapshot = null;
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
        if ($traffic === [] && $relayTraffic === [] && $relayUserTraffic === []) {
            return null;
        }

        $reportId = trim((string) $reportId);
        if ($reportId === '') {
            // 兼容未携带 report_id 的旧 Node；这类请求无法跨 HTTP 重试去重。
            $reportId = 'legacy-' . Str::uuid()->toString();
        }

        $batch = NodeReportBatch::query()->firstOrCreate(
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

        if ($batch->wasRecentlyCreated || $batch->status === NodeReportBatch::STATUS_FAILED) {
            ProcessNodeReportBatch::dispatch((int) $batch->id);
        }

        return $batch;
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
