<?php

namespace App\Services;

use App\Models\Server;
use Illuminate\Support\Facades\Cache;

class UserConnectionService
{
    /** 每个实际入口保存一份完整快照；不累加重复报告，也不重复计算中转落地。 */
    public function replace(Server $node, array $counts): void
    {
        if ($node->isRelayChild()) return;
        $normalized = [];
        foreach ($counts as $userKey => $value) {
            $userId = filter_var($userKey, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $count = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if ($userId === false || $count === false || is_bool($value)) {
                // 无法确认完整性的报告不能把旧连接误清零。
                return;
            }
            $normalized[(int) $userId] = (int) $count;
        }
        Cache::put($this->key((int) $node->id), [
            'counts' => $normalized,
            'updated_at' => now()->timestamp,
        ], max(60, (int) admin_setting('server_push_interval', 60) * 3));
    }

    /** 一次批量读缓存供整页使用；旧节点、超时和缓存故障不冒充零连接。 */
    public function forUsers(iterable $users, ?array $runtime = null): array
    {
        $nodes = Server::query()->where(function ($query) {
            $query->whereNull('relay_entry_id')->orWhere('relay_entry_id', 0);
        })->get(['id', 'group_ids', 'enabled']);
        try {
            $snapshots = Cache::many($nodes->map(fn ($node) => $this->key((int) $node->id))->all());
            $runtime ??= app(RealtimeStateStore::class)->readMany($nodes->map(fn ($node) => 'node:' . $node->id)->all());
            foreach ($nodes as $node) {
                $state = $runtime['node:' . $node->id] ?? null;
                if ($state === null) continue;
                $snapshots[$this->key((int) $node->id)] = $state['fresh'] && isset($state['data']['connection_counts'])
                    ? ['counts' => $state['data']['connection_counts'], 'updated_at' => intdiv($state['received_at'], 1000)]
                    : null;
            }
        } catch (\Throwable) {
            $snapshots = [];
        }
        $result = [];
        foreach ($users as $user) {
            $groups = $user->effectiveGroupIds();
            $total = 0;
            $known = false;
            $missing = false;
            $updatedAt = null;
            foreach ($nodes as $node) {
                $snapshot = $snapshots[$this->key((int) $node->id)] ?? null;
                $count = $snapshot['counts'][$user->id] ?? 0;
                // 插件额外下发的用户也按真实报告计数，不只依赖权限组。
                if (!$count && ($node->enabled === false || !array_intersect($groups, $node->group_ids ?? []))) continue;
                if (!is_array($snapshot)) {
                    $missing = true;
                    continue;
                }
                $known = true;
                $total += $count;
                $updatedAt = $updatedAt === null ? $snapshot['updated_at'] : min($updatedAt, $snapshot['updated_at']);
            }
            $result[$user->id] = [
                'connection_count' => $known ? $total : null,
                'connection_count_partial' => $known && $missing,
                'connection_count_updated_at' => $updatedAt,
            ];
        }
        return $result;
    }

    private function key(int $nodeId): string
    {
        return 'user_connections:v1:node:' . $nodeId;
    }
}
