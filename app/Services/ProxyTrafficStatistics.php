<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProxyTrafficStatistics
{
    private function started(): int
    {
        return (int) DB::table('v2_settings')->where('name', 'proxy_traffic_started_at')->value('value');
    }

    private function meta(array $range): array
    {
        $started = $this->started();
        return array_replace($range['meta'], [
            'scope' => $range['scope'],
            'started_at' => $started ? CarbonImmutable::createFromTimestamp($started, config('app.timezone'))->toIso8601String() : null,
            'partial' => $range['start']->timestamp < $started && $range['end']->timestamp > $started,
        ]);
    }

    private function source(array $range, bool $trend = false): Builder
    {
        $today = CarbonImmutable::today(config('app.timezone'));
        $fine = ($range['precision'] ?? '') === 'auto';
        $unit = $range['start'] < TrafficStatisticsRetention::hourlyStart() ? 'day' : ($range['start'] >= $today ? 'minute' : 'hour');
        $union = null; $windows = [];
        $started = CarbonImmutable::createFromTimestamp($this->started(), config('app.timezone'));
        $boundary = match ($unit) { 'minute' => $started->startOfMinute(), 'hour' => $started->startOfHour(), default => $started->startOfDay() };
        if ($boundary < $started) { $boundary = $unit === 'day' ? $boundary->addDay() : $boundary->addSeconds($unit === 'minute' ? 60 : 3600); }
        $shortFineRange = $range['end']->timestamp - $range['start']->timestamp <= 172800;
        $fineWindows = [];
        for ($day = $range['start']->startOfDay(); $day < $range['end']; $day = $day->addDay()) {
            $start = $range['start']->max($day); $end = $range['end']->min($day->addDay());
            $whole = $start->equalTo($day) && $end->equalTo($day->addDay());
            if (!$whole && (!$fine || $start < $boundary)) {
                throw ValidationException::withMessages(['start_time' => '该时段没有完整的新口径记录，请选择完整日期或启用后的时段。']);
            }
            // 排行和长范围趋势使用已有日汇总，避免按天重复扫描细分表。
            // 只有短范围趋势保留小时/分钟粒度；日内边界仍必须使用细分记录。
            $selected = !$whole || ($fine && $trend && $shortFineRange) ? $unit : 'day';
            if ($whole && $selected !== 'day') $fineWindows[] = [$start, $end];
            $last = count($windows[$selected] ?? []) - 1;
            if ($last >= 0 && $windows[$selected][$last][1] === $start->timestamp) {
                $windows[$selected][$last][1] = $end->timestamp;
            } else {
                $windows[$selected][] = [$start->timestamp, $end->timestamp];
            }
        }
        if ($fineWindows !== [] && !$this->fineMatchesDaily($range['scope'], $unit, $fineWindows)) {
            // 迟到数据使细分与日汇总不一致时，只回退完整日期；日内边界仍使用可用细分。
            $fineKeys = array_fill_keys(array_map(fn (array $window): string => $window[0]->timestamp . ':' . $window[1]->timestamp, $fineWindows), true);
            $windows = [];
            for ($day = $range['start']->startOfDay(); $day < $range['end']; $day = $day->addDay()) {
                $start = $range['start']->max($day); $end = $range['end']->min($day->addDay());
                $whole = $start->equalTo($day) && $end->equalTo($day->addDay());
                $key = $start->timestamp . ':' . $end->timestamp;
                $selected = ($whole && isset($fineKeys[$key])) ? 'day'
                    : (!$whole || ($fine && $trend && $shortFineRange) ? $unit : 'day');
                $last = count($windows[$selected] ?? []) - 1;
                if ($last >= 0 && $windows[$selected][$last][1] === $start->timestamp) $windows[$selected][$last][1] = $end->timestamp;
                else $windows[$selected][] = [$start->timestamp, $end->timestamp];
            }
        }
        // 整日窗口合并为同一查询，全年范围不展开为数百个 UNION。
        foreach ($windows as $selected => $ranges) {
            $query = DB::table(ProxyTrafficRecorder::TABLE)->where('scope', $range['scope'])->where('unit', $selected)
                ->where(function (Builder $query) use ($ranges): void {
                    foreach ($ranges as [$start, $end]) {
                        $query->orWhere(fn (Builder $part) => $part->where('record_at', '>=', $start)->where('record_at', '<', $end));
                    }
                })->select('entity_id', 'entity_name', 'record_at', 'unit', 'u', 'd');
            $union === null ? $union = $query : $union->unionAll($query);
        }
        return DB::query()->fromSub($union, 'proxy_source');
    }

    /** 短范围趋势只做两次扫描，并按日期和实体比较细分与日汇总。 */
    private function fineMatchesDaily(string $scope, string $unit, array $windows): bool
    {
        $aggregate = function (string $selected) use ($scope, $windows): array {
            $query = DB::table(ProxyTrafficRecorder::TABLE)->where('scope', $scope)->where('unit', $selected)
                ->where(function (Builder $query) use ($windows): void {
                    foreach ($windows as [$start, $end]) {
                        $query->orWhere(fn (Builder $part) => $part->where('record_at', '>=', $start->timestamp)->where('record_at', '<', $end->timestamp));
                    }
                });
            $result = [];
            foreach ($query->select('record_at', 'entity_id', 'u', 'd')->get() as $row) {
                $day = CarbonImmutable::createFromTimestamp((int) $row->record_at, config('app.timezone'))->startOfDay()->timestamp;
                $key = $day . ':' . (int) $row->entity_id;
                $result[$key] ??= [0, 0];
                $result[$key][0] += (int) $row->u;
                $result[$key][1] += (int) $row->d;
            }
            ksort($result);
            return $result;
        };
        return $aggregate($unit) === $aggregate('day');
    }

    /** 先按实体汇总有符号差值，再限制为非负数，采样迟到不会累计虚假直出。 */
    private function totals(Builder $source): Builder
    {
        return $source->selectRaw('entity_id, MAX(entity_name) AS entity_name,
            CASE WHEN SUM(u) > 0 THEN SUM(u) ELSE 0 END AS upload,
            CASE WHEN SUM(d) > 0 THEN SUM(d) ELSE 0 END AS download')->groupBy('entity_id');
    }

    public function ranking(array $range): array
    {
        $scope = $range['scope'];
        $table = $scope === 'node' ? 'v2_server' : 'v2_server_machine';
        $name = $scope === 'node' ? 'COALESCE(current_entity.name, old_name.name, totals.entity_name)' : 'COALESCE(current_entity.name, totals.entity_name)';
        $query = DB::query()->fromSub($this->totals($this->source($range)), 'totals')
            ->leftJoin($table . ' as current_entity', 'current_entity.id', '=', 'totals.entity_id');
        if ($scope === 'node') $query->leftJoin('v2_stat_server_name as old_name', 'old_name.server_id', '=', 'totals.entity_id');
        $query->selectRaw("totals.entity_id AS id, {$name} AS name,
                CASE WHEN current_entity.id IS NULL THEN 1 ELSE 0 END AS deleted,
                upload, download, upload + download AS total")
            ->whereRaw('upload + download > 0');
        $query->whereRaw("TRIM({$name}) <> ''");
        $search = trim($range['search'] ?? '');
        if ($search !== '') {
            $pattern = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)) . '%';
            $query->whereRaw("LOWER({$name}) LIKE ? ESCAPE '!'", [$pattern]);
        }
        $sort = $range['sort'] ?? 'total'; $direction = $range['direction'] ?? 'desc';
        if ($sort === 'node') {
            $query->orderBy('deleted')->orderBy($scope === 'node' ? 'current_entity.sort' : 'totals.entity_id', $direction)
                ->orderBy('totals.entity_id', $direction);
        } else {
            $query->orderBy($sort, $direction);
        }
        $page = $query->orderBy('totals.entity_id')->paginate((int) $range['page_size'], ['*'], 'page', (int) $range['page']);
        return [
            'list' => array_map(fn ($row) => [
                'id' => (int) $row->id, 'name' => $row->name . ($row->deleted ? '（已删除）' : ''),
                'upload' => (int) $row->upload, 'download' => (int) $row->download, 'total' => (int) $row->total,
            ], $page->items()),
            'total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage(),
            'page_size' => $page->perPage(), 'meta' => $this->meta($range),
        ];
    }

    public function overview(array $range): array
    {
        $rows = $this->source($range, true)->orderBy('record_at')->get();
        $unit = $rows->contains('unit', 'day') || $range['start'] < TrafficStatisticsRetention::hourlyStart() || $range['end']->timestamp - $range['start']->timestamp > 172800
            ? 'day' : ($range['start'] >= CarbonImmutable::today(config('app.timezone')) ? 'minute' : 'hour');
        if (($range['precision'] ?? '') !== 'auto') { $unit = $range['unit']; }
        if ($range['start'] < TrafficStatisticsRetention::hourlyStart()) { $unit = 'day'; }
        $series = [];
        // 按节点分别消化迟到的扣减；回收前面时间桶中的同方向直出，趋势与排行合计一致。
        foreach ($rows->groupBy('entity_id') as $entityRows) {
            foreach (['u' => 'upload', 'd' => 'download'] as $column => $direction) {
                $raw = 0; $visible = 0; $stack = [];
                foreach ($entityRows as $row) {
                    $time = CarbonImmutable::createFromTimestamp((int) $row->record_at, config('app.timezone'));
                    $time = match ($unit) {
                        'month' => $time->startOfMonth(), 'week' => $time->startOfWeek(), 'day' => $time->startOfDay(), default => $time,
                    };
                    $key = $time->timestamp;
                    $series[$key] ??= ['date' => in_array($unit, ['hour', 'minute'], true) ? $time->toIso8601String() : $time->toDateString(), 'upload' => 0, 'download' => 0, 'total' => 0];
                    $raw += (int) $row->$column;
                    $next = max(0, $raw);
                    $delta = $next - $visible;
                    $visible = $next;
                    if ($delta > 0) {
                        $series[$key][$direction] += $delta;
                        $stack[] = [$key, $delta];
                    } elseif ($delta < 0) {
                        $remaining = -$delta;
                        while ($remaining > 0 && $stack !== []) {
                            [$previousKey, $amount] = array_pop($stack);
                            $removed = min($amount, $remaining);
                            $series[$previousKey][$direction] -= $removed;
                            $remaining -= $removed;
                            if ($amount > $removed) { $stack[] = [$previousKey, $amount - $removed]; }
                        }
                    }
                }
            }
        }
        $started = $this->started();
        if ($series !== [] || $range['start'] < TrafficStatisticsRetention::hourlyStart()) {
            for ($time = $range['start']; $time->timestamp < min($range['end']->timestamp, now()->timestamp + 1); $time = match ($unit) {
                'minute' => $time->addSeconds(60), 'hour' => $time->addSeconds(3600), 'week' => $time->addWeek(), 'month' => $time->addMonthNoOverflow(), default => $time->addDay(),
            }) {
                $bucket = match ($unit) { 'week' => $time->startOfWeek(), 'month' => $time->startOfMonth(), default => $time };
                $empty = $bucket->timestamp < $started ? null : 0;
                $series[$bucket->timestamp] ??= ['date' => in_array($unit, ['hour', 'minute'], true) ? $bucket->toIso8601String() : $bucket->toDateString(), 'upload' => $empty, 'download' => $empty, 'total' => $empty];
            }
        }
        ksort($series);
        $series = $this->limitSeries($series, (int) ($range['max_points'] ?? 1000));
        $summary = ['upload' => 0, 'download' => 0, 'total' => 0];
        foreach ($series as &$row) {
            if ($row['upload'] === null) { continue; }
            $row['total'] = $row['upload'] + $row['download'];
            foreach (array_keys($summary) as $key) { $summary[$key] += $row[$key]; }
        }
        unset($row);
        return ['list' => array_values($series), 'summary' => $summary, 'meta' => $this->meta($range) + [
            'unit' => $unit, 'visualization' => $range['start'] < TrafficStatisticsRetention::hourlyStart() ? 'heatmap' : 'trend',
        ]];
    }

    /** 按连续时间桶合并，上传/下载仍按桶求和，迟到扣减的结果不会被抽点丢失。 */
    private function limitSeries(array $series, int $max): array
    {
        if ($max < 1 || count($series) <= $max) return $series;
        $points = array_values($series);
        $size = (int) ceil(count($points) / $max);
        $limited = [];
        foreach (array_chunk($points, $size) as $chunk) {
            $first = $chunk[0];
            $upload = $download = 0;
            $hasValue = false;
            foreach ($chunk as $point) {
                if ($point['upload'] === null || $point['download'] === null) continue;
                $hasValue = true;
                $upload += (int) $point['upload'];
                $download += (int) $point['download'];
            }
            $limited[] = [
                'date' => $first['date'],
                'upload' => $hasValue ? $upload : null,
                'download' => $hasValue ? $download : null,
                'total' => $hasValue ? $upload + $download : null,
            ];
        }
        return $limited;
    }
}
