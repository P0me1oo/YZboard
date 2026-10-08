<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ServerNameHistory
{
    /** 与节点删除共用事务，只保存删除前的名称，不保存节点配置。 */
    public static function remember(Collection $servers): void
    {
        $rows = $servers->filter(fn ($server) => trim((string) $server->name) !== '')
            ->map(fn ($server) => ['server_id' => $server->id, 'name' => $server->name, 'deleted_at' => now()->timestamp])
            ->values()->all();
        if ($rows !== []) {
            DB::table('v2_stat_server_name')->upsert($rows, ['server_id'], ['name', 'deleted_at']);
        }
    }

    /** 在分页前排除无法恢复名称的旧节点，保留完整的历史流量汇总。 */
    public static function joinNames(Builder $query, string $table): Builder
    {
        $current = DB::table('v2_server')->whereNotNull('name')->whereRaw("TRIM(name) <> ''");
        $names = (clone $current)->selectRaw('id AS named_server_id, name AS node_name, sort AS node_sort, 0 AS node_deleted')
            ->unionAll(DB::table('v2_stat_server_name')->whereRaw("TRIM(name) <> ''")
                ->whereNotIn('server_id', (clone $current)->select('id'))
                ->selectRaw('server_id AS named_server_id, name AS node_name, NULL AS node_sort, 1 AS node_deleted'));
        return $query->joinSub($names, 'stat_node_names', 'stat_node_names.named_server_id', '=', $table . '.server_id');
    }

    public static function label(object $row): string
    {
        return $row->node_name . ($row->node_deleted ? '（已删除）' : '');
    }
}
