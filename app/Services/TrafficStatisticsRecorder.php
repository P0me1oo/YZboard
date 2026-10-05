<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class TrafficStatisticsRecorder
{
    /** 与调用方的流量结算共用事务；数据库原子累加，避免并发覆盖。 */
    public function add(int $userId, int $serverId, string $kind, int $recordAt, int $u, int $d, int $billedU = 0, int $billedD = 0, ?int $receivedAt = null): void
    {
        if ($u === 0 && $d === 0) {
            return;
        }
        $table = 'v2_stat_user_server';
        $updates = [];
        foreach (['u', 'd', 'billed_u', 'billed_d'] as $column) {
            $updates[$column] = DB::raw(in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)
                ? "{$column} + VALUES({$column})"
                : "{$table}.{$column} + excluded.{$column}");
        }
        DB::table($table)->upsert([[
            'user_id' => $userId, 'server_id' => $serverId, 'kind' => $kind,
            'record_at' => $recordAt, 'u' => $u, 'd' => $d,
            'billed_u' => $billedU, 'billed_d' => $billedD,
        ]], ['user_id', 'server_id', 'kind', 'record_at'], $updates);
        // 小时总趋势只记录入口，和日明细共用调用方事务及批次去重。
        if ($kind !== 'entry' || $receivedAt === null) {
            return;
        }
        $started = (int) DB::table('v2_settings')->where('name', 'traffic_hourly_started_at')->value('value');
        $received = CarbonImmutable::createFromTimestamp($receivedAt, config('app.timezone'));
        if (!$started || $receivedAt < $started || $received->startOfDay()->timestamp !== $recordAt) {
            return;
        }
        $table = 'v2_stat_traffic_hour';
        $updates = [];
        foreach (['u', 'd'] as $column) {
            $updates[$column] = DB::raw(in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)
                ? "{$column} + VALUES({$column})"
                : "{$table}.{$column} + excluded.{$column}");
        }
        DB::table($table)->upsert([['record_at' => $received->startOfHour()->timestamp, 'u' => $u, 'd' => $d]], ['record_at'], $updates);
    }
}
