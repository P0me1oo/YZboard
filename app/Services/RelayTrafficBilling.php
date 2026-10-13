<?php

namespace App\Services;

use App\Models\NodeReportBatch;
use App\Models\Server;

/** 按入口已上报的用户总量分配线路扣费，落地节点承载量不再次计费。 */
class RelayTrafficBilling
{
    public function snapshotRates(Server $entry, array $relayTraffic): array
    {
        $ids = [];
        foreach ($relayTraffic as $nodes) {
            foreach ($nodes as $id => $_) {
                $ids[(int) $id] = true;
            }
        }
        if ($ids === []) {
            return [];
        }
        $rates = [];
        foreach (Server::query()->whereIn('id', array_keys($ids))
            ->where('relay_entry_id', $entry->id)->where('relay_rate_override', true)->get() as $child) {
            if ($child->isRelayChild()) {
                $rates[$child->id] = $child->getCurrentRate();
            }
        }
        return $rates;
    }

    /**
     * 只读取持久化快照；队列重试、时段变化和节点改绑均不能改变这批扣费。
     * 同一方向的落地采样超过入口总量时，按比例分配入口总量，不能新增套餐用量。
     * 相同倍率累计取整，避免拆成多条线路后改变原有扣费精度。
     *
     * @return array<int, array<int, array{server_id: int, kind: string, rate: float, u: int, d: int, billed_u: int, billed_d: int}>>
     */
    public function lines(NodeReportBatch $batch): array
    {
        $snapshot = (array) $batch->server_snapshot;
        $entryRate = max(0.0, (float) ($snapshot['rate'] ?? 1));
        $overrides = (array) ($snapshot['relay_rates'] ?? []);
        $traffic = (array) $batch->traffic;
        $relay = (array) $batch->relay_user_traffic;
        $result = [];
        foreach (array_unique([...array_keys($traffic), ...array_keys($relay)]) as $uid) {
            $nodes = (array) ($relay[$uid] ?? []);
            ksort($nodes, SORT_NUMERIC);
            $rows = [];
            foreach ($nodes as $sid => $values) {
                $rows[] = ['server_id' => (int) $sid, 'kind' => 'transit',
                    'rate' => max(0.0, (float) ($overrides[$sid] ?? $entryRate)),
                    'u' => max(0, (int) $values[0]), 'd' => max(0, (int) $values[1]),
                    'billed_u' => 0, 'billed_d' => 0];
            }
            $direct = ['server_id' => (int) ($snapshot['id'] ?? $batch->server_id), 'kind' => 'direct',
                'rate' => $entryRate, 'u' => 0, 'd' => 0, 'billed_u' => 0, 'billed_d' => 0];
            foreach (['u' => 0, 'd' => 1] as $direction => $index) {
                $total = max(0, (int) ($traffic[$uid][$index] ?? 0));
                $relayTotal = array_sum(array_column($rows, $direction));
                $direct[$direction] = max(0, $total - $relayTotal);
                if ($relayTotal > $total) {
                    $accumulated = 0; $assigned = 0;
                    foreach ($rows as &$row) {
                        $accumulated += $row[$direction];
                        $next = (int) bcdiv(bcmul((string) $total, (string) $accumulated, 0), (string) $relayTotal, 0);
                        $row[$direction] = $next - $assigned;
                        $assigned = $next;
                    }
                    unset($row);
                }
            }
            $rows[] = $direct;
            $cumulative = [];
            foreach ($rows as &$row) {
                $key = (string) $row['rate'];
                foreach (['u', 'd'] as $direction) {
                    $previous = $cumulative[$key][$direction] ?? 0;
                    $next = $previous + $row[$direction];
                    $row['billed_' . $direction] = (int) ($next * $row['rate']) - (int) ($previous * $row['rate']);
                    $cumulative[$key][$direction] = $next;
                }
            }
            unset($row);
            $result[(int) $uid] = array_values(array_filter($rows, fn ($row) => $row['u'] + $row['d'] > 0));
        }
        return $result;
    }
}
