<?php

namespace App\Services;

use App\Jobs\RelayNodeTrafficJob;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\ServerRoute;
use App\Models\User;
use App\Services\Plugin\HookManager;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Collection;

class ServerService
{

    /**
     * 获取所有服务器列表
     * @return Collection
     */
    public static function getAllServers(): Collection
    {
        $query = Server::orderBy('sort', 'ASC');

        return $query->get()->append([
            'last_check_at',
            'last_push_at',
            'online',
            'is_online',
            'available_status',
            'cache_key',
            'load_status',
            'metrics',
            'online_conn'
        ]);
    }

    /**
     * 获取机器下所有已启用节点
     */
    public static function getMachineNodes(ServerMachine $machine): Collection
    {
        return Server::where('machine_id', $machine->id)
            ->where('enabled', true)
            ->orderBy('sort', 'ASC')
            ->get();
    }

    /**
     * 获取指定用户可用的服务器列表
     * @param User $user
     * @return array
     */
    public static function getAvailableServers(User $user): array
    {
        $servers = Server::whereJsonContains('group_ids', (string) $user->group_id)
            ->where('show', true)
            ->where(function ($query) {
                $query->whereNull('transfer_enable')
                    ->orWhere('transfer_enable', 0)
                    ->orWhereRaw('u + d < transfer_enable');
            })
            ->orderBy('sort', 'ASC')
            ->get()
            ->append(['last_check_at', 'last_push_at', 'online', 'is_online', 'available_status', 'cache_key', 'server_key']);

        $servers = collect($servers)->map(function ($server) use ($user) {
            if ($server->isRelayChild()) {
                $entry = ServerRelayService::entryFor($server);
                // 拓扑不完整或节点被禁用时直接丢弃，绝不把落地服务器的内部连接信息下发给客户端。
                if (!$entry || $server->enabled === false) {
                    return null;
                }
                return self::projectRelayChild($server, $entry, $user);
            }

            // 判断动态端口
            if (str_contains($server->port, '-')) {
                $port = $server->port;
                $server->port = (int) Helper::randomPort($port);
                $server->ports = $port;
            } else {
                $server->port = (int) $server->port;
            }
            $server->password = $server->generateServerPassword($user);
            if ($server->type === ServerRelayService::ENTRY_TYPE && ServerRelayService::hasRelayChildren($server)) {
                // 入口节点自身也需要一个路由编号，用于在入口上显式选择直接出站。
                $server->password = Helper::applyVlessRoute(
                    $server->password,
                    ServerRelayService::ensureRouteId($server)
                );
            }
            $server->rate = $server->getCurrentRate();
            return $server;
        })->filter()->values()->toArray();

        return $servers;
    }

    /**
     * 把中转逻辑节点投影成入口节点的客户端配置。
     *
     * 客户端看到的是一个普通节点：协议、传输方式、地址、端口和传输安全参数来自入口节点，
     * 名称、排序、标签、权限和显示状态仍属于逻辑节点自身；用户身份使用原始 UUID，只在
     * 路由字节写入逻辑节点的编号。落地服务器的内部连接信息不会出现在结果中。
     */
    private static function projectRelayChild(Server $child, Server $entry, User $user): Server
    {
        $routeId = ServerRelayService::ensureRouteId($child);

        $child->type = $entry->type;
        $child->host = $entry->host;
        $child->protocol_settings = $entry->protocol_settings;
        // 服务端口属于落地服务器的内部监听端口，必须一并换成入口的值，
        // 否则用户侧节点列表仍能看到内部端口。
        $child->server_port = $entry->server_port;

        if (str_contains((string) $entry->port, '-')) {
            $child->port = (int) Helper::randomPort($entry->port);
            $child->ports = $entry->port;
        } else {
            $child->port = (int) $entry->port;
            $child->ports = null;
        }

        $child->password = Helper::applyVlessRoute($user->uuid, $routeId);
        // 逻辑节点强制继承入口的基础倍率和动态时段倍率。
        $child->rate = $entry->getCurrentRate();

        // 拓扑判定过程中加载的入口节点关联会被一并序列化，其中包含 Reality 私钥等
        // 服务端配置，必须在返回前解除。
        $child->unsetRelation('relayEntry');

        return $child;
    }

