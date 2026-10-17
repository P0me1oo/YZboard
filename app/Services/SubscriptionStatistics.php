<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Support\SubscriptionProtectionIp;

class SubscriptionStatistics
{
    public function range(Request $request): array
    {
        $values = $request->validate([
            'period' => 'sometimes|in:today,7d,30d,90d,custom',
            'start_date' => 'required_if:period,custom|date_format:Y-m-d',
            'end_date' => 'required_if:period,custom|date_format:Y-m-d',
            'start_time' => 'prohibited', 'end_time' => 'prohibited',
            'page' => 'sometimes|integer|min:1|max:10000', 'page_size' => 'sometimes|integer|in:10,20,30,40,50,100',
            'search' => 'nullable|string|max:100', 'ip' => 'nullable|string|max:45',
            'client' => 'nullable|string|max:32', 'user_id' => 'sometimes|integer|min:1|max:4294967295',
            'client_version' => 'nullable|string|max:32',
        ]);
        $today = CarbonImmutable::now(config('app.timezone'))->startOfDay();
        $period = $values['period'] ?? 'today';
        $presets = [];
        foreach (['today' => 1, '7d' => 7, '30d' => 30, '90d' => 90] as $key => $days) {
            $presets[$key] = [$today->subDays($days - 1)->format('Y-m-d\TH:i'), $today->addDay()->format('Y-m-d\TH:i')];
        }
        $start = $period === 'custom' ? CarbonImmutable::parse($values['start_date'], config('app.timezone')) : CarbonImmutable::parse($presets[$period][0], config('app.timezone'));
        $end = $period === 'custom' ? CarbonImmutable::parse($values['end_date'], config('app.timezone'))->addDay() : $today->addDay();
        if ($start < $today->subDays(89) || $end > $today->addDay() || $start >= $end) {
            throw ValidationException::withMessages(['period' => '请选择最近 90 天内的有效日期范围']);
        }
        return $values + ['page' => 1, 'page_size' => 20, 'start' => $start->timestamp, 'end' => $end->timestamp,
            'meta' => ['today' => $today->toDateString(), 'min_date' => $today->subDays(89)->toDateString(),
                'detail_min_date' => $today->subDays(6)->toDateString(), 'retention_days' => 90, 'detail_retention_days' => 7,
                'timezone' => config('app.timezone'), 'start_at' => $start->format('Y-m-d\TH:i'), 'end_at' => $end->format('Y-m-d\TH:i'), 'presets' => $presets]];
    }

    private function base(array $range): Builder
    {
        $query = DB::table('v2_subscription_stat as s')->where('s.day', '>=', $range['start'])->where('s.day', '<', $range['end']);
        if (!empty($range['user_id'])) $query->where('s.user_id', $range['user_id']);
        if (!empty($range['ip'])) {
            $ip = SubscriptionProtectionIp::normalize($range['ip']);
            if ($ip && !str_contains($ip, '/')) $query->where('s.ip', $ip);
            else $query->whereRaw("LOWER(s.ip) LIKE ? ESCAPE '!'", [$this->like($range['ip'])]);
        }
        if (!empty($range['client'])) $query->whereRaw("LOWER(s.client) LIKE ? ESCAPE '!'", [$this->like($range['client'])]);
        return $query;
    }

    private function like(string $value): string
    {
        return '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower(trim($value))) . '%';
    }

