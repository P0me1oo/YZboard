<?php

namespace App\WebSocket;

use App\Models\Server;
use App\Models\ServerMachine;
use App\Services\DeviceStateService;
use App\Services\NodeRegistry;
use App\Services\NodeRuntimeMetadata;
use App\Services\ServerService;
use App\Support\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Workerman\Connection\TcpConnection;
use Workerman\Timer;
use Workerman\Worker;

class NodeWorker
{
    private const AUTH_TIMEOUT = 10;
    private const PING_INTERVAL = 5;
    private const IDLE_TIMEOUT = 20;

    public const HEARTBEAT_CACHE_KEY = 'ws_server:heartbeat';
    private const HEARTBEAT_INTERVAL = 10;
    private const HEARTBEAT_TTL = 30;

    private Worker $worker;
    private ?AdminRealtimeWorker $admin = null;

    private array $handlers = [
        'pong' => [NodeEventHandlers::class, 'handlePong'],
        'node.status' => [NodeEventHandlers::class, 'handleNodeStatus'],
        'report.devices' => [NodeEventHandlers::class, 'handleDeviceReport'],
        'request.devices' => [NodeEventHandlers::class, 'handleDeviceRequest'],
        'runtime.state' => [NodeEventHandlers::class, 'handleRuntimeState'],
        'report.traffic' => [NodeEventHandlers::class, 'handleTrafficReport'],
        'request.sync' => [NodeEventHandlers::class, 'handleSyncRequest'],
    ];

    public function __construct(string $host, int $port)
    {
        $this->worker = new Worker("websocket://{$host}:{$port}");
        $this->worker->count = 1;
        $this->worker->name = 'xboard-ws-server';
        $this->admin = app(AdminRealtimeWorker::class);
    }

    public function run(): void
    {
        $this->setupLogging();
        $this->setupCallbacks();
        Worker::runAll();
    }

    private function setupLogging(): void
    {
        $logPath = storage_path('logs');
        if (!is_dir($logPath)) {
            mkdir($logPath, 0777, true);
        }
        Worker::$logFile = $logPath . '/xboard-ws-server.log';
        Worker::$pidFile = $logPath . '/xboard-ws-server.pid';
    }

    private function setupCallbacks(): void
    {
        $this->worker->onWorkerStart = [$this, 'onWorkerStart'];
        $this->worker->onConnect = [$this, 'onConnect'];
        $this->worker->onWebSocketConnect = [$this, 'onWebSocketConnect'];
        $this->worker->onMessage = [$this, 'onMessage'];
        $this->worker->onClose = [$this, 'onClose'];
    }

    public function onWorkerStart(Worker $worker): void
    {
        Log::info("[WS] Worker started, pid={$worker->id}");
        $this->subscribeRedis();
        $this->setupTimers();
    }

    /**
     * 常驻进程不会像请求和队列任务那样重置系统设置实例，需要主动丢弃，
     * 否则管理员修改的设置要等进程重启才会生效。
     */
    public static function refreshSettings(): void
    {
        app()->forgetInstance(Setting::class);
    }

