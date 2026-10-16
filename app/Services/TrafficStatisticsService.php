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
    public function range(Request $request, int $retentionDays = TrafficStatisticsRetention::DAILY_DAYS): array
    {
        $input = $request->validate([
            'period' => 'sometimes|in:365d,30d,14d,7d,24h,today,all,custom',
            'start_date' => 'required_if:period,custom|date_format:Y-m-d',
            'end_date' => 'required_if:period,custom|date_format:Y-m-d|after_or_equal:start_date',
            'unit' => 'sometimes|in:day,week,month',
            'metric' => 'sometimes|in:actual,billed',
            'scope' => 'sometimes|in:node,machine',
            'page' => 'sometimes|integer|min:1|max:1000000',
            'page_size' => 'sometimes|integer|min:1|max:100',
            'search' => 'nullable|string|max:100',
            'min_traffic_mb' => 'sometimes|numeric|min:0|max:8589934591',
            'user_id' => 'sometimes|integer|min:1',
            'server_id' => 'sometimes|integer|min:1',
            'precision' => 'sometimes|in:auto',
            'max_points' => 'sometimes|integer|min:1|max:1000',
            'start_time' => 'required_with:end_time|date_format:H:i',
            'end_time' => 'required_with:start_time|date_format:H:i',
            'sort' => 'sometimes|in:node,upload,download,total',
            'direction' => 'sometimes|in:asc,desc',
        ]);
        $today = CarbonImmutable::today(config('app.timezone'));
        $oldest = $today->subDays($retentionDays - 1);
        $hourlyStart = TrafficStatisticsRetention::hourlyStart();
        $period = $input['period'] ?? '30d';
        $start = $period === 'custom' ? CarbonImmutable::parse($input['start_date'], config('app.timezone'))
            : $today->subDays(match ($period) { '7d' => 6, '14d' => 13, 'today' => 0, 'all', '365d' => $retentionDays - 1, default => 29 });
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
            throw ValidationException::withMessages(['start_date' => "请选择最近 {$retentionDays} 天内的日期。"]);
        }
        if ($start < $hourlyStart && ($start->format('H:i') !== '00:00' || $end->format('H:i') !== '00:00')) {
            throw ValidationException::withMessages(['start_time' => '30 天前仅保留日汇总，请选择完整日期。']);
        }
        if ($start < $today && ($start->minute !== 0 || $end->minute !== 0)) {
            throw ValidationException::withMessages(['start_time' => '跨日或历史查询请使用整点，分钟记录仅保留今天。']);
        }
        $started = (int) DB::table('v2_settings')->where('name', 'traffic_statistics_started_at')->value('value');
        $presets = [];
        foreach (['today' => 0, '7d' => 6, '14d' => 13, '30d' => 29, '365d' => $retentionDays - 1, 'all' => $retentionDays - 1, '24h' => 0] as $key => $days) {
            $presetEnd = $key === '24h' ? CarbonImmutable::now(config('app.timezone'))->startOfHour()->addHour() : $today->addDay();
            $presetStart = $key === '24h' ? $presetEnd->subHours(24) : $today->subDays($days);
            $presets[$key] = [$presetStart->format('Y-m-d\TH:i'), $presetEnd->format('Y-m-d\TH:i')];
        }
        return $input + [
            'start' => $start, 'end' => $end, 'unit' => 'day', 'metric' => 'actual', 'scope' => 'node',
            'page' => 1, 'page_size' => 20,
            'max_points' => min(1000, max(1, (int) ($input['max_points'] ?? 1000))),
            'meta' => [
                'start_date' => $start->toDateString(), 'end_date' => $end->subSecond()->toDateString(),
                'start_at' => $start->format('Y-m-d\TH:i'), 'end_at' => $end->format('Y-m-d\TH:i'),
                'now' => CarbonImmutable::now(config('app.timezone'))->format('Y-m-d\TH:i'),
                'presets' => $presets,
                'today' => $today->toDateString(), 'min_date' => $oldest->toDateString(),
                'timezone' => config('app.timezone'), 'retention_days' => $retentionDays,
                'fine_min_date' => $hourlyStart->toDateString(),
                'hourly_retention_days' => TrafficStatisticsRetention::HOURLY_DAYS,
                'visualization' => $start < $hourlyStart ? 'heatmap' : 'trend',
                'started_at' => $started ? CarbonImmutable::createFromTimestamp($started, config('app.timezone'))->toIso8601String() : null,
            ],
        ];
    }

    public function overview(array $range): array
    {
        return app(ProxyTrafficStatistics::class)->overview($range);
    }

    public function nodes(array $range): array
    {
        return app(ProxyTrafficStatistics::class)->ranking($range);
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
        $dailySource = ($range['meta']['visualization'] ?? '') === 'heatmap' ? clone $query : null;
        $sort = ($range['sort'] ?? 'total') === 'node' ? 'total' : ($range['sort'] ?? 'total');
        $query->selectRaw("user_id AS id, SUM({$u}) AS upload, SUM({$d}) AS download, SUM({$u} + {$d}) AS total")
            ->groupBy('user_id')->orderBy($sort, $range['direction'] ?? 'desc')->orderBy('user_id');
        if (($range['min_traffic_mb'] ?? 0) > 0) {
            // 先汇总用户在所选时段的流量再过滤，保证分页总数与排行一致。
            $minimum = (int) ceil((float) $range['min_traffic_mb'] * 1048576);
            $query->havingRaw("SUM({$u} + {$d}) >= ?", [$minimum]);
        }
        $matchingUsers = $dailySource !== null ? (clone $query)->reorder()->select('user_id') : null;
        $result = $this->ranking($query, $range, 'user');
        if ($dailySource !== null) {
            $days = $dailySource->whereIn('user_id', $matchingUsers)
                ->selectRaw("record_at, SUM({$u}) AS upload, SUM({$d}) AS download, SUM({$u} + {$d}) AS total")
                ->groupBy('record_at')->orderBy('record_at')->get();
            $result['daily'] = $days->map(fn ($row) => [
                'date' => CarbonImmutable::createFromTimestamp((int) $row->record_at, config('app.timezone'))->toDateString(),
                'upload' => (int) $row->upload, 'download' => (int) $row->download, 'total' => (int) $row->total,
            ])->all();
            $result['meta'] = $this->userMeta($range);
        }
        return $result;
    }

    public function user(array $range): array
    {
        $userId = (int) $range['user_id'];
        $rows = collect(app(UserRouteTraffic::class)->details($userId, $range));
        $serverIds = $rows->pluck('server_id')->unique()->values()->all();
        $nameSource = DB::table(UserRouteTraffic::TABLE)->where('user_id', $userId);
        if ($serverIds === []) $nameSource->whereRaw('1 = 0');
        else $nameSource->whereIn('server_id', $serverIds);
        $names = ServerNameHistory::joinNames($nameSource, UserRouteTraffic::TABLE)
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
        $daily = ($range['meta']['visualization'] ?? '') === 'heatmap'
            ? $rows->groupBy('date')->map(fn ($items, $date) => ['date' => $date] + $summarize($items, $range['metric'] === 'billed' ? 'billed_' : ''))
                ->sortKeys()->values()->all() : [];
        $direct = $summarize($rows->where('kind', 'direct')); $relay = $summarize($rows->where('kind', 'relay'));
        if (($range['min_traffic_mb'] ?? 0) > 0) {
            // 隐藏低流量明细，但保留所选用户、时间及节点范围的完整用量合计。
            $minimum = (int) ceil((float) $range['min_traffic_mb'] * 1048576);
            $rows = $rows->where($range['metric'] === 'billed' ? 'billed_total' : 'total', '>=', $minimum);
        }
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
            'daily' => $daily,
            'meta' => $this->userMeta($range),
        ];
    }

    private function userMeta(array $range): array
    {
        $started = (int) DB::table('v2_settings')->where('name', 'user_route_statistics_started_at')->value('value');
        return array_replace($range['meta'], ['started_at' => $started
            ? CarbonImmutable::createFromTimestamp($started, config('app.timezone'))->toIso8601String() : null]);
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

}