    private function totals(Builder $query): Builder
    {
        return $query->selectRaw('SUM(s.count) AS count, SUM(CASE WHEN (s.status >= 200 AND s.status < 300) OR s.status = 304 THEN s.count ELSE 0 END) AS success_count,
            MIN(s.first_at) AS first_at, MAX(s.last_at) AS last_at');
    }

    public function users(array $range): array
    {
        $query = $this->base($range)->leftJoin('v2_user as u', 'u.id', '=', 's.user_id');
        $search = trim($range['search'] ?? '');
        if ($search !== '') $query->where(function ($part) use ($search): void {
            $part->whereRaw("LOWER(u.email) LIKE ? ESCAPE '!'", [$this->like($search)]);
            if (ctype_digit($search)) $part->orWhere('s.user_id', $search);
        });
        $this->totals($query)->addSelect('s.user_id', 'u.email as name')
            ->selectRaw('COUNT(DISTINCT s.ip) AS ip_count, COUNT(DISTINCT s.client_key) AS client_count')
            ->groupBy('s.user_id', 'u.email');
        return $this->paginate($query, $range, 's.user_id');
    }

    public function user(array $range): array
    {
        $query = $this->totals($this->base($range))->addSelect('s.ip', 's.client', 's.client_version', 's.client_key')
            ->groupBy('s.ip', 's.client', 's.client_version', 's.client_key');
        return $this->paginate($query, $range, 's.ip', 's.client_key') + ['user' => [
            'id' => (int) $range['user_id'], 'name' => DB::table('v2_user')->where('id', $range['user_id'])->value('email') ?? '#' . $range['user_id'],
        ]];
    }

    private function paginate(Builder $query, array $range, string ...$ties): array
    {
        $total = DB::query()->fromSub(clone $query, 'subscription_groups')->count();
        $page = min((int) $range['page'], max(1, (int) ceil($total / $range['page_size'])));
        $query->orderByDesc('last_at');
        foreach ($ties as $tie) $query->orderBy($tie);
        $rows = $query->offset(($page - 1) * $range['page_size'])->limit($range['page_size'])->get()->map(function ($row): array {
            $row = (array) $row;
            foreach (['count', 'success_count', 'user_id', 'ip_count', 'client_count'] as $field) if (isset($row[$field])) $row[$field] = (int) $row[$field];
            $row['failure_count'] = $row['count'] - $row['success_count'];
            foreach (['first_at', 'last_at'] as $field) $row[$field] = $this->time((int) $row[$field]);
            if (isset($row['user_id'])) $row['name'] ??= '#' . $row['user_id'];
            unset($row['client_key']);
            return $row;
        })->all();
        return $this->page($rows, $total, $page, $range);
    }

    public function events(array $range): array
    {
        // 与块合并使用同一数据库快照，避免一页同时读取合并前后的重复块。
        return DB::transaction(function () use ($range): array {
            $query = $this->base($range)->where('s.ip', SubscriptionProtectionIp::normalize($range['ip']) ?? $range['ip'])
                ->where('s.client', $range['client'])->where('s.client_version', $range['client_version'] ?? '')
                ->join('v2_subscription_block as b', 'b.stat_id', '=', 's.id')
                ->where('b.day', '>=', CarbonImmutable::now(config('app.timezone'))->startOfDay()->subDays(6)->timestamp);
            $total = (int) (clone $query)->sum('b.count');
            $page = min((int) $range['page'], max(1, (int) ceil($total / $range['page_size'])));
            $rows = $this->newestEvents($query, ($page - 1) * $range['page_size'], (int) $range['page_size']);
            return $this->page($rows, $total, $page, $range);
        });
    }

    /** 按时间归并重叠的块，翻页只跳过时间，不累计前面所有页的数据。 */
    private function newestEvents(Builder $query, int $skip, int $size): array
    {
        $blocks = (clone $query)->select('b.id', 'b.last_at', 's.status', 's.format')
            ->orderByDesc('b.last_at')->orderByDesc('b.id')->lazy(128)->getIterator();
        $blocks->rewind();
        $heap = new \SplPriorityQueue();
        $heap->setExtractFlags(\SplPriorityQueue::EXTR_BOTH);
        $active = [];
        $rows = [];
        while (count($rows) < $size && ($blocks->valid() || !$heap->isEmpty())) {
            $next = $blocks->valid() ? $blocks->current() : null;
            if ($heap->isEmpty() || ($next && [(int) $next->last_at, (int) $next->id] >= array_slice($heap->top()['priority'], 0, 2))) {
                $block = DB::table('v2_subscription_block')->where('id', $next->id)->first();
                if (!$block) throw new \RuntimeException('订阅记录查询快照失效');
                $times = app(SubscriptionTimeCodec::class)->decode((array) $block);
                $index = count($times) - 1;
                $active[$next->id] = ['times' => $times, 'status' => (int) $next->status, 'format' => $next->format];
                $heap->insert([(int) $next->id, $index], [$times[$index], (int) $next->id, $index]);
                $blocks->next();
                continue;
            }
            $entry = $heap->extract();
            [$id, $index] = $entry['data'];
            if ($skip > 0) $skip--;
            else $rows[] = ['at' => $this->time($entry['priority'][0]), 'status' => $active[$id]['status'], 'format' => $active[$id]['format']];
            if ($index > 0) $heap->insert([$id, $index - 1], [$active[$id]['times'][$index - 1], $id, $index - 1]);
            else unset($active[$id]);
        }
        return $rows;
    }

    public function days(array $range): array
    {
        $query = $this->totals($this->base($range))->where('s.ip', SubscriptionProtectionIp::normalize($range['ip']) ?? $range['ip'])
            ->where('s.client', $range['client'])->where('s.client_version', $range['client_version'] ?? '')
            ->addSelect('s.day', 's.status', 's.format')->groupBy('s.day', 's.status', 's.format');
        $result = $this->paginate($query, $range, 's.day', 's.status', 's.format');
        foreach ($result['list'] as &$row) {
            $row['day'] = CarbonImmutable::createFromTimestamp((int) $row['day'], config('app.timezone'))->toDateString();
            $row['status'] = (int) $row['status'];
        }
        unset($row);
        return $result;
    }

    private function page(array $rows, int $total, int $page, array $range): array
    {
        return ['list' => $rows, 'total' => $total, 'page' => $page, 'page_size' => (int) $range['page_size'],
            'last_page' => max(1, (int) ceil($total / $range['page_size'])), 'meta' => $range['meta']];
    }

    private function time(int $at): string { return CarbonImmutable::createFromTimestamp($at, config('app.timezone'))->toIso8601String(); }
}