    private function setupTimers(): void
    {
        Timer::add(0.1, function () { $this->admin?->tick(); });
        Cache::put(self::HEARTBEAT_CACHE_KEY, time(), self::HEARTBEAT_TTL);
        Timer::add(self::HEARTBEAT_INTERVAL, function () {
            Cache::put(self::HEARTBEAT_CACHE_KEY, time(), self::HEARTBEAT_TTL);
        });

        Timer::add(self::PING_INTERVAL, function () {
            $seen = [];

            foreach (NodeRegistry::getConnectedNodeIds() as $nodeId) {
                $conn = NodeRegistry::get($nodeId);
                if ($conn) {
                    $oid = spl_object_id($conn);
                    if (!isset($seen[$oid])) {
                        $seen[$oid] = true;
                        $conn->send(json_encode(['event' => 'ping']));
                    }
                }
            }

            foreach (NodeRegistry::getConnectedMachineIds() as $machineId) {
                $conn = NodeRegistry::getMachine($machineId);
                if ($conn) {
                    $oid = spl_object_id($conn);
                    if (!isset($seen[$oid])) {
                        $seen[$oid] = true;
                        $conn->send(json_encode(['event' => 'ping']));
                    }
                }
            }
            foreach ($this->worker->connections as $connection) {
                if (!empty($connection->realtime) && time() - ($connection->lastMessageAt ?? time()) > self::IDLE_TIMEOUT) {
                    $connection->close();
                }
            }
        });

        Timer::add(1, function () {
            self::refreshSettings();
            $pendingNodeIds = Redis::spop('device:push_pending_nodes', 100);
            if (empty($pendingNodeIds)) {
                return;
            }
            if (in_array(0, array_map('intval', $pendingNodeIds), true)) {
                $pendingNodeIds = array_unique(array_merge($pendingNodeIds, NodeRegistry::getConnectedNodeIds()));
            }

            $service = app(DeviceStateService::class);
            foreach ($pendingNodeIds as $nodeId) {
                $nodeId = (int) $nodeId;
                if (NodeRegistry::get($nodeId) !== null) {
                    NodeEventHandlers::pushDeviceStateToNode($nodeId, $service);
                }
            }
        });
    }

    public function onConnect(TcpConnection $conn): void
    {
        $conn->authTimer = Timer::add(self::AUTH_TIMEOUT, function () use ($conn) {
            if (empty($conn->nodeId) && empty($conn->machineNodeIds)) {
                $conn->close(json_encode([
                    'event' => 'error',
                    'data' => ['message' => 'auth timeout'],
                ]));
            }
        }, [], false);
    }

    public function onWebSocketConnect(TcpConnection $conn, $httpMessage): void
    {
        $queryString = '';
        if (is_string($httpMessage)) {
            $queryString = parse_url($httpMessage, PHP_URL_QUERY) ?? '';
        } elseif ($httpMessage instanceof \Workerman\Protocols\Http\Request) {
            $queryString = $httpMessage->queryString();
        }

        parse_str($queryString, $params);
        $conn->realtime = ($params['realtime'] ?? '') === '1';
        $conn->lastMessageAt = time();
        if (($params['client'] ?? '') === 'admin') {
            $conn->adminPending = true;
            $conn->realtime = false;
            $conn->maxSendBufferSize = 2 * 1024 * 1024;
            $conn->onBufferFull = function ($connection) { $connection->close(); };
            return;
        }

        if (isset($conn->authTimer)) {
            Timer::del($conn->authTimer);
        }

        // 认证和首次同步都使用最新的通讯密钥与节点配置设置。
        self::refreshSettings();

        // 判断认证模式
        if (!empty($params['machine_id'])) {
            $this->authenticateMachine($conn, $params);
        } else {
            $this->authenticateNode($conn, $params);
        }
    }

    /**
     * 旧模式：单节点认证
     */
    private function authenticateNode(TcpConnection $conn, array $params): void
    {
        $token = $params['token'] ?? '';
        $nodeId = (int) ($params['node_id'] ?? 0);

        $serverToken = admin_setting('server_token', '');
        if ($token === '' || $serverToken === '' || !hash_equals($serverToken, $token)) {
            $conn->close(json_encode([
                'event' => 'error',
                'data' => ['message' => 'invalid token'],
            ]));
            return;
        }

        $node = ServerService::getServer($nodeId, null);
        if (!$node) {
            $conn->close(json_encode([
                'event' => 'error',
                'data' => ['message' => 'node not found'],
            ]));
            return;
        }

        $conn->nodeId = $nodeId;
        NodeRegistry::add($nodeId, $conn);
        Cache::put("node_ws_alive:{$nodeId}", true, 86400);

        // 通道连接变化不代表代理连接变化，保留最后快照直至新快照或过期。

        Log::debug("[WS] Node#{$nodeId} connected", [
            'remote' => $conn->getRemoteIp(),
            'total' => NodeRegistry::count(),
        ]);

        $conn->send(json_encode([
            'event' => 'auth.success',
            'data' => ['node_id' => $nodeId],
        ]));

        NodeEventHandlers::pushFullSync($conn, $node);
        if ($conn->realtime) {
            $conn->send(json_encode(['event' => 'sync.ready']));
        }
    }

