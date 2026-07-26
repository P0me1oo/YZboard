<?php

namespace App\Observers;

use App\Models\Server;
use App\Services\NodeSyncService;

class ServerObserver
{
    public bool $afterCommit = true;

    /**
     * 影响入口节点出站与路由规则的字段。逻辑节点的这些字段变化时，入口必须重新下发配置。
     */
    private const RELAY_ENTRY_FIELDS = [
        'parent_id',
        'type',
        'host',
        'port',
        'server_port',
        'protocol_settings',
        'enabled',
        'vless_route',
    ];

    public function created(Server $server): void
    {
        $this->notifyMachineNodesChanged($server->machine_id);
        $this->notifyRelayEntry($server->parent_id);
    }

    public function updated(Server $server): void
    {
        if ($server->wasChanged('group_ids')) {
            NodeSyncService::notifyFullSync($server->id);
        } elseif ($server->wasChanged([
            'server_port',
            'protocol_settings',
            'type',
            'parent_id',
            'route_ids',
            'custom_outbounds',
            'custom_routes',
            'cert_config',
        ])) {
            NodeSyncService::notifyConfigUpdated($server->id);
        }

        if ($server->wasChanged(['machine_id', 'enabled'])) {
            $this->notifyMachineChange(
                $server->machine_id,
                $server->getOriginal('machine_id')
            );
        }

        if ($server->wasChanged(self::RELAY_ENTRY_FIELDS)) {
            $this->notifyRelayEntry($server->parent_id);
            $this->notifyRelayEntry($server->getOriginal('parent_id'));
        }
    }

    public function deleted(Server $server): void
    {
        $this->notifyMachineChange(null, $server->getOriginal('machine_id') ?: $server->machine_id);
        $this->notifyRelayEntry($server->getOriginal('parent_id') ?: $server->parent_id);
    }

    /**
     * 逻辑节点新增、变更、禁用或删除后，让入口节点重新生成中转出站和路由规则。
     */
    private function notifyRelayEntry(?int $entryId): void
    {
        if ($entryId) {
            NodeSyncService::notifyConfigUpdated((int) $entryId);
        }
    }

    private function notifyMachineChange(?int $newMachineId, ?int $oldMachineId): void
    {
        $notified = [];

        if ($newMachineId) {
            NodeSyncService::notifyMachineNodesChanged($newMachineId);
            $notified[] = $newMachineId;
        }

        if ($oldMachineId && !in_array($oldMachineId, $notified, true)) {
            NodeSyncService::notifyMachineNodesChanged($oldMachineId);
        }
    }

    private function notifyMachineNodesChanged(?int $machineId): void
    {
        if ($machineId) {
            NodeSyncService::notifyMachineNodesChanged($machineId);
        }
    }
}
