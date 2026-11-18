<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Models\User;
use App\Services\NodeSyncService;
use App\Services\ServerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

class CheckTrafficExceeded extends Command
{
    protected $signature = 'check:traffic-exceeded';
    protected $description = '检查流量超标用户并通知节点';

    public function handle()
    {
        $checked = 0;
        $remaining = min(1000, (int) Redis::scard('traffic:pending_check'));
        while ($remaining > 0) {
            $size = min(100, $remaining);
            $remaining -= $size;
            $ids = array_map('intval', Redis::spop('traffic:pending_check', $size) ?: []);
            if ($ids === []) break;
            try {
                $users = User::whereIn('id', $ids)->whereRaw('u + d >= transfer_enable')->where('banned', 0)
                    ->get(['id', 'group_id', 'group_ids']);
                $byGroup = [];
                foreach ($users as $user) foreach ($user->effectiveGroupIds() as $group) $byGroup[$group][$user->id] = ['id' => $user->id];
                foreach (NodeSyncService::serversInGroups(array_keys($byGroup)) as $node) {
                    if ($node->isRelayChild() || $node->balancer_role === \App\Services\DirectPoolService::ROLE
                        || $node->enabled === false || !NodeSyncService::isNodeOnline($node->id)) continue;
                    if (\App\Services\DirectPoolService::poolFor($node) || \App\Services\ServerRelayService::hasRelayChildren($node)
                        || $node->type === Server::TYPE_WIREGUARD) {
                        $sent = NodeSyncService::push($node->id, 'sync.users', ['users' => ServerService::getUsersForSync($node)->toArray()]);
                    } else {
                        $removals = [];
                        foreach ($node->group_ids ?? [] as $group) $removals += $byGroup[(int) $group] ?? [];
                        if ($removals === []) continue;
                        $sent = NodeSyncService::push($node->id, 'sync.user.delta', ['action' => 'remove', 'users' => array_values($removals)]);
                    }
                    if (!$sent) throw new \RuntimeException('流量超额通知未送达');
                }
                $checked += count($ids);
            } catch (\Throwable $exception) {
                Redis::sadd('traffic:pending_check', ...$ids);
                \App\Services\FailureLog::record('流量超额检查失败，保留待检用户', $exception);
                return self::FAILURE;
            }
        }
        $this->info("已检查 {$checked} 个待检用户。");
        return self::SUCCESS;
    }
}