    /**
     * 新模式：机器认证，自动注册该机器下所有已启用节点
     */
    private function authenticateMachine(TcpConnection $conn, array $params): void
    {
        $machineId = (int) ($params['machine_id'] ?? 0);
        $token = $params['token'] ?? '';

        $machine = ServerMachine::where('id', $machineId)
            ->where('token', $token)
            ->first();

        if (!$machine || !$machine->is_active) {
            $conn->close(json_encode([
                'event' => 'error',
                'data' => ['message' => 'invalid machine credentials'],
            ]));
            return;
        }

        $nodes = ServerService::getMachineNodes($machine);

        $machine->forceFill(['last_seen_at' => now()->timestamp])->saveQuietly();
        NodeRegistry::addMachine($machineId, $conn);

        // 把同一个连接注册到该机器下所有节点
        $nodeIds = [];
        foreach ($nodes as $node) {
            NodeRegistry::add($node->id, $conn);
            Cache::put("node_ws_alive:{$node->id}", true, 86400);
            $nodeIds[] = $node->id;
        }

        // 连接上记录所属机器和节点列表
        $conn->machineId = $machineId;
        $conn->machineNodeIds = $nodeIds;

        Log::debug("[WS] Machine#{$machineId} connected, nodes: " . implode(',', $nodeIds), [
            'remote' => $conn->getRemoteIp(),
            'total' => NodeRegistry::count(),
            'machines' => NodeRegistry::machineCount(),
        ]);

        $conn->send(json_encode([
            'event' => 'auth.success',
            'data' => [
                'machine_id' => $machineId,
                'node_ids' => $nodeIds,
            ],
        ]));

        // 为每个节点推送完整同步
        foreach ($nodes as $node) {
            NodeEventHandlers::pushFullSync($conn, $node);
        }
        if ($conn->realtime) {
            $conn->send(json_encode(['event' => 'sync.ready']));
        }
    }

    public function onMessage(TcpConnection $conn, $data): void
    {
        $msg = json_decode($data, true);
        if (!is_array($msg) || !is_string($msg['event'] ?? null)) return;
        if (!empty($conn->adminPending) || !empty($conn->adminAuthenticated)) {
            try {
                $this->admin?->handle($conn, $msg);
            } catch (\Throwable) {
                $conn->close(json_encode(['event' => 'state.error']));
            }
            return;
        }
        $event = $msg['event'];
        $body = $msg['data'] ?? [];
        if (!is_array($body)) return;
        $machineState = $event === 'machine.state' && !empty($conn->machineId) && !empty($conn->realtime);
        $conn->lastMessageAt = time();

        if (!empty($conn->machineId)) {
            if (NodeRegistry::getMachine((int) $conn->machineId) !== $conn) return;
            if ($event === 'pong') {
                foreach ($conn->machineNodeIds ?? [] as $nid) {
                    Cache::put("node_ws_alive:{$nid}", true, 86400);
                }
                return;
            }
            $nodeId = $machineState ? 0 : (int) ($body['node_id'] ?? 0);
            if (!$machineState && ($nodeId <= 0 || !in_array($nodeId, $conn->machineNodeIds ?? [], true))) return;
        } else {
            $nodeId = (int) ($conn->nodeId ?? 0);
        }
        if (!$machineState && (!$nodeId || NodeRegistry::get($nodeId) !== $conn || !isset($this->handlers[$event]))) return;
        try {
            if ($machineState) {
                $receipt = app(\App\Services\MachineStateService::class)->accept(app(NodeRuntimeMetadata::class)->machineOrFail((int) $conn->machineId), $body);
                $conn->send(json_encode(['event' => 'state.ack', 'data' => ['node_id' => 0, 'request_id' => $body['request_id'] ?? null] + $receipt]));
                $this->admin?->changed();
                return;
            }
            ($this->handlers[$event])($conn, $nodeId, $body);
            if (in_array($event, ['runtime.state', 'report.traffic'], true)) $this->admin?->changed();
        } catch (\Throwable $exception) {
            $code = $exception instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
                ? $exception->getStatusCode()
                : ($exception instanceof \Illuminate\Validation\ValidationException ? 422 : 500);
            $errorEvent = match ($event) {
                'runtime.state', 'machine.state' => 'state.error',
                'report.traffic' => 'traffic.error',
                default => 'error',
            };
            $conn->send(json_encode(['event' => $errorEvent, 'data' => [
                'node_id' => $nodeId,
                'request_id' => $body['request_id'] ?? null,
                'report_id' => is_string($body['report_id'] ?? null) ? $body['report_id'] : null,
                'sequence' => $body['sequence'] ?? null,
                'code' => $code,
            ]]));
            Log::warning('[WS] Node request failed', ['node_id' => $nodeId, 'event' => $event, 'code' => $code]);
        }
    }