    /**
     * 根据节点权限组获取可用的用户列表
     * @param Server $node
     * @return Collection
     */
    public static function getAvailableUsers(Server $node)
    {
        // 中转逻辑节点的落地入站只接受入口服务器的内部凭据，不下发面板用户，
        // 也因此不会在落地端重复统计用户流量。
        if ($node->isRelayChild()) {
            return collect();
        }

        $groupIds = $node->group_ids ?? [];
        if (empty($groupIds)) {
            return collect();
        }
        $users = User::toBase()
            ->whereIn('group_id', $groupIds)
            ->whereRaw('u + d < transfer_enable')
            ->where(function ($query) {
                $query->where('expired_at', '>=', time())
                    ->orWhere('expired_at', NULL);
            })
            ->where('banned', 0)
            ->select([
                'id',
                'uuid',
                'speed_limit',
                'device_limit'
            ])
            ->get();
        return HookManager::filter('server.users.get', $users, $node);
    }

    // 获取路由规则
    public static function getRoutes(array $routeIds)
    {
        $routes = ServerRoute::select(['id', 'match', 'action', 'action_value'])->whereIn('id', $routeIds)->get();
        return $routes;
    }

    /**
     * 处理节点流量数据汇报
     */
    public static function processTraffic(Server $node, array $traffic): void
    {
        $data = array_filter($traffic, fn($item) =>
            is_array($item) && count($item) === 2
            && is_numeric($item[0]) && is_numeric($item[1])
        );

        if (empty($data)) {
            return;
        }

        $nodeType = strtoupper($node->type);
        Cache::put(CacheKey::get("SERVER_{$nodeType}_ONLINE_USER", $node->id), count($data), 3600);
        self::touchPush($node);

        (new UserService())->trafficFetch($node, $node->type, $data);
    }

    /** 更新节点最近一次有效流量推送时间。 */
    public static function touchPush(Server $node): void
    {
        $nodeType = strtoupper($node->type);
        Cache::put(
            CacheKey::get("SERVER_{$nodeType}_LAST_PUSH_AT", $node->id),
            time(),
            3600
        );
    }

    /**
     * 处理节点在线设备汇报
     */
    public static function processAlive(int $nodeId, array $alive): void
    {
        app(DeviceStateService::class)->replaceNodeDevices($nodeId, $alive);
        Redis::sadd('device:push_pending_nodes', $nodeId);
    }

    /**
     * 处理节点连接数汇报
     */
    public static function processOnline(Server $node, array $online): void
    {
        $cacheTime = max(300, (int) admin_setting('server_push_interval', 60) * 3);
        $nodeType = $node->type;
        $nodeId = $node->id;

        $indexKey = CacheKey::get("SERVER_{$nodeType}_ONLINE_USERS", $nodeId);
        $previousUserIds = (array) Cache::get($indexKey, []);
        $online = array_filter(
            $online,
            fn ($conn, $uid) => is_numeric($uid) && is_numeric($conn) && (int) $conn > 0,
            ARRAY_FILTER_USE_BOTH
        );
        $currentUserIds = array_map('intval', array_keys($online));

        foreach (array_diff($previousUserIds, $currentUserIds) as $uid) {
            Cache::forget(CacheKey::get("USER_ONLINE_CONN_{$nodeType}_{$nodeId}", $uid));
        }

        foreach ($online as $uid => $conn) {
            $cacheKey = CacheKey::get("USER_ONLINE_CONN_{$nodeType}_{$nodeId}", $uid);
            Cache::put($cacheKey, (int) $conn, $cacheTime);
        }

        Cache::put($indexKey, $currentUserIds, $cacheTime);
        Cache::put(
            CacheKey::get('SERVER_' . strtoupper($nodeType) . '_ONLINE_USER', $nodeId),
            count($currentUserIds),
            $cacheTime
        );
    }

