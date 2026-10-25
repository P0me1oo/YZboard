<?php

namespace App\Services;

use App\Models\BalancerMember;
use App\Models\BalancerPool;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Utils\CacheKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LoadBalancerService
{
    public const GATEWAY_PROTOCOL = 'balancer';

    public static function agentCompatible(ServerMachine $machine): bool
    {
        $version = ltrim((string) data_get($machine->agent_runtime, 'version', ''), 'v');
        return preg_match('/\A\d+\.\d+\.\d+(?:[-+].*)?\z/', $version) && version_compare($version, '2.25.0', '>=');
    }

    public static function offlineThreshold(): int
    {
        return max(30, min(86400, (int) admin_setting('load_balance_offline_threshold', 180)));
    }

    public static function heartbeat(Server $node): ?int
    {
        // 必须读取本实例的心跳，不能继承父级节点的在线状态。
        $value = Cache::get(CacheKey::get('SERVER_' . strtoupper($node->type) . '_LAST_CHECK_AT', $node->id));
        if ($value === null && $node->balancer_role === 'member') {
            $value = BalancerMember::where('runtime_node_id', $node->id)->value('last_report_at');
        }
        return is_numeric($value) ? (int) $value : null;
    }

    public static function online(Server $node): bool
    {
        $last = self::heartbeat($node);
        return $node->enabled !== false && $last !== null && now()->timestamp - $last < self::offlineThreshold()
            && !MachineTrafficService::nodeBlocked($node);
    }

    public function save(array $data): BalancerPool
    {
        if (!empty($data['id'])) {
            return Cache::lock('balancer:dns:' . $data['id'], 120)->block(2, fn () => $this->persist($data));
        }
        return $this->persist($data);
    }

    private function persist(array $data): BalancerPool
    {
        return DB::transaction(function () use ($data) {
            $ddns = $data['ddns'] ?? null;
            unset($data['ddns']);
            $pool = isset($data['id']) ? BalancerPool::query()->lockForUpdate()->findOrFail($data['id']) : new BalancerPool();
            $personalDdns = $ddns['enabled'] ?? ($pool->exists && DdnsService::current('pool', $pool->id)?->enabled);
            if ($personalDdns && !array_key_exists('gateway_address', $data)) {
                $this->invalid('gateway_address', '开启 DDNS 时请明确填写统一入口连接 IP');
            }
            $starting = (bool) $data['enabled'];
            $members = collect($data['members']);
            $sources = Server::query()->whereIn('id', $members->pluck('node_id'))->lockForUpdate()->get()->keyBy('id');
            $storedMembers = $pool->exists ? $pool->members()->with('runtimeNode')->get()->keyBy('source_node_id') : collect();
            // 已有成员使用池专用入口的快照，编辑原直连节点不会悄悄改变池的服务器或密钥。
            foreach ($storedMembers as $member) {
                if (!$member->runtimeNode || !$sources->has($member->source_node_id)) continue;
                $source = clone $member->runtimeNode;
                $source->id = $member->source_node_id;
                $source->balancer_role = null;
                $source->balancer_pool_id = null;
                $sources->put($member->source_node_id, $source);
            }
            $first = $sources->get((int) $members->first()['node_id']);
            if (!$first || $first->type !== 'vless' || !ServerRelayService::isSupportedEntry($first) || $first->balancer_role !== null) {
                $this->invalid('members', '第一版请选择 VLESS Reality + TCP 前置');
            }
            if ($first->type === 'vless' && ((int) data_get($first->protocol_settings, 'tls') !== 2
                || !in_array(strtolower((string) data_get($first->protocol_settings, 'network', 'tcp')), ['', 'tcp', 'raw'], true))) {
                $this->invalid('members', '本版 VLESS 前置池支持 Reality + TCP；其他传输继续使用独立节点');
            }
            if ($pool->exists && $pool->protocol !== $first->type) $this->invalid('members', '修改协议请新建前置池，避免影响已有订阅');
            foreach ($members as $member) {
                $source = $sources->get((int) $member['node_id']);
                if (!$source || !$source->machine_id || !$source->machine || ($starting && !$source->machine->is_active) || $source->type !== $first->type
                    || !ServerRelayService::isSupportedEntry($source) || $source->balancer_role !== null) {
                    $this->invalid('members', '成员必须是同协议、已绑定启用服务器的独立前置');
                }
                if ($starting && !self::agentCompatible($source->machine)) $this->invalid('members', '参与前置池的服务器需要先运行 Node v2.25.0 或更新版本');
                $error = ServerRelayService::validateEntrySettings($first->type, $first->protocol_settings, $first->host, $source->kernel_type);
                if ($error !== null) $this->invalid('members', $error);
            }
            if ($sources->pluck('machine_id')->unique()->count() !== $sources->count()) {
                $this->invalid('members', '同一个池每台服务器只能选择一个模板节点');
            }
            $hosts = $sources->map(fn ($source) => strtolower(rtrim(trim($source->host, '[] '), '.')));
            if ($hosts->unique()->count() !== $hosts->count() || $hosts->contains(strtolower(rtrim($data['domain'], '.')))) {
                $this->invalid('members', '成员地址必须分别直达各自服务器，不能使用共同的入口域名');
            }
            $machine = ServerMachine::query()->whereKey($data['gateway_machine_id'])->when($starting, fn ($query) => $query->where('is_active', true))->first();
            if (!$machine) $this->invalid('gateway_machine_id', '请选择已启用的调度入口服务器');
            if ($starting && !self::agentCompatible($machine)) $this->invalid('gateway_machine_id', '调度入口服务器需要先运行 Node v2.25.0 或更新版本');
            if ($machine->traffic_mode === 'proxy' && $machine->traffic_limit > 0) {
                $this->invalid('gateway_machine_id', '调度入口的流量限额请使用网卡计量模式，避免将转发量误作用户用量');
            }
            // 新配置直接读取机器上报与首个节点；已有池编辑、启停不改变当前连接地址。
            if (!array_key_exists('gateway_address', $data)) {
                if ($pool->exists && (int) $pool->gateway_machine_id === (int) $machine->id) {
                    $data['gateway_address'] = $pool->gateway_address;
                } else {
                    $addresses = $this->machineAddresses($machine);
                    if ($addresses === []) $this->invalid('gateway_machine_id', '所选服务器尚未上报公网 IP，请先确认节点程序正常上报');
                    $data['gateway_address'] = $addresses[0];
                    if (empty($data['gateway_source_ips'])) $data['gateway_source_ips'] = $addresses;
                }
            }
            if (!array_key_exists('gateway_port', $data)) {
                $preferred = $pool->exists ? (int) $pool->gateway_port : (int) $first->port;
                if ($preferred < 1 || $preferred > 65535) $this->invalid('members', '首个前置节点没有有效的连接端口');
                $data['gateway_port'] = $this->gatewayPort((int) $machine->id, $preferred, $pool->gateway_node_id);
            }
            $gatewayPort = (int) $data['gateway_port'];
            if (!empty($data['dns_preset_id'])) \App\Models\BalancerDnsPreset::query()->whereKey($data['dns_preset_id'])->lockForUpdate()->firstOrFail();
            $occupied = Server::query()->where('machine_id', $machine->id)->where('server_port', $gatewayPort)
                ->when($pool->gateway_node_id, fn ($query) => $query->where('id', '!=', $pool->gateway_node_id))->exists();
            if ($occupied) $this->invalid('gateway_port', '调度入口端口已被其他节点使用');
            if ($pool->exists && ($pool->domain !== strtolower($data['domain']) || $pool->dns_preset_id != ($data['dns_preset_id'] ?? null)) && $pool->gateway_node_id) {
                // 旧域名的托管记录必须先明确移除，避免改名后遗留入口。
                if (\App\Models\BalancerDnsRecord::where('pool_id', $pool->id)->exists()) {
                    $this->invalid('domain', '修改域名前请先停用池并完成 DNS 同步');
                }
            }
            $pool->fill(collect($data)->except(['id', 'members'])->all());
            $pool->domain = strtolower(rtrim($pool->domain, '.'));
            $pool->protocol = $first->type;
            if (!$pool->exists) {
                $pool->template_node_id = $first->id;
                $pool->template_config = collect($first->toArray())->only(['type', 'kernel_type', 'rate', 'group_ids', 'protocol_settings', 'route_ids', 'source_policy', 'rate_time_enable', 'rate_time_ranges'])->all();
            }
            $pool->backend_port ??= $this->allocatePort($sources->pluck('machine_id')->all(), $gatewayPort);
            if ($gatewayPort === (int) $pool->backend_port && $sources->contains('machine_id', $machine->id)) {
                $this->invalid('gateway_port', '同一服务器上的调度入口端口不能与池成员端口相同');
            }
            if (Server::whereIn('machine_id', $sources->pluck('machine_id'))->where('server_port', $pool->backend_port)
                ->where(fn ($query) => $query->whereNull('balancer_pool_id')->orWhere('balancer_pool_id', '!=', $pool->id ?? 0))->exists()) {
                $this->invalid('members', '新成员的池入口端口已被其他节点使用');
            }
            $pool->save();

            $oldMembers = $storedMembers;
            foreach ($members->values() as $position => $memberData) {
                $source = $sources[(int) $memberData['node_id']];
                $member = $oldMembers->pull($source->id) ?? new BalancerMember(['pool_id' => $pool->id, 'source_node_id' => $source->id]);
                $runtime = $member->runtime_node_id ? Server::find($member->runtime_node_id) : null;
                $runtime ??= new Server();
                // 池专用入口复制模板，不更改已有直连节点；证书来源仍取该服务器自己的设置。
                $runtime->fill($pool->template_config);
                // 池专用入口只接受已绑定落地的权限，不继承原直连节点的直接出网权限。
                $runtime->group_ids = [];
                $runtime->cert_config = $source->cert_config;
                $runtime->machine_id = $source->machine_id;
                $runtime->name = $pool->name . ' / ' . $source->name;
                $runtime->host = $source->host;
                $runtime->port = (string) $pool->backend_port;
                $runtime->server_port = (int) $pool->backend_port;
                $runtime->enabled = $pool->enabled;
                $runtime->show = false;
                $runtime->parent_id = null;
                $runtime->relay_entry_id = null;
                $runtime->balancer_pool_id = $pool->id;
                $runtime->balancer_role = 'member';
                $runtime->auto_firewall = $source->auto_firewall;
                $runtime->save();
                $member->runtime_node_id = $runtime->id;
                $member->priority = (int) $memberData['priority'];
                $member->position = $position;
                $member->save();
            }
            foreach ($oldMembers as $member) {
                $member->runtimeNode?->delete();
                $member->delete();
            }
            $gateway = $pool->gateway_node_id ? Server::find($pool->gateway_node_id) : null;
            $gateway ??= new Server();
            $gateway->fill(['type' => self::GATEWAY_PROTOCOL, 'kernel_type' => 'xray', 'name' => $pool->name . ' / 调度入口',
                'host' => $pool->gateway_address, 'port' => (string) $pool->gateway_port, 'server_port' => $pool->gateway_port,
                'machine_id' => $pool->gateway_machine_id, 'enabled' => $pool->enabled, 'show' => false, 'rate' => 1,
                'balancer_pool_id' => $pool->id, 'balancer_role' => 'gateway', 'protocol_settings' => [], 'group_ids' => []]);
            $gateway->save();
            $pool->gateway_node_id = $gateway->id;
            $pool->save();
            app(DdnsService::class)->configure('pool', $pool, $ddns);
            DB::afterCommit(fn () => $this->notify($pool));
            return $pool->fresh(['members.sourceNode', 'members.runtimeNode']);
        }, 3);
    }

    public static function entryForPool(int $poolId): ?Server
    {
        $pool = BalancerPool::with('members.runtimeNode')->find($poolId);
        if (!$pool || !$pool->enabled) return null;
        $entry = $pool->members->pluck('runtimeNode')->filter(fn ($node) => $node && $node->enabled !== false
            && !MachineTrafficService::nodeBlocked($node))->first();
        if (!$entry) return null;
        $entry = clone $entry;
        $entry->host = $pool->domain;
        $entry->port = (string) $pool->gateway_port;
        $entry->server_port = (int) $pool->gateway_port;
        return $entry;
    }

    public static function gatewayConfig(Server $node): array
    {
        $pool = BalancerPool::with(['members.runtimeNode.machine'])->findOrFail($node->balancer_pool_id);
        $targets = $pool->members->filter(fn ($member) => $member->runtimeNode)->map(function ($member) use ($pool) {
            $host = trim($member->runtimeNode->host, '[]');
            return ['id' => (int) $member->runtime_node_id, 'address' => (str_contains($host, ':') ? '[' . $host . ']' : $host) . ':' . $pool->backend_port,
                'priority' => (int) $member->priority, 'online' => self::online($member->runtimeNode) && $member->runtimeNode->machine?->is_active === true];
        })->values()->all();
        return ['protocol' => self::GATEWAY_PROTOCOL, 'kernel_type' => 'xray', 'listen_ip' => str_contains($pool->gateway_address, ':') ? '::' : '0.0.0.0',
            'server_port' => (int) $pool->gateway_port, 'network' => 'tcp',
            'auto_firewall' => (bool) $node->auto_firewall,
            'balancer' => ['network' => 'tcp', 'targets' => $targets, 'max_sessions' => 16384]];
    }

    public function notify(BalancerPool $pool): void
    {
        $nodes = Server::where('balancer_pool_id', $pool->id)->get();
        foreach ($nodes->pluck('machine_id')->filter()->unique() as $machineId) NodeSyncService::notifyMachineNodesChanged((int) $machineId);
        foreach ($nodes as $node) NodeSyncService::notifyFullSync($node->id);
        foreach (Server::where('relay_pool_id', $pool->id)->pluck('id') as $id) NodeSyncService::notifyConfigUpdated((int) $id);
    }

    private function allocatePort(array $machines, int $gatewayPort): int
    {
        $used = Server::whereIn('machine_id', $machines)->pluck('server_port')->map(fn ($v) => (int) $v)->flip();
        for ($port = 20000; $port <= 65535; $port++) if ($port !== $gatewayPort && !$used->has($port)) return $port;
        $this->invalid('members', '没有可供全部成员使用的池入口端口');
    }

    private function machineAddresses(ServerMachine $machine): array
    {
        $runtime = MachineAgentService::runtimeView($machine->agent_runtime) ?? [];
        $addresses = array_key_exists('public_ipv4', $runtime) || array_key_exists('public_ipv6', $runtime)
            ? [$runtime['public_ipv4'] ?? null, $runtime['public_ipv6'] ?? null] : [$runtime['public_ip'] ?? null];
        return array_values(array_unique(array_filter($addresses, fn ($address) => is_string($address)
            && filter_var($address, FILTER_VALIDATE_IP) && !in_array($address, ['0.0.0.0', '::'], true))));
    }

    private function gatewayPort(int $machineId, int $preferred, ?int $gatewayNodeId): int
    {
        $used = Server::where('machine_id', $machineId)
            ->when($gatewayNodeId, fn ($query) => $query->where('id', '!=', $gatewayNodeId))
            ->pluck('server_port')->map(fn ($value) => (int) $value)->flip();
        if (!$used->has($preferred)) return $preferred;
        for ($port = 20000; $port <= 65535; $port++) if (!$used->has($port)) return $port;
        $this->invalid('gateway_machine_id', '所选服务器没有可用的入口端口');
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