    public function onClose(TcpConnection $conn): void
    {
        $this->admin?->close($conn);
        // 只移除通道登记。代理连接仍可能存在，设备状态由新快照或有效期管理。
        if (!empty($conn->machineId)) {
            foreach ($conn->machineNodeIds ?? [] as $nodeId) {
                if (NodeRegistry::get($nodeId) !== $conn) continue;
                NodeRegistry::remove($nodeId, $conn);
                Cache::forget("node_ws_alive:{$nodeId}");
            }
            NodeRegistry::removeMachine((int) $conn->machineId, $conn);
            return;
        }
        if (!empty($conn->nodeId) && NodeRegistry::get($conn->nodeId) === $conn) {
            NodeRegistry::remove($conn->nodeId, $conn);
            Cache::forget("node_ws_alive:{$conn->nodeId}");
        }
    }

    private function subscribeRedis(): void
    {
        $host = config('database.redis.default.host', '127.0.0.1');
        $port = config('database.redis.default.port', 6379);

        if (str_starts_with($host, '/')) {
            $redisUri = "unix://{$host}";
        } else {
            $redisUri = "redis://{$host}:{$port}";
        }

        $redis = new \Workerman\Redis\Client($redisUri);

        $password = config('database.redis.default.password');
        if ($password) {
            $redis->auth($password);
        }

        $prefix = config('database.redis.options.prefix', '');
        $channel = $prefix . 'node:push';
        $stateChannel = $prefix . 'realtime:changed';

        $redis->subscribe([$channel, $stateChannel], function ($chan, $message) use ($stateChannel) {
            if ($chan === $stateChannel) {
                $this->admin?->changed();
                return;
            }
            $payload = json_decode($message, true);
            if (!is_array($payload)) {
                return;
            }

            $event = $payload['event'] ?? '';
            $data = $payload['data'] ?? [];

            // Machine-level events (e.g., sync.nodes)
            $machineId = $payload['machine_id'] ?? null;
            if ($machineId && $event) {
                // Update server-side registry when node membership changes
                if ($event === 'sync.nodes') {
                    $nodeIds = array_map('intval', array_column($data['nodes'] ?? [], 'id'));
                    NodeRegistry::refreshMachineNodes((int) $machineId, $nodeIds);
                }

                $sent = NodeRegistry::sendMachine((int) $machineId, $event, $data);
                if ($sent) {
                    Log::debug("[WS] Pushed {$event} to machine#{$machineId}");
                }
                return;
            }

            // Per-node events
            $nodeId = $payload['node_id'] ?? null;
            if (!$nodeId || !$event) {
                return;
            }

            $connection = NodeRegistry::get((int) $nodeId);
            if ($connection && !empty($connection->realtime) && in_array($event, ['sync.config', 'sync.users', 'sync.user.delta'], true)) {
                $node = Server::find((int) $nodeId);
                if ($node) NodeEventHandlers::pushFullSync($connection, $node);
                return;
            }
            $sent = NodeRegistry::send((int) $nodeId, $event, $data);
            if ($sent) {
                Log::debug("[WS] Pushed {$event} to node#{$nodeId}");
            }
        });

        Log::info("[WS] Subscribed to Redis channel: {$channel}");
    }
}