    /**
     * 处理节点负载状态汇报
     */
    public static function processStatus(Server $node, array $status): void
    {
        $nodeType = strtoupper($node->type);
        $nodeId = $node->id;

        $statusData = [
            'cpu' => (float) ($status['cpu'] ?? 0),
            'mem' => [
                'total' => (int) ($status['mem']['total'] ?? 0),
                'used' => (int) ($status['mem']['used'] ?? 0),
            ],
            'swap' => [
                'total' => (int) ($status['swap']['total'] ?? 0),
                'used' => (int) ($status['swap']['used'] ?? 0),
            ],
            'disk' => [
                'total' => (int) ($status['disk']['total'] ?? 0),
                'used' => (int) ($status['disk']['used'] ?? 0),
            ],
            'updated_at' => now()->timestamp,
            'kernel_status' => $status['kernel_status'] ?? null,
        ];

        $cacheTime = max(300, (int) admin_setting('server_push_interval', 60) * 3);
        cache([
            CacheKey::get("SERVER_{$nodeType}_LOAD_STATUS", $nodeId) => $statusData,
            CacheKey::get("SERVER_{$nodeType}_LAST_LOAD_AT", $nodeId) => now()->timestamp,
        ], $cacheTime);
    }

    /**
     * 标记节点心跳
     */
    public static function touchNode(Server $node): void
    {
        Cache::put(
            CacheKey::get('SERVER_' . strtoupper($node->type) . '_LAST_CHECK_AT', $node->id),
            time(),
            3600
        );
    }

    /**
     * Update node metrics and load status
     */
    public static function updateMetrics(Server $node, array $metrics): void
    {
        $nodeType = strtoupper($node->type);
        $nodeId = $node->id;
        $cacheTime = max(300, (int) admin_setting('server_push_interval', 60) * 3);

        $metricsData = [
            'uptime' => (int) ($metrics['uptime'] ?? 0),
            'goroutines' => (int) ($metrics['goroutines'] ?? 0),
            'active_connections' => (int) ($metrics['active_connections'] ?? 0),
            'total_connections' => (int) ($metrics['total_connections'] ?? 0),
            'total_users' => (int) ($metrics['total_users'] ?? 0),
            'active_users' => (int) ($metrics['active_users'] ?? 0),
            'inbound_speed' => (int) ($metrics['inbound_speed'] ?? 0),
            'outbound_speed' => (int) ($metrics['outbound_speed'] ?? 0),
            'cpu_per_core' => $metrics['cpu_per_core'] ?? [],
            'load' => $metrics['load'] ?? [],
            'speed_limiter' => $metrics['speed_limiter'] ?? [],
            'gc' => $metrics['gc'] ?? [],
            'api' => $metrics['api'] ?? [],
            'ws' => $metrics['ws'] ?? [],
            'limits' => $metrics['limits'] ?? [],
            'updated_at' => now()->timestamp,
            'kernel_status' => (bool) ($metrics['kernel_status'] ?? false),
        ];

        Cache::put(
            CacheKey::get('SERVER_' . $nodeType . '_METRICS', $nodeId),
            $metricsData,
            $cacheTime
        );
    }

