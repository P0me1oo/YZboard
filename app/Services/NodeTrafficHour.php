<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class NodeTrafficHour
{
    /** 与节点日统计共用事务；入口和落地各记一次，与节点管理保持同一口径。 */
    public function add(int $day, int $u, int $d, ?int $receivedAt, ?int $serverId = null): void
    {
        $this->writeMany([[$day, $u, $d, $receivedAt, $serverId]]);
    }

    /** @param list<array{int, int, int, ?int, ?int}> $items 与 add 参数顺序一致。 */
    public function addMany(array $items): void
    {
        if (get_class($this) !== self::class || get_class(app(FineTrafficStatistics::class)) !== FineTrafficStatistics::class) {
            foreach ($items as $item) $this->add(...$item);
            return;
        }
        $this->writeMany($items);
    }

    private function writeMany(array $items): void
    {
        if ($items === []) return;
        $fine = []; $hours = [];
        foreach ($items as [$day, $u, $d, $receivedAt, $serverId]) {
            if ($serverId !== null) $fine[] = ['node', ['server_id' => $serverId], ['u' => $u, 'd' => $d], $day, $receivedAt];
        }
        app(FineTrafficStatistics::class)->recordMany($fine);
        $started = (int) DB::table('v2_settings')->where('name', 'node_hourly_started_at')->value('value');
        foreach ($items as [$day, $u, $d, $receivedAt]) {
            if (!$started || $receivedAt === null || $receivedAt < $started || ($u === 0 && $d === 0)) continue;
            $received = CarbonImmutable::createFromTimestamp($receivedAt, config('app.timezone'));
            if ($received->startOfDay()->timestamp !== $day) continue;
            $hours[] = ['record_at' => $received->startOfHour()->timestamp, 'u' => $u, 'd' => $d];
        }
        TrafficStatWriter::add('v2_stat_node_hour', $hours, ['record_at'], ['u', 'd']);
    }
}
