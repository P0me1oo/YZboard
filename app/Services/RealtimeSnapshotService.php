<?php

namespace App\Services;

use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Cache;

/** 管理端只接收允许展示的运行字段，不发送完整用户、机器或节点配置。 */
class RealtimeSnapshotService
{
    public function __construct(private readonly RealtimeStateStore $states)
    {
    }

    public function validate(array $input): array
    {
        $rules = ['system' => 'sometimes|boolean'];
        foreach (['users', 'nodes', 'machines', 'devices'] as $type) {
            $rules[$type] = 'sometimes|array|max:' . ($type === 'devices' ? 20 : 500);
            $rules[$type . '.*'] = 'integer|min:1';
        }
        Validator::make($input, $rules)->validate();
        $result = ['system' => (bool) ($input['system'] ?? false)];
        foreach (['users', 'nodes', 'machines', 'devices'] as $type) {
            $ids = array_values(array_unique(array_map('intval', $input[$type] ?? [])));
            sort($ids);
            $result[$type] = $ids;
        }
        return $result;
    }

    public function snapshot(array $subscription): array
    {
        $demand = app(TelemetryDemand::class);
        $demand->renew(array_merge(
            $subscription['users'] !== [] ? ['users'] : [],
            array_map(fn ($id) => 'node:' . $id, $subscription['nodes']),
            array_map(fn ($id) => 'machine:' . $id, $subscription['machines']),
        ));
        // HTTP 和 WebSocket 共用捕获顺序，慢请求不能越过后发起的快照。
        $version = Cache::lock('realtime:admin:version:lock', 5)->block(2, function (): array {
            $version = Cache::get('realtime:admin:version') ?? ['epoch' => bin2hex(random_bytes(16)), 'sequence' => 0];
            $version['sequence']++;
            Cache::forever('realtime:admin:version', $version);
            return $version;
        });
        $result = ['users' => [], 'nodes' => [], 'machines' => [], 'devices' => [], 'system' => null, 'version' => $version];
        $userIds = array_values(array_unique(array_merge($subscription['users'], $subscription['devices'])));
        $runtime = new RealtimeReadContext($this->states);
        $displayNodes = collect();
        $nodes = collect();
        if ($userIds !== [] || $subscription['nodes'] !== []) {
            $allNodes = Server::query()->where(function ($query) use ($userIds, $subscription) {
                $query->whereIn('id', $subscription['nodes']);
                if ($userIds !== []) {
                    $query->orWhere(fn ($q) => $q->whereNull('relay_pool_id')->where(fn ($entry) => $entry->whereNull('relay_entry_id')->orWhere('relay_entry_id', 0)));
                }
            })->get(['id', 'type', 'parent_id', 'relay_entry_id', 'relay_pool_id', 'group_ids', 'enabled', 'u', 'd', 'usage_u', 'usage_d']);
            $displayNodes = $allNodes->whereIn('id', $subscription['nodes']);
            $nodes = $allNodes->filter(fn ($node) => !$node->relay_entry_id && !$node->relay_pool_id);
            $runtime->readMany(array_merge(
                $userIds === [] ? [] : $nodes->map(fn ($node) => 'node:' . $node->id)->all(),
                $displayNodes->map(fn ($node) => 'node:' . ($node->parent_id ?: $node->id))->all(),
            ));
        }
        if ($userIds !== []) {
            $users = User::query()->whereIn('id', $userIds)->get(['id', 'group_id', 'group_ids', 'online_count', 'last_online_at', 'u', 'd']);
            $sources = $runtime->readMany($nodes->map(fn ($node) => 'node:' . $node->id)->all());
            $connectionService = app(UserConnectionService::class);
            $connections = get_class($connectionService) === UserConnectionService::class
                ? $connectionService->forUsersWithNodes($users, $sources, $nodes)
                : $connectionService->forUsers($users, $sources);
            $speeds = app(UserSpeedService::class)->forUsers($users, $sources, $nodes);
            $devices = app(DeviceStateService::class);
            $legacyDevices = get_class($devices) === DeviceStateService::class
                ? $devices->readUsersDevices($users->pluck('id')->all(), $runtime, true)
                : $users->mapWithKeys(fn ($user) => [$user->id => $devices->getDeviceIPs((int) $user->id, true)])->all();
            foreach ($users as $user) {
                $groups = $user->effectiveGroupIds();
                $known = false;
                $missing = false;
                $currentIPs = [];
                foreach ($nodes as $node) {
                    $state = $sources['node:' . $node->id] ?? null;
                    $hasUser = !empty($state['data']['alive'][$user->id]);
                    if (!$hasUser && ($node->enabled === false || !array_intersect($groups, $node->group_ids ?? []))) continue;
                    if ($state === null) {
                        // 老版本沿用原设备状态读取规则，新版本额外区分采样失效。
                        $known = true;
                    } elseif ($state['fresh'] && array_key_exists('alive', $state['data'] ?? [])) {
                        $known = true;
                        foreach ($state['data']['alive'][$user->id] ?? [] as $ip) {
                            $key = DeviceIpExclusion::countKey((string) $ip);
                            if ($key !== null) $currentIPs[$key] = true;
                        }
                    } else {
                        $missing = true;
                    }
                }
                foreach ($legacyDevices[$user->id] ?? [] as $ip) {
                    $currentIPs[$ip] = true;
                }
                $ips = array_keys($currentIPs);
                sort($ips);
                if (in_array((int) $user->id, $subscription['users'], true)) {
                    $result['users'][$user->id] = $connections[$user->id] + $speeds[$user->id] + [
                        'id' => (int) $user->id,
                        'online_count' => $known ? count($ips) : null,
                        'online_count_partial' => $known && $missing,
                        'last_online_at' => $user->last_online_at,
                        'u' => (int) $user->u,
                        'd' => (int) $user->d,
                        'total_used' => (int) $user->u + (int) $user->d,
                    ];
                }
                if (in_array((int) $user->id, $subscription['devices'], true)) {
                    $result['devices'][$user->id] = ['known' => $known, 'partial' => $missing, 'ips' => $ips];
                }
            }
        }

        if ($subscription['nodes'] !== []) {
            $nodes = $displayNodes;
            $demand->renew($nodes->map(fn ($node) => 'node:' . ($node->parent_id ?: $node->id))->all());
            $sources = $runtime->readMany($nodes->map(fn ($node) => 'node:' . ($node->parent_id ?: $node->id))->all());
            $views = [];
            foreach ($nodes as $node) {
                $data = ['id' => (int) $node->id, 'u' => (int) $node->u, 'd' => (int) $node->d];
                $data['usage_u'] = (int) $node->usage_u;
                $data['usage_d'] = (int) $node->usage_d;
                $state = $sources['node:' . ($node->parent_id ?: $node->id)] ?? null;
                $viewKey = $node->type . ':' . ($node->parent_id ?: $node->id);
                if (!isset($views[$viewKey])) {
                    $view = $node->runtimeView($state);
                    if ($state !== null) {
                        $view['load_status'] = $state['fresh'] ? $this->loadView($state['data']['status'] ?? null, $view['last_check_at']) : null;
                    }
                    $views[$viewKey] = $view;
                }
                $data += $views[$viewKey];
                $result['nodes'][$node->id] = $data;
            }
        }

        if ($subscription['machines'] !== []) {
            $machines = ServerMachine::query()->whereIn('id', $subscription['machines'])
                ->get(['id', 'is_active', 'last_seen_at', 'load_status', 'agent_runtime', 'agent_operation', 'proxy_u', 'proxy_d']);
            $sources = $runtime->readMany($machines->map(fn ($machine) => 'machine:' . $machine->id)->all());
            foreach ($machines as $machine) {
                $operation = $machine->agent_operation;
                if ($operation && in_array($operation['status'] ?? null, ['pending', 'running'], true) && ($operation['expires_at'] ?? 0) <= time()) {
                    $operation['status'] = 'timeout';
                    $operation['error'] = 'timeout';
                }
                $data = [
                    'id' => (int) $machine->id, 'last_seen_at' => $machine->last_seen_at,
                    'load_status' => $machine->load_status,
                    'agent_runtime' => MachineAgentService::runtimeView($machine->agent_runtime),
                    'agent_operation' => $operation,
                    'proxy_u' => (int) $machine->proxy_u,
                    'proxy_d' => (int) $machine->proxy_d,
                ];
                $state = $sources['machine:' . $machine->id] ?? null;
                if ($state !== null) {
                    $data['realtime_stale'] = !$state['fresh'];
                    $data['last_seen_at'] = $state['received_at'] ? intdiv($state['received_at'], 1000) : null;
                    $data['load_status'] = $state['fresh'] ? $this->loadView($state['data']['status'] ?? null, $data['last_seen_at']) : null;
                }
                $result['machines'][$machine->id] = $data;
            }
        }
        if ($subscription['system']) {
            $result['system'] = app(QueueStateService::class)->snapshot();
        }
        return $result;
    }

    private function loadView(?array $status, ?int $updatedAt): ?array
    {
        if (!$status) return null;
        // 磁盘和交换区是可选采样，保持原管理页面所需的对象结构。
        $result = ['cpu' => (float) ($status['cpu'] ?? 0), 'updated_at' => $updatedAt];
        foreach (['mem', 'disk', 'swap'] as $type) {
            $result[$type] = [
                'total' => (int) ($status[$type]['total'] ?? 0),
                'used' => (int) ($status[$type]['used'] ?? 0),
            ];
        }
        foreach (['net', 'kernel_status'] as $type) {
            if (array_key_exists($type, $status)) $result[$type] = $status[$type];
        }
        return $result;
    }
}
