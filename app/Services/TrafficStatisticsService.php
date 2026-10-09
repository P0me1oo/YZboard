<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TrafficStatisticsService
{
    public function range(Request $request): array
    {
        $input = $request->validate([
            'period' => 'sometimes|in:30d,14d,7d,24h,today,all,custom',
            'start_date' => 'required_if:period,custom|date_format:Y-m-d',
            'end_date' => 'required_if:period,custom|date_format:Y-m-d|after_or_equal:start_date',
            'unit' => 'sometimes|in:day,week,month',
            'metric' => 'sometimes|in:actual,billed',
            'page' => 'sometimes|integer|min:1|max:1000000',
            'page_size' => 'sometimes|integer|min:1|max:100',
            'search' => 'nullable|string|max:100',
            'min_traffic_mb' => 'sometimes|numeric|min:0|max:8589934591',
            'user_id' => 'sometimes|integer|min:1',
            'server_id' => 'sometimes|integer|min:1',
            'precision' => 'sometimes|in:auto',
            'start_time' => 'required_with:end_time|date_format:H:i',
            'end_time' => 'required_with:start_time|date_format:H:i',
            'sort' => 'sometimes|in:node,upload,download,total',
            'direction' => 'sometimes|in:asc,desc',
        ]);
        $today = CarbonImmutable::today(config('app.timezone'));
        $oldest = $today->subDays(29);
        $period = $input['period'] ?? '30d';
        $start = $period === 'custom' ? CarbonImmutable::parse($input['start_date'], config('app.timezone'))
            : $today->subDays(match ($period) { '7d' => 6, '14d' => 13, 'today' => 0, default => 29 });
        $end = $period === 'custom' ? CarbonImmutable::parse($input['end_date'], config('app.timezone')) : $today;
        $timed = isset($input['start_time'], $input['end_time']);
        if ($timed && ($period !== 'custom' || ($input['precision'] ?? '') !== 'auto')) {
            throw ValidationException::withMessages(['start_time' => '请选择自定义时间范围。']);
        }
        $end = $end->addDay();
        if ($timed) {
            $start = $start->setTimeFromTimeString($input['start_time']);
            $end = $end->subDay()->setTimeFromTimeString($input['end_time']);
            if ($start->format('Y-m-d H:i') !== $input['start_date'] . ' ' . $input['start_time']
                || $end->format('Y-m-d H:i') !== $input['end_date'] . ' ' . $input['end_time']) {
                throw ValidationException::withMessages(['start_time' => '该本地时间不存在，请按面板时区重新选择。']);
            }
        }
        if ($period === '24h') { $end = CarbonImmutable::now(config('app.timezone'))->startOfHour()->addHour(); $start = $end->subHours(24); }
        if ($start < $oldest || $end > $today->addDay() || $start >= $end) {
            throw ValidationException::withMessages(['start_date' => '请选择最近 30 天内的日期。']);
        }
        if ($start < $today && ($start->minute !== 0 || $end->minute !== 0)) {
            throw ValidationException::withMessages(['start_time' => '跨日或历史查询请使用整点，分钟记录仅保留今天。']);
        }
        $started = (int) DB::table('v2_settings')->where('name', 'traffic_statistics_started_at')->value('value');
        $presets = [];
        foreach (['today' => 0, '7d' => 6, '14d' => 13, '30d' => 29, 'all' => 29, '24h' => 0] as $key => $days) {
            $presetEnd = $key === '24h' ? CarbonImmutable::now(config('app.timezone'))->startOfHour()->addHour() : $today->addDay();
            $presetStart = $key === '24h' ? $presetEnd->subHours(24) : $today->subDays($days);
            $presets[$key] = [$presetStart->format('Y-m-d\TH:i'), $presetEnd->format('Y-m-d\TH:i')];
        }
        return $input + [
            'start' => $start, 'end' => $end, 'unit' => 'day', 'metric' => 'actual',
            'page' => 1, 'page_size' => 20,
            'meta' => [
                'start_date' => $start->toDateString(), 'end_date' => $end->subSecond()->toDateString(),
                'start_at' => $start->format('Y-m-d\TH:i'), 'end_at' => $end->format('Y-m-d\TH:i'),
                'now' => CarbonImmutable::now(config('app.timezone'))->format('Y-m-d\TH:i'),
                'presets' => $presets,
                'today' => $today->toDateString(), 'min_date' => $oldest->toDateString(),
                'timezone' => config('app.timezone'), 'retention_days' => 30,
                'started_at' => $started ? CarbonImmutable::createFromTimestamp($started, config('app.timezone'))->toIso8601String() : null,
            ],
        ];
    }

    public function overview(array $range): array
    {
        if (($range['precision'] ?? '') === 'auto') { return $this->fineOverview($range); }
        $query = $this->between(DB::table('v2_stat_server')->where('record_type', 'd'), $range);
        $summary = $this->summary($query);
        if ($range['start']->addDay()->equalTo($range['end'])) {
            $hourly = $this->hourlyOverview($range, $summary);
            if ($hourly !== null) {
                return $hourly;
            }
            $range['unit'] = 'day';
        }
        $rows = (clone $query)->selectRaw('record_at, SUM(u) AS upload, SUM(d) AS download')
            ->groupBy('record_at')->get()->keyBy('record_at');
        $series = [];
        $firstRecorded = DB::table('v2_stat_server')->where('record_type', 'd')->min('record_at');
        $firstDay = $firstRecorded === null ? $range['end']
            : CarbonImmutable::createFromTimestamp((int) $firstRecorded, config('app.timezone'))->startOfDay();
        for ($day = $range['start']; $day < $range['end']; $day = $day->addDay()) {
            // 升级前已接收但尚未结算的批次可能迟到，保留确实收集到的日期。
            if ($day < $firstDay && !$rows->has($day->timestamp)) {
                continue;
            }
            $bucket = $this->bucket($day, $range['unit']);
            $series[$bucket] ??= ['date' => $bucket, 'upload' => 0, 'download' => 0, 'total' => 0];
            $row = $rows[$day->timestamp] ?? null;
            $series[$bucket]['upload'] += (int) ($row->upload ?? 0);
            $series[$bucket]['download'] += (int) ($row->download ?? 0);
            $series[$bucket]['total'] = $series[$bucket]['upload'] + $series[$bucket]['download'];
        }
        return ['list' => array_values($series), 'summary' => $summary, 'meta' => $range['meta'] + ['unit' => $range['unit']]];
    }

    private function fineOverview(array $range): array
    {
        $query = app(FineTrafficStatistics::class)->source($range, 'node', true);
        $summary = $this->summary($query);
        $rows = $query->selectRaw('record_at, bucket_unit, SUM(u) AS upload, SUM(d) AS download')
            ->groupBy('record_at', 'bucket_unit')->orderBy('record_at')->get();
        $unit = $rows->contains('bucket_unit', 'day') || $range['end']->timestamp - $range['start']->timestamp > 172800
            ? 'day' : ($range['start'] >= CarbonImmutable::today(config('app.timezone')) ? 'minute' : 'hour');
        $series = [];
        foreach ($rows as $row) {
            $time = CarbonImmutable::createFromTimestamp((int) $row->record_at, config('app.timezone'));
            $key = $unit === 'day' ? $time->startOfDay()->timestamp : $time->timestamp;
            $series[$key] ??= ['date' => $unit === 'day' ? $time->toDateString() : $time->toIso8601String(), 'upload' => 0, 'download' => 0, 'total' => 0];
            $series[$key]['upload'] += (int) $row->upload;
            $series[$key]['download'] += (int) $row->download;
            $series[$key]['total'] = $series[$key]['upload'] + $series[$key]['download'];
        }
        // 细分时间轴从查询起点展开；启用前缺少记录的时段保留为空。
        if ($rows->isNotEmpty()) {
            $first = $unit === 'day'
                ? CarbonImmutable::createFromTimestamp((int) $rows->first()->record_at, config('app.timezone'))
                : $range['start'];
            $started = (int) DB::table('v2_settings')->where('name', 'fine_traffic_started_at')->value('value');
            $last = min($range['end']->timestamp, now()->timestamp + 1);
            for ($time = $first; $time->timestamp < $last; $time = match ($unit) {
                'minute' => $time->addSeconds(60), 'hour' => $time->addSeconds(3600), default => $time->addDay(),
            }) {
                $key = $unit === 'day' ? $time->startOfDay()->timestamp : $time->timestamp;
                $empty = $unit !== 'day' && (!$started || $time->timestamp < $started) ? null : 0;
                $series[$key] ??= ['date' => $unit === 'day' ? $time->toDateString() : $time->toIso8601String(), 'upload' => $empty, 'download' => $empty, 'total' => $empty];
            }
        }
        ksort($series);
        return ['list' => array_values($series), 'summary' => $summary, 'meta' => $range['meta'] + ['unit' => $unit]];
    }

    private function hourlyOverview(array $range, array $summary): ?array
    {
        $started = (int) DB::table('v2_settings')->where('name', 'node_hourly_started_at')->value('value');
        if (!$started || $range['end']->timestamp <= $started || $summary['total'] === 0) {
            return null;
        }
        $query = $this->between(DB::table('v2_stat_node_hour'), $range);
        // 升级当天及旧任务可能只有日记录；不把缺失的小时流量伪装成零。
        if ($this->summary($query) !== $summary) {
            return null;
        }
        $rows = $query->get()->keyBy('record_at');
        $first = max($range['start']->timestamp, CarbonImmutable::createFromTimestamp($started, config('app.timezone'))->startOfHour()->timestamp);
        $last = min($range['end']->timestamp, now()->timestamp + 1);
        $series = [];
        // 按真实经过的小时递增，保留夏令时重复小时的时区偏移。
        for ($timestamp = $first; $timestamp < $last; $timestamp += 3600) {
            $row = $rows[$timestamp] ?? null;
            $u = (int) ($row->u ?? 0); $d = (int) ($row->d ?? 0);
            $series[] = ['date' => CarbonImmutable::createFromTimestamp($timestamp, config('app.timezone'))->toIso8601String(),
                'upload' => $u, 'download' => $d, 'total' => $u + $d];
        }
        return ['list' => $series, 'summary' => $summary, 'meta' => $range['meta'] + ['unit' => 'hour']];
    }

    public function nodes(array $range): array
    {
        $fine = ($range['precision'] ?? '') === 'auto';
        $query = $fine ? app(FineTrafficStatistics::class)->source($range, 'node')
            : $this->between(DB::table('v2_stat_server')->where('record_type', 'd'), $range);
        ServerNameHistory::joinNames($query, $fine ? 'fine_source' : 'v2_stat_server');
        $search = trim($range['search'] ?? '');
        if ($search !== '') {
            $pattern = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)) . '%';
            $query->whereRaw("LOWER(node_name) LIKE ? ESCAPE '!'", [$pattern]);
        }
        $query->selectRaw('server_id AS id, SUM(u) AS upload, SUM(d) AS download, SUM(u + d) AS total')
            ->addSelect('node_name', 'node_deleted')->groupBy('server_id', 'node_name', 'node_deleted');
        $sort = $range['sort'] ?? 'total';
        $direction = $range['direction'] ?? 'desc';
        if ($sort === 'node') {
            // 在分页前沿用节点管理的排序，已删除节点统一放在现有节点后面。
            $query->groupBy('node_sort')->orderBy('node_deleted')->orderBy('node_sort', $direction)
                ->orderBy('server_id', $direction);
        } else {
            $query->orderBy($sort, $direction)->orderBy('server_id');
        }
        return $this->ranking($query, $range, 'node');
    }

    public function users(array $range): array
    {
        $routes = app(UserRouteTraffic::class);
        $query = $range['metric'] === 'billed'
            ? $routes->billedQuery($range)
            : $routes->actualQuery($range);
        $search = trim($range['search'] ?? '');
        if ($search !== '') {
            $query->where(function (Builder $query) use ($search): void {
                $query->whereIn('user_id', $this->userSearch($search)->select('id'));
                if (ctype_digit($search)) {
                    $query->orWhere('user_id', (int) $search);
                }
            });
        }
        $u = $range['metric'] === 'billed' ? 'billed_u' : 'u';
        $d = $range['metric'] === 'billed' ? 'billed_d' : 'd';
        $sort = ($range['sort'] ?? 'total') === 'node' ? 'total' : ($range['sort'] ?? 'total');
        $query->selectRaw("user_id AS id, SUM({$u}) AS upload, SUM({$d}) AS download, SUM({$u} + {$d}) AS total")
            ->groupBy('user_id')->orderBy($sort, $range['direction'] ?? 'desc')->orderBy('user_id');
        if (($range['min_traffic_mb'] ?? 0) > 0) {
            // 先汇总用户在所选时段的流量再过滤，保证分页总数与排行一致。
            $minimum = (int) ceil((float) $range['min_traffic_mb'] * 1048576);
            $query->havingRaw("SUM({$u} + {$d}) >= ?", [$minimum]);
        }
        return $this->ranking($query, $range, 'user');
    }

    public function user(array $range): array
    {
        $userId = (int) $range['user_id'];
        $rows = collect(app(UserRouteTraffic::class)->details($userId, $range));
        $names = ServerNameHistory::joinNames(DB::table(UserRouteTraffic::TABLE)->where('user_id', $userId), UserRouteTraffic::TABLE)
            ->select('server_id', 'node_name', 'node_deleted', 'node_sort')->distinct()
            ->orderBy('node_deleted')->orderBy('node_sort')->orderBy('server_id')->get()->keyBy('server_id');
        $nodeOrder = $names->keys()->flip();
        $allNodes = $names->only($rows->pluck('server_id')->unique()->all())
            ->map(fn ($node) => ['id' => (int) $node->server_id, 'name' => ServerNameHistory::label($node)])->values()->all();
        if (!empty($range['server_id'])) { $rows = $rows->where('server_id', (int) $range['server_id']); }
        // 合计先于名称过滤，已删除且没有名称的节点仍保留真实用量与扣费。
        $summarize = fn ($items, string $prefix = '') => [
            'upload' => (int) $items->sum($prefix . 'upload'), 'download' => (int) $items->sum($prefix . 'download'),
            'total' => (int) $items->sum($prefix . 'total'),
        ];
        $actual = $summarize($rows); $billed = $summarize($rows, 'billed_');
        $direct = $summarize($rows->where('kind', 'direct')); $relay = $summarize($rows->where('kind', 'relay'));
        $sort = $range['sort'] ?? 'total';
        $direction = ($range['direction'] ?? 'desc') === 'asc' ? 1 : -1;
        $rows = $rows->filter(fn ($row) => $names->has($row['server_id']))
            ->map(fn ($row) => $row + ['server_name' => ServerNameHistory::label($names[$row['server_id']])])
            ->sort(function ($a, $b) use ($sort, $direction, $names, $nodeOrder): int {
                $order = $sort === 'node'
                    ? ($names[$a['server_id']]->node_deleted <=> $names[$b['server_id']]->node_deleted)
                        ?: $direction * ($nodeOrder[$a['server_id']] <=> $nodeOrder[$b['server_id']])
                    : $direction * ($a[$sort] <=> $b[$sort]);
                return $order ?: strcmp($b['date'], $a['date']) ?: ($a['server_id'] <=> $b['server_id'])
                    ?: strcmp($a['kind'], $b['kind']) ?: ($a['rate'] <=> $b['rate']);
            })->values();
        $count = $rows->count(); $size = (int) $range['page_size']; $page = (int) $range['page'];
        return [
            'user' => ['id' => $userId, 'name' => User::whereKey($userId)->value('email') ?? '用户 #' . $userId],
            'list' => $rows->slice(($page - 1) * $size, $size)->values()->all(), 'total' => $count,
            'page' => $page, 'last_page' => max(1, (int) ceil($count / $size)), 'page_size' => $size,
            'actual_summary' => $actual, 'billed_summary' => $billed,
            'direct_summary' => $direct, 'relay_summary' => $relay, 'nodes' => $allNodes,
            'meta' => $range['meta'],
        ];
    }

    public function searchUsers(string $search): array
    {
        if (trim($search) === '') {
            return [];
        }
        return $this->userSearch(trim($search))->orderBy('id')->limit(20)->get(['id', 'email AS name'])->all();
    }

    private function userSearch(string $search): Builder
    {
        $pattern = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)) . '%';
        return DB::table('v2_user')->where(function (Builder $query) use ($pattern, $search): void {
            $query->whereRaw("LOWER(email) LIKE ? ESCAPE '!'", [$pattern]);
            if (ctype_digit($search)) {
                $query->orWhere('id', (int) $search);
            }
        });
    }

    private function between(Builder $query, array $range): Builder
    {
        return $query->where('record_at', '>=', $range['start']->timestamp)->where('record_at', '<', $range['end']->timestamp);
    }

    private function summary(Builder $query, string $metric = 'actual'): array
    {
        $u = $metric === 'billed' ? 'billed_u' : 'u';
        $d = $metric === 'billed' ? 'billed_d' : 'd';
        $row = (clone $query)->selectRaw("SUM({$u}) AS upload, SUM({$d}) AS download")->first();
        $upload = (int) ($row->upload ?? 0);
        $download = (int) ($row->download ?? 0);
        return ['upload' => $upload, 'download' => $download, 'total' => $upload + $download];
    }

    private function ranking(Builder $query, array $range, string $type): array
    {
        $page = $query->paginate((int) $range['page_size'], ['*'], 'page', (int) $range['page']);
        $ids = collect($page->items())->pluck('id');
        $names = $type === 'user' ? User::whereIn('id', $ids)->pluck('email', 'id') : collect();
        $list = array_map(fn ($row) => [
            'id' => (int) $row->id, 'name' => $type === 'node' ? ServerNameHistory::label($row) : ($names[$row->id] ?? '用户 #' . $row->id),
            'upload' => (int) $row->upload, 'download' => (int) $row->download, 'total' => (int) $row->total,
        ], $page->items());
        return ['list' => $list, 'total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'page_size' => $page->perPage(), 'meta' => $range['meta']];
    }

    private function bucket(CarbonImmutable $day, string $unit): string
    {
        return match ($unit) {
            'week' => $day->startOfWeek()->toDateString(),
            'month' => $day->startOfMonth()->toDateString(),
            default => $day->toDateString(),
        };
    }

    /** 使用最多 30 个日期边界，兼容 SQLite、MySQL 和 PostgreSQL。 */
    private function bucketExpression(array $range): array
    {
        $parts = [];
        $bindings = [];
        for ($day = $range['start']; $day < $range['end']; $day = $day->addDay()) {
            $parts[] = 'WHEN record_at >= ? AND record_at < ? THEN ?';
            array_push($bindings, $day->timestamp, $day->addDay()->timestamp, $this->bucket($day, $range['unit']));
        }
        return ['CASE ' . implode(' ', $parts) . ' END', $bindings];
    }
}