    public static function buildNodeConfig(Server $node): array
    {
        $nodeType = $node->type;
        $protocolSettings = $node->protocol_settings;
        $serverPort = $node->server_port;
        $host = $node->host;

        $baseConfig = [
            'protocol' => $nodeType,
            // 节点内核独立于协议；历史节点的空值按默认 Xray 下发。
            'kernel_type' => Server::effectiveKernelType($node->kernel_type),
            'listen_ip' => '0.0.0.0',
            'server_port' => (int) $serverPort,
            'network' => data_get($protocolSettings, 'network'),
            'networkSettings' => data_get($protocolSettings, 'network_settings') ?: null,
        ];

        $response = match ($nodeType) {
            'shadowsocks' => [
                ...$baseConfig,
                'cipher' => $protocolSettings['cipher'],
                'plugin' => $protocolSettings['plugin'],
                'plugin_opts' => $protocolSettings['plugin_opts'],
                'server_key' => match ($protocolSettings['cipher']) {
                        '2022-blake3-aes-128-gcm' => Helper::getServerKey($node->created_at, 16),
                        '2022-blake3-aes-256-gcm' => Helper::getServerKey($node->created_at, 32),
                        default => null,
                    },
            ],
            'vmess' => [
                ...$baseConfig,
                'tls' => (int) $protocolSettings['tls'],
                'tls_settings' => $protocolSettings['tls_settings'],
                'multiplex' => data_get($protocolSettings, 'multiplex'),
            ],
            'trojan' => [
                ...$baseConfig,
                'host' => $host,
                'server_name' => data_get($protocolSettings, 'tls_settings.server_name'),
                'multiplex' => data_get($protocolSettings, 'multiplex'),
                'tls' => (int) $protocolSettings['tls'],
                'tls_settings' => match ((int) $protocolSettings['tls']) {
                        2 => $protocolSettings['reality_settings'],
                        default => $protocolSettings['tls_settings'],
                    },
            ],
            'vless' => [
                ...$baseConfig,
                'tls' => (int) $protocolSettings['tls'],
                'flow' => $protocolSettings['flow'],
                'decryption' => match (data_get($protocolSettings, 'encryption.enabled')) {
                    true => data_get($protocolSettings, 'encryption.decryption'),
                    default => null,
                },
                'tls_settings' => match ((int) $protocolSettings['tls']) {
                        2 => $protocolSettings['reality_settings'],
                        default => $protocolSettings['tls_settings'],
                    },
                'multiplex' => data_get($protocolSettings, 'multiplex'),
            ],
            'hysteria' => [
                ...$baseConfig,
                'server_port' => (int) $serverPort,
                'version' => (int) $protocolSettings['version'],
                'host' => $host,
                'server_name' => $protocolSettings['tls']['server_name'],
                'tls_settings' => $protocolSettings['tls'],
                'up_mbps' => (int) $protocolSettings['bandwidth']['up'],
                'down_mbps' => (int) $protocolSettings['bandwidth']['down'],
                ...match ((int) $protocolSettings['version']) {
                        1 => ['obfs' => $protocolSettings['obfs']['password'] ?? null],
                        2 => [
                            'obfs' => $protocolSettings['obfs']['open'] ? $protocolSettings['obfs']['type'] : null,
                            'obfs-password' => $protocolSettings['obfs']['password'] ?? null,
                        ],
                        default => [],
                    },
            ],
            'tuic' => [
                ...$baseConfig,
                'version' => (int) $protocolSettings['version'],
                'server_port' => (int) $serverPort,
                'server_name' => $protocolSettings['tls']['server_name'],
                'congestion_control' => $protocolSettings['congestion_control'],
                'tls_settings' => $protocolSettings['tls'],
                'auth_timeout' => '3s',
                'zero_rtt_handshake' => false,
                'heartbeat' => '3s',
            ],
            'anytls' => [
                ...$baseConfig,
                'server_port' => (int) $serverPort,
                'server_name' => $protocolSettings['tls']['server_name'],
                'tls_settings' => $protocolSettings['tls'],
                'padding_scheme' => $protocolSettings['padding_scheme'],
            ],
            'socks' => [
                ...$baseConfig,
                'server_port' => (int) $serverPort,
                'tls' => (int) data_get($protocolSettings, 'tls', 0),
                'tls_settings' => data_get($protocolSettings, 'tls_settings'),
            ],
            'naive' => [
                ...$baseConfig,
                'server_port' => (int) $serverPort,
                'tls' => (int) $protocolSettings['tls'],
                'tls_settings' => $protocolSettings['tls_settings'],
            ],
            'http' => [
                ...$baseConfig,
                'server_port' => (int) $serverPort,
                'tls' => (int) $protocolSettings['tls'],
                'tls_settings' => $protocolSettings['tls_settings'],
            ],
            'mieru' => [
                ...$baseConfig,
                'server_port' => (int) $serverPort,
                'transport' => data_get($protocolSettings, 'transport', 'TCP'),
                'traffic_pattern' => $protocolSettings['traffic_pattern'],
            ],
            default => [],
        };

        if ($relay = self::buildRelayConfig($node)) {
            $response['relay'] = $relay;
        }

        if (!empty($node['route_ids'])) {
            $response['routes'] = self::getRoutes($node['route_ids']);
        }

        if (!empty($node['custom_outbounds'])) {
            $response['custom_outbounds'] = $node['custom_outbounds'];
        }

        if (!empty($node['custom_routes'])) {
            $response['custom_routes'] = $node['custom_routes'];
        }

        if (!empty($node['cert_config'])) {
            $certConfig = $node['cert_config'];
            // Normalize: accept both "mode" and "cert_mode" from the database
            if (isset($certConfig['mode']) && !isset($certConfig['cert_mode'])) {
                $certConfig['cert_mode'] = $certConfig['mode'];
                unset($certConfig['mode']);
            }
            if (data_get($certConfig, 'cert_mode') !== 'none') {
                $response['cert_config'] = $certConfig;
            }
        }

        return $response;
    }

