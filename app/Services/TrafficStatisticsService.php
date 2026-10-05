<?php

namespace App\Services;

use App\Models\Server;
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
            'period' => 'sometimes|in:30d,7d,today,custom',
            'start_date' => 'required_if:period,custom|date_format:Y-m-d',
            'end_date' => 'required_if:period,custom|date_format:Y-m-d|after_or_equal:start_date',
            'unit' => 'sometimes|in:day,week,month',
            'metric' => 'sometimes|in:actual,billed',
            'page' => 'sometimes|integer|min:1|max:1000000',
            'page_size' => 'sometimes|integer|min:1|max:100',
            'search' => 'nullable|string|max:100',
            'user_id' => 'sometimes|integer|min:1',
            'server_id' => 'sometimes|integer|min:1',
        ]);
        $today = CarbonImmutable::today(config('app.timezone'));
        $oldest = $today->subDays(29);
        $period = $input['period'] ?? '30d';
        $start = $period === 'custom' ? CarbonImmutable::parse($input['start_date'], config('app.timezone'))
            : $today->subDays(match ($period) { '7d' => 6, 'today' => 0, default => 29 });
        $end = $period === 'custom' ? CarbonImmutable::parse($input['end_date'], config('app.timezone')) : $today;
        if ($start < $oldest || $end > $today) {
            throw ValidationException::withMessages(['start_date' => '请选择最近 30 天内的日期。']);
        }
        $started = (int) DB::table('v2_settings')->where('name', 'traffic_statistics_started_at')->value('value');
        return $input + [
            'start' => $start, 'end' => $end->addDay(), 'unit' => 'day', 'metric' => 'actual',
            'page' => 1, 'page_size' => 20,
            'meta' => [
                'start_date' => $start->toDateString(), 'end_date' => $end->toDateString(),
                'today' => $today->toDateString(), 'min_date' => $oldest->toDateString(),
                'timezone' => config('app.timezone'), 'retention_days' => 30,
                'started_at' => $started ? CarbonImmutable::createFromTimestamp($started, config('app.timezone'))->toIso8601String() : null,
            ],
        ];
    }

    public function overview(array $range): array
    {
        $query = $this->between(DB::table('v2_stat_user_server')->where('kind', 'entry'), $range);
        $rows = (clone $query)->selectRaw('record_at, SUM(u) AS upload, SUM(d) AS download')
            ->groupBy('record_at')->get()->keyBy('record_at');
        $series = [];
        $started = $range['meta']['started_at'];
        $firstDay = $started ? CarbonImmutable::parse($started)->startOfDay() : $range['start'];
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
        return ['list' => array_values($series), 'summary' => $this->summary($query), 'meta' => $range['meta']];
    }

    public function nodes(array $range): array
    {
        $query = $this->between(DB::table('v2_stat_server')->where('record_type', 'd'), $range);
        $query->selectRaw('server_id AS id, SUM(u) AS upload, SUM(d) AS download, SUM(u + d) AS total')
            ->groupBy('server_id')->orderByDesc('total')->orderBy('server_id');
        return $this->ranking($query, $range, 'node');
    }

    public function users(array $range): array
    {
        $query = $range['metric'] === 'billed'
            ? DB::table('v2_stat_user')->where('record_type', 'd')
            : DB::table('v2_stat_user_server')->where('kind', 'entry');
        $this->between($query, $range);
        $search = trim($range['search'] ?? '');
        if ($search !== '') {
            $query->where(function (Builder $query) use ($search): void {
                $query->whereIn('user_id', $this->userSearch($search)->select('id'));
                if (ctype_digit($search)) {
                    $query->orWhere('user_id', (int) $search);
                }
            });
        }
        $query->selectRaw('user_id AS id, SUM(u) AS upload, SUM(d) AS download, SUM(u + d) AS total')
            ->groupBy('user_id')->orderByDesc('total')->orderBy('user_id');
        return $this->ranking($query, $range, 'user');
    }

    public function user(array $range): array
    {
        $userId = (int) $range['user_id'];
        $base = $this->between(DB::table('v2_stat_user_server')->where('user_id', $userId), $range);
        $allNodes = (clone $base)->select('server_id')->distinct()->pluck('server_id');
        $names = Server::whereIn('id', $allNodes)->pluck('name', 'id');
        if (!empty($range['server_id'])) {
            $base->where('server_id', $range['server_id']);
        }
        $entrySummary = $this->summary((clone $base)->where('kind', 'entry'), $range['metric']);
        // 落地没有独立扣费流量；不能伪装为零或与入口相加。
        $relaySummary = $this->summary((clone $base)->where('kind', 'relay'));
        [$expression, $bindings] = $this->bucketExpression($range);
        $query = (clone $base)->selectRaw($expression . ' AS date', $bindings)
            ->selectRaw('server_id, kind, SUM(u) AS upload, SUM(d) AS download, SUM(u + d) AS total,
                SUM(billed_u) AS billed_upload, SUM(billed_d) AS billed_download, SUM(billed_u + billed_d) AS billed_total')
            ->groupBy('date', 'server_id', 'kind')->orderByDesc('date')->orderBy('server_id')->orderBy('kind');
        $page = $query->paginate((int) $range['page_size'], ['*'], 'page', (int) $range['page']);
        $list = array_map(function ($row) use ($names): array {
            $item = (array) $row;
            $item['server_name'] = $names[$row->server_id] ?? '节点 #' . $row->server_id;
            foreach (['upload', 'download', 'total', 'billed_upload', 'billed_download', 'billed_total'] as $key) {
                $item[$key] = str_starts_with($key, 'billed_') && $row->kind === 'relay' ? null : (int) $item[$key];
            }
            return $item;
        }, $page->items());
        return [
            'user' => ['id' => $userId, 'name' => User::whereKey($userId)->value('email') ?? '用户 #' . $userId],
            'list' => $list, 'total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage(),
            'entry_summary' => $entrySummary, 'relay_summary' => $relaySummary,
            'nodes' => $allNodes->map(fn ($id) => ['id' => $id, 'name' => $names[$id] ?? '节点 #' . $id])->values()->all(),
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
        $names = $type === 'node' ? Server::whereIn('id', $ids)->pluck('name', 'id') : User::whereIn('id', $ids)->pluck('email', 'id');
        $list = array_map(fn ($row) => [
            'id' => (int) $row->id, 'name' => $names[$row->id] ?? ($type === 'node' ? '节点 #' : '用户 #') . $row->id,
            'upload' => (int) $row->upload, 'download' => (int) $row->download, 'total' => (int) $row->total,
        ], $page->items());
        return ['list' => $list, 'total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'meta' => $range['meta']];
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
