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
        $this->writeMany([[$type, $keys, $values, $day, $receivedAt]]);
    }

    /** @param list<array{string, array, array, int, ?int}> $items 与 record 参数顺序一致。 */
    public function recordMany(array $items): void
    {
        if (get_class($this) !== self::class) {
            foreach ($items as $item) $this->record(...$item);
            return;
        }
        $this->writeMany($items);
    }

    private function writeMany(array $items): void
    {
        if ($items === []) return;
        $started = (int) DB::table('v2_settings')->where('name', 'fine_traffic_started_at')->value('value');
        $tables = [];
        foreach ($items as [$type, $keys, $values, $day, $receivedAt]) {
            if ($type !== 'route') throw new \InvalidArgumentException('仅支持用户线路细分记录。');
            if (!$started || !$receivedAt || $receivedAt < $started || array_sum($values) === 0) { continue; }
            $received = CarbonImmutable::createFromTimestamp($receivedAt, config('app.timezone'));
            $today = CarbonImmutable::today(config('app.timezone'));
            if ($received->startOfDay()->timestamp !== $day || $received < TrafficStatisticsRetention::hourlyStart() || $received >= $today->addDay()) { continue; }
            foreach (['hour', 'minute'] as $unit) {
                // 延迟到次日处理的上报只补小时记录，不重新创建已过期的分钟行。
                if ($unit === 'minute' && $received < $today) { continue; }
                $table = 'v2_stat_route_' . $unit;
                $recordAt = $unit === 'minute' ? $received->startOfMinute()->timestamp : $received->startOfHour()->timestamp;
                $tables[$table]['keys'] = [...array_keys($keys), 'record_at'];
                $tables[$table]['columns'] = array_keys($values);
                $tables[$table]['rows'][] = $keys + ['record_at' => $recordAt] + $values;
            }
        }
        foreach ($tables as $table => $data) {
            TrafficStatWriter::add($table, $data['rows'], $data['keys'], $data['columns']);
        }
    }

    /** 整日直接读取日汇总，仅首尾不完整日期读取仍在保留期内的细分记录。 */
    public function source(array $range): Builder
    {
        $dailyStart = $range['start']->startOfDay();
        if (!$dailyStart->equalTo($range['start'])) $dailyStart = $dailyStart->addDay();
        $dailyEnd = $range['end']->startOfDay();
        $daily = DB::table(UserRouteTraffic::TABLE)->where('record_at', '>=', $dailyStart->timestamp)
            ->where('record_at', '<', $dailyEnd->timestamp)
            ->select('user_id', 'entry_id', 'server_id', 'kind', 'rate', 'record_at', 'u', 'd', 'billed_u', 'billed_d');
        $windows = [];
        if (!$range['start']->equalTo($range['start']->startOfDay())) {
            $windows[] = [$range['start'], $range['end']->min($dailyStart)];
        }
        if (!$range['end']->equalTo($dailyEnd) && $dailyEnd >= $dailyStart) {
            $windows[] = [$dailyEnd, $range['end']];
        }
        // 同日从零点开始、结束在日内时，没有完整日期。
        if ($windows === [] && $dailyStart->equalTo($dailyEnd) && $range['end'] > $dailyEnd) {
            $windows[] = [$range['start'], $range['end']];
        }
        $union = $daily;
        $started = (int) DB::table('v2_settings')->where('name', 'fine_traffic_started_at')->value('value');
        foreach ($windows as [$start, $end]) {
            $minute = $start >= TrafficStatisticsRetention::today();
            $unit = $minute ? 'minute' : 'hour';
            $boundary = CarbonImmutable::createFromTimestamp($started, config('app.timezone'));
            $boundary = $minute ? $boundary->startOfMinute() : $boundary->startOfHour();
            if ($boundary->timestamp < $started) $boundary = $boundary->addSeconds($minute ? 60 : 3600);
            if (!$started || $start < $boundary || $start < TrafficStatisticsRetention::hourlyStart()) {
                throw ValidationException::withMessages(['start_time' => '该时段没有完整的细分记录，请选择完整日期或记录启用后的时段。']);
            }
            $fine = DB::table('v2_stat_route_' . $unit)->where('record_at', '>=', $start->timestamp)
                ->where('record_at', '<', $end->timestamp)
                ->select('user_id', 'entry_id', 'server_id', 'kind', 'rate')
                ->selectRaw('? AS record_at, SUM(u) AS u, SUM(d) AS d, SUM(billed_u) AS billed_u, SUM(billed_d) AS billed_d', [$start->startOfDay()->timestamp])
                ->groupBy('user_id', 'entry_id', 'server_id', 'kind', 'rate');
            $union->unionAll($fine);
        }
        return DB::query()->fromSub($union, 'fine_source');
    }
}
