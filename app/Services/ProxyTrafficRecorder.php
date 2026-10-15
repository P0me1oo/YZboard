<?php

namespace App\Services;

use App\Models\NodeReportBatch;
use App\Models\Server;
use App\Models\ServerMachine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ProxyTrafficRecorder
{
    public const TABLE = 'v2_stat_proxy_traffic';

    /** 收到上报时固定归属；队列重试、删除和改绑不能搬走这批流量。 */
    public function snapshot(Server $entry, array $relayTraffic, array $relayUserTraffic): array
    {
        $ids = array_column($relayTraffic, 'server_id');
        foreach ($relayUserTraffic as $nodes) {
            $ids = [...$ids, ...array_keys($nodes)];
        }
        $nodes = Server::whereIn('id', array_unique([$entry->id, ...$ids]))
            ->get(['id', 'name', 'machine_id'])->keyBy('id');
        $machines = ServerMachine::whereIn('id', $nodes->pluck('machine_id')->filter()->unique())
            ->pluck('name', 'id');
        return [
            'version' => 1,
            'entry_id' => (int) $entry->id,
            'nodes' => $nodes->map(fn ($node) => [
                'name' => (string) $node->name,
                'machine_id' => $machines->has($node->machine_id) ? (int) $node->machine_id : null,
            ])->all(),
            'machines' => $machines->all(),
        ];
    }

    /** 与用户结算、批次完成标记共用事务；仅消费代理计数，不读取网卡指标。 */
    public function recordBatch(NodeReportBatch $batch): void
    {
        $snapshot = $batch->server_snapshot['proxy_traffic'] ?? null;
        if (!is_array($snapshot) || ($snapshot['version'] ?? null) !== 1) {
            return; // 升级前已接收的队列保留旧口径，不猜测历史机器归属。
        }
        $entryId = (int) $snapshot['entry_id'];
        $entryMachine = $snapshot['nodes'][$entryId]['machine_id'] ?? null;
        $total = [0, 0];
        foreach ((array) $batch->traffic as $value) {
            $total[0] += (int) $value[0];
            $total[1] += (int) $value[1];
        }
        // 始终使用独立线路总量，不能在两种异步采样之间逐批切换数据源。
        $relays = [];
        foreach ((array) $batch->relay_traffic as $row) {
            $relays[$row['server_id']] ??= [0, 0];
            $relays[$row['server_id']][0] += (int) $row['u'];
            $relays[$row['server_id']][1] += (int) $row['d'];
        }
        $direct = $total;
        $machineTraffic = $entryMachine ? [$entryMachine => $total] : [];
        $nodeTraffic = [];
        foreach ($relays as $id => $value) {
            $direct[0] -= $value[0];
            $direct[1] -= $value[1];
            $nodeTraffic[(int) $id] = $value;
            $machine = $snapshot['nodes'][$id]['machine_id'] ?? null;
            // 同机落地已经包含在入口总量中，跨机器才为落地服务器另记一次。
            if ($machine && $machine !== $entryMachine) {
                $machineTraffic[$machine] ??= [0, 0];
                $machineTraffic[$machine][0] += $value[0];
                $machineTraffic[$machine][1] += $value[1];
            }
        }
        $nodeTraffic[$entryId] = $direct;
        $rows = [];
        foreach (['node' => $nodeTraffic, 'machine' => $machineTraffic] as $scope => $items) {
            ksort($items, SORT_NUMERIC);
            foreach ($items as $id => [$u, $d]) {
                if ($u === 0 && $d === 0) { continue; }
                $name = $scope === 'node' ? ($snapshot['nodes'][$id]['name'] ?? '') : ($snapshot['machines'][$id] ?? '');
                array_push($rows, ...$this->rows($scope, (int) $id, (string) $name, $u, $d, (int) $batch->record_at, $batch->created_at->timestamp));
                $table = $scope === 'node' ? 'v2_server' : 'v2_server_machine';
                $prefix = $scope === 'node' ? 'usage_' : 'proxy_';
                DB::table($table)->where('id', $id)->incrementEach([$prefix . 'u' => $u, $prefix . 'd' => $d]);
            }
        }
        TrafficStatWriter::add(self::TABLE, $rows, ['scope', 'unit', 'record_at', 'entity_id'], ['u', 'd'], ['entity_name']);
    }

    private function rows(string $scope, int $id, string $name, int $u, int $d, int $day, int $receivedAt): array
    {
        $received = CarbonImmutable::createFromTimestamp($receivedAt, config('app.timezone'));
        $today = CarbonImmutable::today(config('app.timezone'));
        if ($received < $today->subDays(29)) { return []; }
        $buckets = ['day' => $day, 'hour' => $received->startOfHour()->timestamp];
        if ($received >= $today) { $buckets['minute'] = $received->startOfMinute()->timestamp; }
        $rows = [];
        foreach ($buckets as $unit => $recordAt) {
            $rows[] = [
                'scope' => $scope, 'unit' => $unit, 'record_at' => $recordAt, 'entity_id' => $id,
                'entity_name' => $name, 'u' => $u, 'd' => $d,
            ];
        }
        return $rows;
    }
}
