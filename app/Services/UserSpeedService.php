<?php

namespace App\Services;

use App\Models\Server;

/** 只读取最新运行快照，不写用户表或流量账本。单位为用户视角的字节/秒。 */
class UserSpeedService
{
    public function forUsers(iterable $users, ?array $runtime = null, ?iterable $nodes = null): array
    {
        $nodes ??= Server::query()->where(function ($query) {
            $query->whereNull('relay_entry_id')->orWhere('relay_entry_id', 0);
        })->get(['id', 'group_ids', 'enabled']);
        try {
            $runtime ??= app(RealtimeStateStore::class)->readMany(
                collect($nodes)->map(fn ($node) => 'node:' . $node->id)->all()
            );
        } catch (\Throwable) {
            $runtime = [];
        }
        $result = [];
        foreach ($users as $user) {
            $groups = $user->effectiveGroupIds();
            $upload = $download = 0;
            $known = $missing = false;
            $updatedAt = null;
            foreach ($nodes as $node) {
                $state = $runtime['node:' . $node->id] ?? null;
                $speeds = $state['data']['user_speeds'] ?? null;
                $value = $speeds[$user->id] ?? [0, 0];
                // 插件额外下发的用户和尚未结束的连接，以实际报告为准。
                if (!$value[0] && !$value[1] && ($node->enabled === false || !array_intersect($groups, $node->group_ids ?? []))) continue;
                if (!($state['fresh'] ?? false) || !is_array($speeds)) {
                    $missing = true;
                    continue;
                }
                $known = true;
                $upload += $value[0];
                $download += $value[1];
                $receivedAt = intdiv($state['received_at'], 1000);
                $updatedAt = $updatedAt === null ? $receivedAt : min($updatedAt, $receivedAt);
            }
            $result[$user->id] = [
                'upload_speed' => $known ? $upload : null,
                'download_speed' => $known ? $download : null,
                'speed_partial' => $known && $missing,
                'speed_updated_at' => $updatedAt,
            ];
        }
        return $result;
    }
}
