<?php

namespace App\Observers;

use App\Models\Server;
use App\Models\ServerRoute;
use App\Services\NodeSyncService;

class ServerRouteObserver
{
    /** 保存路由和节点绑定在同一事务内完成，提交后再按最终状态推送。 */
    public bool $afterCommit = true;

    public function updated(ServerRoute $route): void
    {
        $this->notifyAffectedNodes($route->id);
    }

    public function deleted(ServerRoute $route): void
    {
        $this->notifyAffectedNodes($route->id);
    }

    /**
     * 通知绑定了该路由的全部节点。隐藏节点仍可能在运行，同样需要更新。
     */
    private function notifyAffectedNodes(int $routeId): void
    {
        $servers = Server::query()->get(['id', 'route_ids'])->filter(
            fn (Server $server) => in_array(
                (string) $routeId,
                array_map(fn ($id) => is_scalar($id) ? (string) (int) $id : '', (array) ($server->route_ids ?? [])),
                true
            )
        );

        foreach ($servers as $server) {
            NodeSyncService::notifyConfigUpdated($server->id);
        }
    }
}
