<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InboundIpStatistics
{
    public function range(Request $request): array
    {
        $request->validate([
            'period' => 'sometimes|in:today,7d,14d,30d,all,custom',
            'start_time' => 'prohibited', 'end_time' => 'prohibited',
            'province' => 'nullable|string|max:128',
        ]);
        if (!$request->has('period')) $request->merge(['period' => 'today']);
        $range = app(TrafficStatisticsService::class)->range($request) + ['province' => $request->input('province')];
        // 流量开始时间不代表入站 IP 开始时间，不向本接口提供无关的历史起点。
        unset($range['meta']['started_at']);
        return $range;
    }

    private function base(array $range): Builder
    {
        $query = DB::table('v2_stat_user_inbound_ip as h')->join('v2_inbound_ip as i', 'i.ip', '=', 'h.ip')
            ->where('h.record_at', '>=', $range['start']->timestamp)->where('h.record_at', '<', $range['end']->timestamp);
        DeviceIpExclusion::constrainQuery($query);
        return $query;
    }

    public function users(array $range): array
    {
        $query = $this->base($range)->leftJoin('v2_user as u', 'u.id', '=', 'h.user_id');
        $search = trim($range['search'] ?? '');
        if ($search !== '') {
            $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search);
            $query->where(function ($part) use ($escaped, $search): void {
                $part->whereRaw("u.email LIKE ? ESCAPE '!'", ['%' . $escaped . '%']);
                if (ctype_digit($search)) $part->orWhere('h.user_id', $search);
            });
        }
        $query->selectRaw('h.user_id AS id, u.email AS name, COUNT(DISTINCT h.ip) AS ip_count, MIN(h.first_seen_at) AS first_seen_at, MAX(h.last_seen_at) AS last_seen_at')
            ->groupBy('h.user_id', 'u.email');
        return $this->paginate($query, $range, 'ip_count', 'desc');
    }

    public function user(array $range): array
    {
        $base = $this->base($range)->where('h.user_id', $range['user_id']);
        $local = (clone $base)->where('i.ip_version', 4)->where('i.local_expires_at', '<=', now()->timestamp)
            ->select('i.ip')->distinct()->orderBy('i.ip');
        // 本地查询分批完成，省份分类不依赖是否翻到某一页，也不占用外部接口额度。
        $local->chunkById(256, function ($rows): void {
            app(InboundIpLocation::class)->refreshLocal($rows->pluck('ip')->all());
        }, 'i.ip', 'ip');
        // 首次打开先补齐该用户待查询的少量地址，其余由定时任务完成，不把网络请求放进节点上报。
        $pending = (clone $base)->where('i.lookup_after', '<=', now()->timestamp)
            ->select('i.ip')->distinct()->orderBy('i.ip')->limit(32)->pluck('i.ip')->all();
        app(InboundIpLocation::class)->refresh($pending);

        $now = now()->timestamp;
        $province = "CASE WHEN i.ip_version = 6 AND i.external_expires_at <= $now THEN '未知' ELSE i.province END";
        $region = "CASE WHEN i.ip_version = 6 AND i.external_expires_at <= $now THEN '未知' ELSE i.region END";
        $query = clone $base;
        if (!empty($range['province'])) $query->whereRaw("($province) = ?", [$range['province']]);
        $query->selectRaw("i.ip, i.ip_version, $region AS region, $province AS province,
            CASE WHEN i.external_expires_at > $now THEN i.asn ELSE NULL END AS asn,
            CASE WHEN i.external_expires_at > $now THEN i.as_name ELSE NULL END AS as_name,
            MIN(h.first_seen_at) AS first_seen_at, MAX(h.last_seen_at) AS last_seen_at")
            ->groupBy('i.ip', 'i.ip_version', 'i.region', 'i.province', 'i.asn', 'i.as_name', 'i.external_expires_at');
        $result = $this->paginate($query, $range, 'province', 'asc');
        // 先算出最终分类再汇总，兼容 MariaDB 严格分组，并合并归为“未知”的过期地址。
        $classified = (clone $base)->selectRaw("h.ip, $province AS province");
        $groups = DB::query()->fromSub($classified, 'inbound_provinces')
            ->select('province')->selectRaw('COUNT(DISTINCT ip) AS count')
            ->groupBy('province')->orderBy('province')->get()
            ->map(fn ($row) => ['name' => $row->province, 'count' => (int) $row->count])->all();
        return $result + ['user' => ['id' => (int) $range['user_id'],
            'name' => DB::table('v2_user')->where('id', $range['user_id'])->value('email') ?? '#' . $range['user_id']],
            'provinces' => $groups, 'ip_count' => array_sum(array_column($groups, 'count'))];
    }

    private function paginate(Builder $query, array $range, string $sort, string $direction): array
    {
        $total = DB::query()->fromSub(clone $query, 'inbound_rows')->count();
        $page = min((int) $range['page'], max(1, (int) ceil($total / $range['page_size'])));
        $rows = (clone $query)->orderBy($sort, $direction)->orderBy($sort === 'ip_count' ? 'id' : 'i.ip')
            ->offset(($page - 1) * $range['page_size'])->limit($range['page_size'])->get()->map(function ($row): array {
                $result = (array) $row;
                foreach (['first_seen_at', 'last_seen_at'] as $field) {
                    $result[$field] = CarbonImmutable::createFromTimestamp((int) $result[$field], config('app.timezone'))->toIso8601String();
                }
                if (isset($result['id'])) {
                    $result['id'] = (int) $result['id'];
                    $result['name'] ??= '#' . $result['id'];
                    $result['ip_count'] = (int) $result['ip_count'];
                } else {
                    $result['ip_version'] = (int) $result['ip_version'];
                }
                return $result;
            })->all();
        return ['list' => $rows, 'total' => $total, 'page' => $page, 'page_size' => (int) $range['page_size'],
            'last_page' => max(1, (int) ceil($total / $range['page_size'])), 'meta' => $range['meta']];
    }
}
