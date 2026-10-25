<?php

namespace App\Observers;

use App\Models\Server;
use App\Services\DeviceHandoverService;
use App\Services\NodeSyncService;

class ServerObserver
{
    /**
     * 影响入口节点出站与路由规则的字段。逻辑节点的这些字段变化时，入口必须重新下发配置。
     */
    private const RELAY_ENTRY_FIELDS = [
        'relay_entry_id',
        'relay_pool_id',
        'type',
        'host',
        'port',
        'server_port',
        'protocol_settings',
        'enabled',
        'kernel_type',
        'vless_route',
        'group_ids',
    ];

    public function created(Server $server): void
    {
        $snapshot = clone $server;
        $server->getConnection()->afterCommit(function () use ($snapshot) {
            \App\Services\DdnsService::notify();
            $this->notifyMachineNodesChanged($snapshot->machine_id);
            $this->notifyRelayEntry($snapshot->relay_entry_id);
            $this->notifyRelayPool($snapshot->relay_pool_id);
        });
    }

    public function updated(Server $server): void
    {
        $snapshot = clone $server;
        $server->getConnection()->afterCommit(fn () => $this->updatedAfterCommit($snapshot));
    }

    private function updatedAfterCommit(Server $server): void
    {
        if ($server->wasChanged(['host', 'enabled', 'group_ids', 'relay_entry_id', 'relay_pool_id'])) \App\Services\DdnsService::notify();
        if ($server->wasChanged('group_ids')) {
            NodeSyncService::notifyFullSync($server->id);
        } elseif ($server->wasChanged([
            'server_port',
            'protocol_settings',
            'type',
            'relay_entry_id',
            'relay_pool_id',
            'route_ids',
            'custom_outbounds',
            'custom_routes',
            'source_policy',
            'accept_proxy_protocol',
            'auto_firewall',
            'cert_config',
        ]) || ($server->wasChanged('kernel_type') && !$server->machine_id)) {
            NodeSyncService::notifyConfigUpdated($server->id);
        }

        if ($server->wasChanged(['machine_id', 'enabled', 'kernel_type'])) {
            $this->notifyMachineChange(
                $server->machine_id,
                $server->getOriginal('machine_id')
            );
        }

        if ($server->wasChanged(self::RELAY_ENTRY_FIELDS)) {
            $this->notifyRelayEntry($server->relay_entry_id);
            $this->notifyRelayEntry($server->getOriginal('relay_entry_id'));
            $this->notifyRelayPool($server->relay_pool_id);
            $this->notifyRelayPool($server->getOriginal('relay_pool_id'));
        }
    }

    public function deleted(Server $server): void
    {
        $snapshot = clone $server;
        $server->getConnection()->afterCommit(fn () => $this->deletedAfterCommit($snapshot));
    }

    private function deletedAfterCommit(Server $server): void
    {
        \App\Services\DdnsService::notify();
        $this->notifyMachineChange(null, $server->getOriginal('machine_id') ?: $server->machine_id);
        $this->notifyRelayEntry($server->getOriginal('relay_entry_id') ?: $server->relay_entry_id);
        $this->notifyRelayPool($server->getOriginal('relay_pool_id') ?: $server->relay_pool_id);
        app(DeviceHandoverService::class)->deletedNode((int) $server->id);
    }

    /**
     * 逻辑节点新增、变更、禁用或删除后，让入口节点重新生成中转出站和路由规则。
     */
    private function notifyRelayEntry(?int $entryId): void
    {
        if ($entryId) {
            NodeSyncService::notifyFullSync((int) $entryId);
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

    private function notifyRelayPool(?int $poolId): void
    {
        if (!$poolId) return;
        foreach (Server::where('balancer_pool_id', $poolId)->where('balancer_role', 'member')->pluck('id') as $id) {
            NodeSyncService::notifyFullSync((int) $id);
        }
    }

    private function notifyMachineNodesChanged(?int $machineId): void
    {
        if ($machineId) {
            NodeSyncService::notifyMachineNodesChanged($machineId);
        }
    }
}