    /**
     * 生成节点的中转配置段。
     *
     * 入口节点得到全部逻辑节点的内部出站参数和路由编号映射；落地节点只得到自己那条
     * 内部入站的监听参数。普通节点返回 null，配置结构保持不变。
     */
    private static function buildRelayConfig(Server $node): ?array
    {
        if ($node->isRelayChild()) {
            $relay = [
                'mode' => 'landing',
                'protocol' => $node->type,
                'listen_port' => (int) $node->server_port,
                'entry_node_id' => (int) $node->relayEntryId(),
            ];

            if ($node->type === Server::TYPE_SHADOWSOCKS) {
                $credential = ServerRelayService::transitCredential($node);
                $relay['cipher'] = $credential['cipher'];
                $relay['password'] = $credential['password'];
            } else {
                $credential = ServerRelayService::vlessTransitCredential($node);
                $relay['vless'] = [
                    'id' => $credential['id'],
                    'transport_auth' => ServerRelayService::normalizeVlessNetwork(
                        data_get($node->protocol_settings, 'network')
                    ) === 'hysteria' ? $credential['transport_auth'] : null,
                ];
            }

            return $relay;
        }

        if ($node->relayEntryId() !== null || $node->type !== ServerRelayService::ENTRY_TYPE) {
            return null;
        }

        $children = ServerRelayService::childrenOf($node);
        if ($children->isEmpty()) {
            return null;
        }

        $outbounds = $children->map(function (Server $child) {
            $outbound = [
                'node_id' => (int) $child->id,
                'tag' => ServerRelayService::outboundTag((int) $child->id),
                'route_id' => ServerRelayService::ensureRouteId($child),
                'protocol' => $child->type,
                'address' => $child->host,
                'port' => (int) $child->port,
            ];

            if ($child->type === Server::TYPE_SHADOWSOCKS) {
                $credential = ServerRelayService::transitCredential($child);
                $outbound['cipher'] = $credential['cipher'];
                $outbound['password'] = $credential['password'];
            } else {
                $outbound['vless'] = ServerRelayService::vlessClientConfig($child);
            }

            return $outbound;
        })->values()->all();

        return [
            'mode' => 'entry',
            'route_id' => ServerRelayService::ensureRouteId($node),
            'children' => $outbounds,
        ];
    }

