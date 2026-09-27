<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Models\User;
use App\Services\NodeSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

class CheckTrafficExceeded extends Command
{
    protected $signature = 'check:traffic-exceeded';
    protected $description = '检查流量超标用户并通知节点';

    public function handle()
    {
        $count = Redis::scard('traffic:pending_check');
        if ($count <= 0) {
            return;
        }

        $pendingUserIds = array_map('intval', Redis::spop('traffic:pending_check', $count));

        $exceededUsers = User::query()
            ->whereIn('id', $pendingUserIds)
            ->whereRaw('u + d >= transfer_enable')
            ->where('transfer_enable', '>', 0)
            ->where('banned', 0)
            ->select(['id', 'group_id', 'group_ids'])
            ->get();

        if ($exceededUsers->isEmpty()) {
            return;
        }

        $usersByGroup = [];
        foreach ($exceededUsers as $user) {
            foreach ($user->effectiveGroupIds() as $groupId) {
                $usersByGroup[$groupId][$user->id] = ['id' => $user->id];
            }
        }
        if ($usersByGroup === []) return;

        $servers = Server::where(function ($query) use ($usersByGroup) {
            foreach (array_keys($usersByGroup) as $groupId) {
                $query->orWhereJsonContains('group_ids', (string) $groupId)
                    ->orWhereJsonContains('group_ids', (int) $groupId);
            }
        })->get(['id', 'group_ids']);
        $notifiedCount = 0;
        foreach ($servers as $server) {
            if (!NodeSyncService::isNodeOnline($server->id)) continue;
            $users = [];
            foreach ($server->group_ids ?? [] as $groupId) {
                $users += $usersByGroup[(int) $groupId] ?? [];
            }
            if ($users === []) continue;
            NodeSyncService::push($server->id, 'sync.user.delta', [
                'action' => 'remove',
                'users' => array_values($users),
            ]);
            $notifiedCount++;
        }

        $this->info("Checked " . count($pendingUserIds) . " users, notified {$notifiedCount} nodes for " . $exceededUsers->count() . " exceeded users.");
    }
}
