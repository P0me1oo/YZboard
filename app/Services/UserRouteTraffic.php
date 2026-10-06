<?php

namespace App\Services;

use App\Models\NodeReportBatch;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class UserRouteTraffic
{
    public const TABLE = 'v2_stat_user_route_source';

    /** 由结算事务调用，保留采样原值，避免逐批截断采样差值而累计虚假直出。 */
    public function recordBatch(NodeReportBatch $batch): void
    {
        $snapshot = (array) $batch->server_snapshot;
        $rate = max(0.0, (float) ($snapshot['rate'] ?? 1));
        $entry = (int) $batch->server_id;
        foreach ((array) $batch->traffic as $uid => $traffic) {
            $u = max(0, (int) $traffic[0]); $d = max(0, (int) $traffic[1]);
            $this->record((int) $uid, $entry, (int) ($snapshot['id'] ?? $entry), 'entry', $rate,
                (int) $batch->record_at, $u, $d, (int) ($u * $rate), (int) ($d * $rate), $batch->created_at?->timestamp);
        }
        foreach ((array) $batch->relay_user_traffic as $uid => $nodes) {
            foreach ($nodes as $sid => $traffic) {
                $this->record((int) $uid, $entry, (int) $sid, 'relay', $rate,
                    (int) $batch->record_at, (int) $traffic[0], (int) $traffic[1], 0, 0, $batch->created_at?->timestamp);
            }
        }
    }

    public function record(int $uid, int $entry, int $sid, string $kind, float $rate, int $day,
        int $u, int $d, int $billedU, int $billedD, ?int $receivedAt): void
    {
        $started = (int) DB::table('v2_settings')->where('name', 'user_route_statistics_started_at')->value('value');
        if (!$started || $receivedAt === null || $receivedAt < $started || ($u === 0 && $d === 0)) {
            return;
        }
        $updates = [];
        foreach (['u', 'd', 'billed_u', 'billed_d'] as $column) {
            $updates[$column] = DB::raw(in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)
                ? "{$column} + VALUES({$column})" : self::TABLE . ".{$column} + excluded.{$column}");
        }
        DB::table(self::TABLE)->upsert([[
            'user_id' => $uid, 'entry_id' => $entry, 'server_id' => $sid, 'kind' => $kind,
            'rate' => (string) $rate, 'record_at' => $day, 'u' => $u, 'd' => $d,
            'billed_u' => $billedU, 'billed_d' => $billedD,
        ]], ['user_id', 'entry_id', 'server_id', 'kind', 'rate', 'record_at'], $updates);
    }

    private function source(array $range): Builder
    {
        return DB::table(self::TABLE)->where('record_at', '>=', $range['start']->timestamp)
            ->where('record_at', '<', $range['end']->timestamp);
    }

    /** 排行与明细使用同一归属：先按用户、入口、日期和倍率扣掉中转，再按节点筛选。 */
    public function actualQuery(array $range): Builder
    {
        $relay = $this->source($range)->where('kind', 'relay')
            ->selectRaw('user_id, entry_id, record_at, rate, SUM(u) AS u, SUM(d) AS d')
            ->groupBy('user_id', 'entry_id', 'record_at', 'rate');
        $entry = DB::query()->fromSub($this->source($range)->where('kind', 'entry'), 'entry_source')
            ->leftJoinSub($relay, 'relay_source', function ($join): void {
                foreach (['user_id', 'entry_id', 'record_at', 'rate'] as $key) {
                    $join->on('entry_source.' . $key, '=', 'relay_source.' . $key);
                }
            })->selectRaw('entry_source.user_id, entry_source.server_id, entry_source.record_at,
                CASE WHEN entry_source.u > COALESCE(relay_source.u, 0) THEN entry_source.u - COALESCE(relay_source.u, 0) ELSE 0 END AS u,
                CASE WHEN entry_source.d > COALESCE(relay_source.d, 0) THEN entry_source.d - COALESCE(relay_source.d, 0) ELSE 0 END AS d');
        $entry->unionAll($this->source($range)->where('kind', 'relay')->select('user_id', 'server_id', 'record_at', 'u', 'd'));
        return DB::query()->fromSub($entry, 'route_actual')->whereRaw('u + d > 0');
    }

    public function billedQuery(array $range): Builder
    {
        return $this->source($range)->where('kind', 'entry');
    }

    /** 将已扣费量归属到线路，不能再次按落地倍率计算或改变用户余额。 */
    public function details(int $uid, array $range): array
    {
        $groups = [];
        foreach ($this->source($range)->where('user_id', $uid)->orderBy('server_id')->get() as $row) {
            $key = $row->entry_id . ':' . $row->record_at . ':' . $row->rate;
            $groups[$key][] = $row;
        }
        $result = [];
        foreach ($groups as $group) {
            $entry = null; $routes = []; $relayU = 0; $relayD = 0;
            foreach ($group as $row) {
                if ($row->kind === 'entry') { $entry = $row; continue; }
                $routes[] = $this->row($row, 'relay');
                $relayU += (int) $row->u; $relayD += (int) $row->d;
            }
            if ($entry !== null) {
                $direct = $this->row($entry, 'direct');
                $direct['upload'] = max(0, $direct['upload'] - $relayU);
                $direct['download'] = max(0, $direct['download'] - $relayD);
                if ($direct['upload'] + $direct['download'] > 0) { $routes[] = $direct; }
            }
            usort($routes, fn ($a, $b) => $a['server_id'] <=> $b['server_id']);
            foreach (['upload' => 'billed_u', 'download' => 'billed_d'] as $direction => $column) {
                $total = array_sum(array_column($routes, $direction));
                $billed = (int) ($entry?->$column ?? 0);
                $accumulated = 0; $assigned = 0;
                foreach ($routes as &$route) {
                    $accumulated += $route[$direction];
                    // 使用整数十进制运算，固定取整归属，所有线路之和严格等于已扣量。
                    $next = $total > 0 ? (int) bcdiv(bcmul((string) $billed, (string) $accumulated, 0), (string) $total, 0) : 0;
                    $route['billed_' . $direction] = $next - $assigned;
                    $assigned = $next;
                }
                unset($route);
            }
            foreach ($routes as $route) {
                $day = CarbonImmutable::createFromTimestamp($route['record_at'], config('app.timezone'));
                $date = match ($range['unit']) {
                    'week' => $day->startOfWeek()->toDateString(),
                    'month' => $day->startOfMonth()->toDateString(),
                    default => $day->toDateString(),
                };
                $key = $date . ':' . $route['server_id'] . ':' . $route['kind'] . ':' . $route['rate'];
                $result[$key] ??= ['date' => $date, 'server_id' => $route['server_id'], 'kind' => $route['kind'],
                    'rate' => $route['rate'], 'upload' => 0, 'download' => 0, 'billed_upload' => 0, 'billed_download' => 0];
                foreach (['upload', 'download', 'billed_upload', 'billed_download'] as $column) {
                    $result[$key][$column] += $route[$column];
                }
            }
        }
        foreach ($result as &$row) {
            $row['total'] = $row['upload'] + $row['download'];
            $row['billed_total'] = $row['billed_upload'] + $row['billed_download'];
        }
        unset($row);
        return array_values($result);
    }

    private function row(object $row, string $kind): array
    {
        return ['server_id' => (int) $row->server_id, 'kind' => $kind, 'record_at' => (int) $row->record_at,
            'rate' => (float) $row->rate, 'upload' => (int) $row->u, 'download' => (int) $row->d];
    }
}
