<?php

namespace App\Services;

class TrafficStatisticsRecorder
{
    /** 与调用方的流量结算共用事务；数据库原子累加，避免并发覆盖。 */
    public function add(int $userId, int $serverId, string $kind, int $recordAt, int $u, int $d, int $billedU = 0, int $billedD = 0, ?int $receivedAt = null): void
    {
        $this->writeMany([[$userId, $serverId, $kind, $recordAt, $u, $d, $billedU, $billedD, $receivedAt]]);
    }

    /** @param list<array{int, int, string, int, int, int, int, int, ?int}> $items 与 add 参数顺序一致。 */
    public function addMany(array $items): void
    {
        if (get_class($this) !== self::class) {
            foreach ($items as $item) $this->add(...$item);
            return;
        }
        $this->writeMany($items);
    }

    private function writeMany(array $items): void
    {
        $rows = [];
        foreach ($items as [$userId, $serverId, $kind, $recordAt, $u, $d, $billedU, $billedD, $receivedAt]) {
            if ($u === 0 && $d === 0) continue;
            $rows[] = ['user_id' => $userId, 'server_id' => $serverId, 'kind' => $kind,
                'record_at' => $recordAt, 'u' => $u, 'd' => $d, 'billed_u' => $billedU, 'billed_d' => $billedD];
        }
        TrafficStatWriter::add('v2_stat_user_server', $rows,
            ['user_id', 'server_id', 'kind', 'record_at'], ['u', 'd', 'billed_u', 'billed_d']);
    }
}
