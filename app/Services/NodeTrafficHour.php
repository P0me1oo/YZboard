<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class NodeTrafficHour
{
    /** 与节点日统计共用事务；入口和落地各记一次，与节点管理保持同一口径。 */
    public function add(int $day, int $u, int $d, ?int $receivedAt): void
    {
        $started = (int) DB::table('v2_settings')->where('name', 'node_hourly_started_at')->value('value');
        if (!$started || $receivedAt === null || $receivedAt < $started || ($u === 0 && $d === 0)) { return; }
        $received = CarbonImmutable::createFromTimestamp($receivedAt, config('app.timezone'));
        if ($received->startOfDay()->timestamp !== $day) { return; }
        $updates = [];
        foreach (['u', 'd'] as $column) {
            $updates[$column] = DB::raw(in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)
                ? "{$column} + VALUES({$column})" : "v2_stat_node_hour.{$column} + excluded.{$column}");
        }
        DB::table('v2_stat_node_hour')->upsert([
            ['record_at' => $received->startOfHour()->timestamp, 'u' => $u, 'd' => $d],
        ], ['record_at'], $updates);
    }
}