    /**
     * 记录中转逻辑节点的落地线路流量。
     *
     * 该流量来自入口服务器上对应的独立内部出站，只作为节点运营统计，
     * 不参与用户套餐扣费，也不叠加倍率。
     *
     * @param array<string|int, mixed> $relayTraffic 出站标签或逻辑节点 ID => [上行, 下行]
     */
    public static function processRelayTraffic(Server $entry, array $relayTraffic): void
    {
        foreach (self::normalizeRelayTraffic($entry, $relayTraffic) as $item) {
            RelayNodeTrafficJob::dispatch(
                $item['server_id'],
                $item['server_type'],
                $item['u'],
                $item['d']
            );
        }
    }

    /**
     * @return array<int, array{server_id: int, server_type: string, u: int, d: int}>
     */
    public static function normalizeRelayTraffic(Server $entry, array $relayTraffic): array
    {
        $normalized = [];
        foreach ($relayTraffic as $key => $value) {
            // 数据来自 Node 上报的 JSON，形状不可信，逐项校验后才使用。
            if (!is_array($value) || count($value) !== 2) {
                continue;
            }
            if (!isset($value[0], $value[1]) || !is_numeric($value[0]) || !is_numeric($value[1])) {
                continue;
            }

            $nodeId = is_numeric($key)
                ? (int) $key
                : ServerRelayService::nodeIdFromOutboundTag((string) $key);
            if (!$nodeId) {
                continue;
            }

            $u = (int) $value[0];
            $d = (int) $value[1];
            if ($u <= 0 && $d <= 0) {
                continue;
            }

            $child = Server::find($nodeId);
            if (!$child || (int) $child->relayEntryId() !== (int) $entry->id) {
                continue;
            }

            $normalized[] = [
                'server_id' => (int) $child->id,
                'server_type' => (string) $child->type,
                'u' => $u,
                'd' => $d,
            ];
        }

        return $normalized;
    }

    /**
     * 归一化入口按用户拆分的落地流量，只接受当前入口的逻辑子节点。
     *
     * @param array<string|int, mixed> $relayUserTraffic 用户 ID => 节点 ID => [上行, 下行]
     * @return array<int, array<int, array{0: int, 1: int}>>
     */
    public static function normalizeRelayUserTraffic(Server $entry, array $relayUserTraffic): array
    {
        $normalized = [];
        foreach ($relayUserTraffic as $userKey => $nodes) {
            $userId = filter_var($userKey, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($userId === false || !is_array($nodes)) {
                continue;
            }

            $userId = (int) $userId;

            foreach ($nodes as $nodeKey => $value) {
                $nodeId = filter_var($nodeKey, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($nodeId === false || !is_array($value) || count($value) !== 2
                    || !isset($value[0], $value[1])
                    || !is_numeric($value[0]) || !is_numeric($value[1])) {
                    continue;
                }

                $nodeId = (int) $nodeId;

                $u = max(0, (int) $value[0]);
                $d = max(0, (int) $value[1]);
                if ($u === 0 && $d === 0) {
                    continue;
                }

                $child = Server::find($nodeId);
                if (!$child || (int) $child->relayEntryId() !== (int) $entry->id) {
                    continue;
                }

                $normalized[$userId][$nodeId] = [$u, $d];
            }
        }

        return $normalized;
    }

    /**
     * 根据协议类型和标识获取服务器
     * @param int $serverId
     * @param string $serverType
     * @return Server|null
     */
    public static function getServer($serverId, ?string $serverType = null): Server | null
    {
        return Server::query()
            ->when($serverType, function ($query) use ($serverType) {
                $query->where('type', Server::normalizeType($serverType));
            })
            ->where(function ($query) use ($serverId) {
                $query->where('code', $serverId)
                    ->orWhere('id', $serverId);
            })
            ->orderByRaw('CASE WHEN code = ? THEN 0 ELSE 1 END', [$serverId])
            ->first();
    }
}
