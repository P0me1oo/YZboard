<?php

namespace App\WebSocket;

use App\Models\Server;
use App\Services\DeviceStateService;
use App\Services\NodeRegistry;
use App\Services\ServerService;
use App\Services\NodeControlStateService;
use App\Services\NodeReportService;
use App\Services\NodeStateService;
use App\Services\NodeRuntimeMetadata;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Workerman\Connection\TcpConnection;

class NodeEventHandlers
{
    /**
     * Handle pong heartbeat
     */
    public static function handlePong(TcpConnection $conn, int $nodeId, array $data = []): void
    {
        Cache::put("node_ws_alive:{$nodeId}", true, 86400);
    }

    /**
     * Handle node status update
     */
    public static function handleNodeStatus(TcpConnection $conn, int $nodeId, array $data): void
    {
        if (!empty($conn->realtime)) return;
        $node = Server::find($nodeId);
        if (!$node) return;

        $nodeType = strtoupper($node->type);
        Cache::put(\App\Utils\CacheKey::get('SERVER_' . $nodeType . '_LAST_CHECK_AT', $nodeId), time(), 3600);
        ServerService::updateMetrics($node, $data);

        Log::debug("[WS] Node#{$nodeId} status updated");
    }

    /**
     * Handle device report from node
     * 
     * 数据格式: {"event": "report.devices", "data": {userId: [ip1, ip2, ...], ...}}
     */
    public static function handleDeviceReport(TcpConnection $conn, int $nodeId, array $data): void
    {
        if (!empty($conn->realtime)) return;
        $service = app(DeviceStateService::class);

        if (isset($data['devices']) && is_array($data['devices'])) {
            $data = $data['devices'];
        }

        $oldDevices = $service->getNodeDevices($nodeId);
        ServerService::processAlive($nodeId, $data);

        // Mark for push
        Redis::sadd('device:push_pending_nodes', $nodeId);

        Log::debug("[WS] Node#{$nodeId} synced " . count($data) . " users, removed " . count(array_diff_key($oldDevices, $data)));
    }

    /**
     * Handle device state request from node
     */
    public static function handleDeviceRequest(TcpConnection $conn, int $nodeId, array $data = []): void
    {
        $node = Server::find($nodeId);
        if (!$node) return;

        if (!empty($conn->realtime)) {
            NodeRegistry::sendDevices($nodeId, app(NodeControlStateService::class)->devices($node), true);
            return;
        }

        $users = ServerService::getAvailableUsers($node);
        $userIds = $users->pluck('id')->toArray();

        $service = app(DeviceStateService::class);
        $devices = $service->getUsersDevices($userIds);

        NodeRegistry::send($nodeId, 'sync.devices', [
            'users' => $devices,
        ]);

        Log::debug("[WS] Node#{$nodeId} requested devices, sent " . count($devices) . " users");
    }

    /**
     * Push device state to node
     */
    public static function pushDeviceStateToNode(int $nodeId, DeviceStateService $service): void
    {
        $node = app(NodeRuntimeMetadata::class)->nodeForDevices($nodeId);
        if (!$node) return;
        if (!empty(NodeRegistry::get($nodeId)?->realtime)) {
            if (!NodeRegistry::sendDevices($nodeId, app(NodeControlStateService::class)->devices($node))) {
                throw new \RuntimeException('设备快照发送失败');
            }
            return;
        }

        $users = ServerService::getAvailableUsers($node);
        $userIds = $users->pluck('id')->toArray();
        $devices = $service->getUsersDevices($userIds);

        NodeRegistry::send($nodeId, 'sync.devices', [
            'users' => $devices
        ]);

        Log::debug("[WS] Pushed device state to node#{$nodeId}: " . count($devices) . " users");
    }

    public static function handleRuntimeState(TcpConnection $conn, int $nodeId, array $data): void
    {
        if (empty($conn->realtime)) return;
        $node = app(NodeRuntimeMetadata::class)->nodeOrFail($nodeId);
        $receipt = app(NodeStateService::class)->accept($node, $data);
        $conn->send(json_encode(['event' => 'state.ack', 'data' => ['node_id' => $nodeId, 'request_id' => $data['request_id'] ?? null] + $receipt]));
    }

    public static function handleTrafficReport(TcpConnection $conn, int $nodeId, array $data): void
    {
        if (empty($conn->realtime)) return;
        $receipt = app(NodeReportService::class)->acceptRealtime(Server::findOrFail($nodeId), $data);
        $conn->send(json_encode(['event' => 'traffic.ack', 'data' => ['node_id' => $nodeId, 'request_id' => $data['request_id'] ?? null] + $receipt]));
    }

    public static function handleSyncRequest(TcpConnection $conn, int $nodeId, array $data = []): void
    {
        $node = Server::find($nodeId);
        if ($node) self::pushFullSync($conn, $node);
    }

    /**
     * Push full config + users to newly connected node
     */
    public static function pushFullSync(TcpConnection $conn, Server $node): void
    {
        $nodeId = (int) $node->id;
        if (!empty($conn->realtime)) {
            $control = app(NodeControlStateService::class);
            NodeRegistry::send($nodeId, 'sync.snapshot', $control->snapshot($node));
            NodeRegistry::sendDevices($nodeId, $control->devices($node), true);
            return;
        }

        // Push config
        $config = ServerService::buildNodeConfig($node);
        NodeRegistry::send($nodeId, 'sync.config', [
            'config' => $config,
        ]);

        // Push users
        $users = ServerService::getAvailableUsers($node)->toArray();
        NodeRegistry::send($nodeId, 'sync.users', [
            'users' => $users,
        ]);

        Log::info("[WS] Full sync pushed to node#{$nodeId}", [
            'users' => count($users),
        ]);
    }
}
