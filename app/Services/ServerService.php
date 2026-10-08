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

    private static function normalizedPorts(Server $server): string
    {
        $ports = (string) $server->port;
        if ($server->type === Server::TYPE_HYSTERIA && (int) data_get($server->protocol_settings, 'version', 2) === 2) {
            return \App\Utils\PortSet::normalize($ports);
        }
        return $ports;
    }

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
        $groupIds = $user->effectiveGroupIds();
        if ($groupIds === []) {
            return [];
        }
        $servers = Server::where(function ($query) use ($groupIds) {
                foreach ($groupIds as $groupId) {
                    $query->orWhereJsonContains('group_ids', (string) $groupId)
                        ->orWhereJsonContains('group_ids', (int) $groupId);
                }
            })
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
            if ($server->type === Server::TYPE_WIREGUARD && !$server->isRelayChild()) {
                if ($server->enabled === false || $user->banned
                    || ($user->expired_at !== null && $user->expired_at <= time())
                    || $user->u + $user->d >= $user->transfer_enable) {
                    return null;
                }
                $server->wireguard = WireGuardService::client($server, $user);
            }
            if ($server->isRelayChild()) {
                $entry = ServerRelayService::entryFor($server);
                // 拓扑不完整或节点被禁用时直接丢弃，绝不把落地服务器的内部连接信息下发给客户端。
                if (!$entry || $server->enabled === false) {
                    return null;
                }
                return self::projectRelayChild($server, $entry, $user);
            }

            // 判断动态端口
            if (\App\Utils\PortSet::isMultiple((string) $server->port)) {
                $port = self::normalizedPorts($server);
                $server->port = (int) Helper::randomPort($port);
                $server->ports = $port;
            } else {
                $server->port = (int) $server->port;
            }
            $server->password = $server->generateServerPassword($user);
            if (ServerRelayService::hasRelayChildren($server)) {
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
        $child->kernel_type = $entry->kernel_type;
        $child->protocol_settings = $entry->protocol_settings;
        // 服务端口属于落地服务器的内部监听端口，必须一并换成入口的值，
        // 否则用户侧节点列表仍能看到内部端口。
        $child->server_port = $entry->server_port;

        if (\App\Utils\PortSet::isMultiple((string) $entry->port)) {
            $ports = self::normalizedPorts($entry);
            $child->port = (int) Helper::randomPort($ports);
            $child->ports = $ports;
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
    public static function getAvailableUsers(Server $node, ?int $userId = null)
    {
        // 插件可能依赖完整名单；普通单用户准入直接查询一行，避免每次换网加载整节点用户。
        if ($userId !== null && isset(HookManager::getFilters()['server.users.get'])) {
            return self::getAvailableUsers($node)->filter(fn ($user) => (int) $user->id === $userId)->values();
        }
        // 中转逻辑节点的落地入站只接受入口服务器的内部凭据，不下发面板用户，
        // 也因此不会在落地端重复统计用户流量。
        if ($node->isRelayChild()) {
            return collect();
        }

        $routeGroups = self::relayRouteGroups($node);
        $groupIds = $routeGroups === null ? ($node->group_ids ?? [])
            : array_values(array_unique(array_merge(...array_values($routeGroups))));
        if (empty($groupIds)) {
            return collect();
        }
        $fields = ['id', 'uuid', 'speed_limit', 'device_limit', 'conn_limit', 'conn_rate_limit'];
        if ($node->type === Server::TYPE_WIREGUARD) {
            $fields[] = 'expired_at';
        }
        $users = User::toBase()
            ->when($userId !== null, fn ($query) => $query->where('id', $userId))
            ->where(function ($query) use ($groupIds) {
                $query->whereIn('group_id', $groupIds);
                foreach ($groupIds as $groupId) {
                    $query->orWhereJsonContains('group_ids', (int) $groupId);
                }
            })
            ->whereRaw('u + d < transfer_enable')
            ->where(function ($query) {
                $query->where('expired_at', '>=', time())
                    ->orWhere('expired_at', NULL);
            })
            ->where('banned', 0)
            ->select($routeGroups === null ? $fields : [...$fields, 'group_id', 'group_ids'])
            ->orderBy('id')
            ->get();
        if ($routeGroups !== null) {
            $users = $users->filter(function ($user) use ($routeGroups) {
                $groups = $user->group_ids === null ? [$user->group_id] : json_decode($user->group_ids, true);
                $user->relay_routes = [];
                foreach ($routeGroups as $route => $allowedGroups) {
                    if (array_intersect($groups ?? [], $allowedGroups) !== []) {
                        $user->relay_routes[] = (int) $route;
                    }
                }
                sort($user->relay_routes, SORT_NUMERIC);
                unset($user->group_id, $user->group_ids);
                return $user->relay_routes !== [];
            })->values();
        }
        if ($node->type === Server::TYPE_WIREGUARD) {
            foreach ($users as $user) {
                $user->wireguard = WireGuardService::peer($node, $user);
                unset($user->expired_at);
            }
        }
        return HookManager::filter('server.users.get', $users, $node);
    }

    /** 入口认证接收各线路用户的并集，每个用户只获得与自身权限组相交的线路。 */
    private static function relayRouteGroups(Server $node): ?array
    {
        $children = ServerRelayService::childrenOf($node);
        if ($children->isEmpty() && ServerRelayService::blockedWireGuardRoutes($node) === []) {
            return null;
        }
        $routes = [ServerRelayService::ensureRouteId($node) => $node->group_ids ?? []];
        foreach ($children as $child) {
            $routes[ServerRelayService::ensureRouteId($child)] = $child->group_ids ?? [];
        }
        return $routes;
    }

    // 获取路由规则
    public static function getRoutes(array $routeIds)
    {
        return ServerRoute::select(['id', 'match', 'protocol', 'port', 'network', 'action', 'action_value'])
            ->whereIn('id', $routeIds)
            ->get()
            ->map(fn (ServerRoute $route) => $route->toNodeConfig())
            ->values();
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
    public static function processAlive(int $nodeId, array $alive, ?int $receivedAt = null): void
    {
        app(InboundIpRecorder::class)->record($alive, $receivedAt);
        app(DeviceStateService::class)->replaceNodeDevices($nodeId, $alive);
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

    /** 将节点上报的真实用户连接数交给插件，旧版节点不提供时不推断。 */
    public static function processConnectionCounts(Server $node, array $counts): void
    {
        $normalized = [];
        foreach ($counts as $userKey => $value) {
            $userId = filter_var($userKey, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($userId !== false && filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false) {
                $normalized[(int) $userId] = (int) $value;
            }
        }
        if ($normalized !== []) {
            HookManager::call('server.connection_counts.reported', [
                'node_id' => (int) $node->id,
                'counts' => $normalized,
            ]);
        }
    }

    /** 中转入口的真实连接数按实际出网节点拆分；节点 0 代表入口直连。 */
    public static function processRelayConnectionCounts(Server $entry, array $counts): bool
    {
        if ($counts === []) {
            return false;
        }
        $children = Server::query()
            ->where('relay_entry_id', $entry->id)
            ->pluck('id')->map(fn ($id) => (int) $id)->flip()->all();
        $nodes = [];
        foreach ($counts as $userKey => $userNodes) {
            $userId = filter_var($userKey, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($userId === false || !is_array($userNodes)) {
                continue;
            }
            foreach ($userNodes as $nodeKey => $value) {
                $nodeId = filter_var($nodeKey, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
                $count = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($nodeId === false || $count === false) {
                    continue;
                }
                $serverId = $nodeId === 0 ? (int) $entry->id : (int) $nodeId;
                if ($serverId !== (int) $entry->id && !isset($children[$serverId])) {
                    continue;
                }
                $nodes[$serverId][(int) $userId] = (int) $count;
            }
        }
        if ($nodes === []) {
            return false;
        }
        HookManager::call('server.relay_connection_counts.reported', [
            'entry_server_id' => (int) $entry->id,
            'nodes' => $nodes,
        ]);
        return true;
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

    /**
     * 处理节点上报的连接限制超限事件。
     *
     * 节点按上报周期汇总，同一用户可能同时上报并发、速率和设备数三条。
     * 校验后交给独立 Telegram Bot 保存待发送提醒，保留插件钩子。
     *
     * @param array $events 节点上报的原始事件列表
     */
    public static function processLimitEvents(Server $node, array $events): void
    {
        $normalized = [];

        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }
            $userId = (int) ($event['user_id'] ?? 0);
            $kind = (string) ($event['kind'] ?? '');
            if ($userId <= 0 || !in_array($kind, ['conn', 'rate', 'device'], true)) {
                continue;
            }
            $item = [
                'user_id' => $userId,
                'kind' => $kind,
                'limit' => max(0, (int) ($event['limit'] ?? 0)),
                'observed' => max(0, (int) ($event['observed'] ?? 0)),
                'count' => max(0, (int) ($event['count'] ?? 0)),
            ];
            // 被拒来源只属于设备数超限，并发和速率事件保持原有字段。
            if ($kind === 'device') {
                $item['ips'] = self::normalizeLimitEventIps($event['ips'] ?? null);
            }
            $normalized[] = $item;
        }

        if (empty($normalized)) {
            return;
        }

        app(\App\Services\TelegramBot\ReminderService::class)->recordLimits($normalized);

        HookManager::call('server.limit.exceeded', [
            'node_id' => (int) $node->id,
            'node_name' => (string) $node->name,
            'node_type' => (string) $node->type,
            'events' => $normalized,
        ]);
    }

    /**
     * 设备数超限事件携带的被拒来源：只保留合法 IP，去重后最多 5 个，与 Node 上报口径一致。
     */
    private static function normalizeLimitEventIps(mixed $ips): array
    {
        if (!is_array($ips)) {
            return [];
        }

        $result = [];
        foreach ($ips as $ip) {
            if (!is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP) === false || in_array($ip, $result, true)) {
                continue;
            }
            $result[] = $ip;
            if (count($result) >= 5) {
                break;
            }
        }

        return $result;
    }

    /**
     * 处理中转入口按实际出网节点拆分的在线来源快照。
     *
     * 节点键 0 表示连接由入口自身出网，归到入口节点；其余键只接受以本节点为入口的逻辑子节点。
     * 面板不落库、不参与设备数限制，只做校验后触发 server.relay_alive.reported 钩子，供插件统计连接。
     *
     * @param array<string|int, mixed> $alive 用户 ID => 节点 ID => 来源 IP 列表
     */
    public static function processRelayUserAlive(Server $entry, array $alive): void
    {
        $children = Server::query()
            ->where('relay_entry_id', $entry->id)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->flip()
            ->all();

        $nodes = [];
        foreach ($alive as $userKey => $userNodes) {
            $userId = filter_var($userKey, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($userId === false || !is_array($userNodes)) {
                continue;
            }
            foreach ($userNodes as $nodeKey => $ips) {
                $nodeId = filter_var($nodeKey, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
                if ($nodeId === false || !is_array($ips)) {
                    continue;
                }
                $serverId = $nodeId === 0 ? (int) $entry->id : (int) $nodeId;
                if ($serverId !== (int) $entry->id && !isset($children[$serverId])) {
                    continue;
                }
                $list = $nodes[$serverId][(int) $userId] ?? [];
                foreach ($ips as $ip) {
                    if (count($list) >= 64) {
                        break;
                    }
                    if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false && !in_array($ip, $list, true)) {
                        $list[] = $ip;
                    }
                }
                if ($list !== []) {
                    $nodes[$serverId][(int) $userId] = $list;
                }
            }
        }

        if ($nodes === []) {
            return;
        }

        HookManager::call('server.relay_alive.reported', [
            'entry_server_id' => (int) $entry->id,
            'nodes' => $nodes,
        ]);
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
            'wireguard' => [...$baseConfig, 'server_port' => (int) $serverPort],
            default => [],
        };

        if ($relay = self::buildRelayConfig($node)) {
            $response['relay'] = $relay;
        }
        if ($nodeType === Server::TYPE_WIREGUARD && !$node->isRelayChild()) {
            $response['wireguard'] = WireGuardService::nodeConfig($node);
        }

        // HY2 内核仍监听单个服务端口，由 Node 管理客户端端口到监听端口的转发。
        if ($nodeType === Server::TYPE_HYSTERIA
            && (int) data_get($protocolSettings, 'version', 2) === 2
            && \App\Utils\PortSet::isMultiple((string) $node->port)
            && data_get($response, 'relay.mode') !== 'landing') {
            $response['port_hopping'] = self::normalizedPorts($node);
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

        if (($nodeType !== Server::TYPE_WIREGUARD || !$node->isRelayChild()) && data_get($node->source_policy, 'block_cn')) {
            $response['source_policy'] = [
                'block_cn' => true,
                'allow_ips' => array_values(data_get($node->source_policy, 'allow_ips', [])),
            ];
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

        // 名单为空时不下发该字段，未使用此功能的节点配置保持不变。
        if ($deviceIpExclude = DeviceIpExclusion::entries()) {
            $response['device_ip_exclude'] = $deviceIpExclude;
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

            if ($node->type === Server::TYPE_WIREGUARD) {
                $relay['wireguard'] = ServerRelayService::wireGuardConfig($node, true);
            } elseif ($node->type === Server::TYPE_SHADOWSOCKS) {
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

        if (!ServerRelayService::isSupportedEntry($node)) {
            return null;
        }

        $children = ServerRelayService::childrenOf($node);
        $blockedRoutes = ServerRelayService::blockedWireGuardRoutes($node);
        if ($children->isEmpty() && $blockedRoutes === []) {
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

            if ($child->type === Server::TYPE_WIREGUARD) {
                $outbound['wireguard'] = ServerRelayService::wireGuardConfig($child, false);
            } elseif ($child->type === Server::TYPE_SHADOWSOCKS) {
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
            ...($blockedRoutes !== [] ? ['blocked_route_ids' => $blockedRoutes] : []),
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
