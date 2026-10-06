<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FineTrafficStatistics
{
    /** 与日统计一起提交；小时直接累计，清理分钟数据不参与结算或转存。 */
    public function record(string $type, array $keys, array $values, int $day, ?int $receivedAt): void
    {
        $started = (int) DB::table('v2_settings')->where('name', 'fine_traffic_started_at')->value('value');
        if (!$started || !$receivedAt || $receivedAt < $started || array_sum($values) === 0) { return; }
        $received = CarbonImmutable::createFromTimestamp($receivedAt, config('app.timezone'));
        $today = CarbonImmutable::today(config('app.timezone'));
        if ($received->startOfDay()->timestamp !== $day || $received < $today->subDays(29) || $received >= $today->addDay()) { return; }
        foreach (['hour', 'minute'] as $unit) {
            // 延迟到次日处理的上报只补小时记录，不重新创建已过期的分钟行。
            if ($unit === 'minute' && $received < $today) { continue; }
            $table = $type === 'node' ? 'v2_stat_node_' . $unit . '_detail' : 'v2_stat_route_' . $unit;
            $recordAt = $unit === 'minute' ? $received->startOfMinute()->timestamp : $received->startOfHour()->timestamp;
            $updates = [];
            foreach (array_keys($values) as $column) {
                $updates[$column] = DB::raw(in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)
                    ? "{$column} + VALUES({$column})" : "{$table}.{$column} + excluded.{$column}");
            }
            DB::table($table)->upsert([$keys + ['record_at' => $recordAt] + $values], [...array_keys($keys), 'record_at'], $updates);
        }
    }

    /** 全日沿用原记录；只有日内边界才查询细分表，旧历史不伪造小时或分钟。 */
    public function source(array $range, string $type, bool $trend = false): Builder
    {
        $union = null;
        $today = CarbonImmutable::today(config('app.timezone'));
        $minute = $range['start'] >= $today && $range['end'] <= $today->addDay();
        $unit = $minute ? 'minute' : 'hour';
        $started = (int) DB::table('v2_settings')->where('name', 'fine_traffic_started_at')->value('value');
        $enabled = CarbonImmutable::createFromTimestamp($started, config('app.timezone'));
        $boundary = $minute ? $enabled->startOfMinute() : $enabled->startOfHour();
        if ($boundary < $enabled) { $boundary = $boundary->addSeconds($minute ? 60 : 3600); }
        for ($day = $range['start']->startOfDay(); $day < $range['end']; $day = $day->addDay()) {
            $start = $range['start']->max($day); $end = $range['end']->min($day->addDay());
            $whole = $start->equalTo($day) && $end->equalTo($day->addDay());
            $daily = $type === 'node' ? DB::table('v2_stat_server')->where('record_type', 'd') : DB::table(UserRouteTraffic::TABLE);
            $daily->where('record_at', $day->timestamp);
            $table = $type === 'node' ? 'v2_stat_node_' . $unit . '_detail' : 'v2_stat_route_' . $unit;
            $fine = DB::table($table)->where('record_at', '>=', $start->timestamp)->where('record_at', '<', $end->timestamp);
            $query = $whole ? $daily : $fine;
            if (!$whole && (!$started || $start < $boundary)) {
                throw ValidationException::withMessages(['start_time' => '该时段没有完整的细分记录，请选择完整日期或记录启用后的时段。']);
            }
            if ($whole && $trend && $range['end']->timestamp - $range['start']->timestamp <= 172800) {
                // 升级日和旧队列可能只有日数据；核对两个方向后才绘制细分趋势。
                $totals = fn ($q) => (array) (clone $q)->selectRaw('COALESCE(SUM(u), 0) AS u, COALESCE(SUM(d), 0) AS d')->first();
                if ((clone $fine)->exists() && $totals($fine) == $totals($daily)) { $query = $fine; }
            }
            if ($type === 'node') {
                $query->select('record_at', 'server_id', 'u', 'd')->selectRaw('? AS bucket_unit', [$query === $daily ? 'day' : $unit]);
            } else {
                // 先汇总所选范围在同一天内的采样值，再计算直出差值和整数分配，避免逐分钟截断放大用量。
                $query->select('user_id', 'entry_id', 'server_id', 'kind', 'rate')
                    ->selectRaw('? AS record_at, SUM(u) AS u, SUM(d) AS d, SUM(billed_u) AS billed_u, SUM(billed_d) AS billed_d', [$day->timestamp])
                    ->groupBy('user_id', 'entry_id', 'server_id', 'kind', 'rate');
            }
            $union === null ? $union = $query : $union->unionAll($query);
        }
        return DB::query()->fromSub($union, 'fine_source');
    }
}
