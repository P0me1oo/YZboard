<?php

namespace App\Services;

use App\Models\Server;
use Illuminate\Support\Facades\DB;

class ServerAutoSort
{
    public static function onReport(Server $node): void
    {
        if (!$node->auto_sort_pending || $node->balancer_role !== null) return;
        $enabled = (bool) admin_setting('server_auto_sort_enable', false);
        $country = $enabled ? SubscriptionServerName::country($node->name) : null;
        // 尚未识别时等待后续上报；关闭期间已上报的节点不留待重新开启后移动。
        if ($enabled && $country === null) return;

        DB::transaction(function () use ($node, $enabled): void {
            // 同时首次上报的节点共用相同锁顺序，插入位置和一次性标记在同一事务提交。
            $servers = Server::whereNull('balancer_role')->orderBy('sort')->orderBy('id')->lockForUpdate()->get();
            $current = $servers->firstWhere('id', $node->id);
            if (!$current || !$current->auto_sort_pending) return;

            $country = $enabled ? SubscriptionServerName::country($current->name) : null;
            if ($enabled && $country === null) return;
            $groups = self::groups($current);
            $others = $servers->reject(fn (Server $server) => $server->id === $current->id)->values();
            $anchor = $country === null ? null : $others->last(fn (Server $server) =>
                self::groups($server) === $groups && SubscriptionServerName::country($server->name) === $country);

            if ($anchor !== null) {
                $position = $others->search(fn (Server $server) => $server->id === $anchor->id);
                $others->splice($position + 1, 0, [$current]);
                if ($others->pluck('id')->all() !== $servers->pluck('id')->all()) {
                    foreach ($others as $index => $server) {
                        if ($server->sort === null || (int) $server->sort !== $index + 1) {
                            Server::whereKey($server->id)->toBase()->update(['sort' => $index + 1]);
                        }
                    }
                }
            }

            Server::whereKey($current->id)->toBase()->update(['auto_sort_pending' => false]);
            NodeRuntimeMetadata::invalidate(Server::class);
        }, 3);
    }

    private static function groups(Server $server): array
    {
        $ids = array_values(array_unique(array_map('intval', $server->group_ids ?? [])));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }
}
