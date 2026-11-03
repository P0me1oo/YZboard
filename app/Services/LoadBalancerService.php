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
    public static function agentCompatible(ServerMachine $machine): bool
    {
        $version = ltrim((string) data_get($machine->agent_runtime, 'version', ''), 'v');
        return preg_match('/\A\d+\.\d+\.\d+(?:[-+].*)?\z/', $version) && version_compare($version, '2.25.0', '>=');
    }

    public static function heartbeat(Server $node): ?int
    {
        $value = Cache::get(CacheKey::get('SERVER_' . strtoupper($node->type) . '_LAST_CHECK_AT', $node->id));
        return is_numeric($value) ? (int) $value : null;
    }

    public static function publicPort(Server $node): ?int
    {
        $port = trim((string) $node->port);
        return preg_match('/\A[0-9]+\z/', $port) && (int) $port >= 1 && (int) $port <= 65535 ? (int) $port : null;
    }

    /** DNS 复用原服务，只比较客户端需要共用的连接参数，不比较 NAT 内部监听端口。 */
    public static function dnsCompatibility(Server $node, ?Server $first = null): array
    {
        $reasons = [];
        if (!$node->machine_id || !$node->machine) $reasons[] = '未绑定服务器';
        if ($node->parent_id) $reasons[] = '共享节点请使用实际运行的父节点';
        if ($node->accept_proxy_protocol) $reasons[] = '原节点启用了 PROXY 协议接收，不能直接作为 DNS 入口';
        if ($node->balancer_role !== null || $node->relayEntryId() !== null || $node->relay_pool_id) $reasons[] = '请选择独立前置节点';
        if ($node->type !== 'vless') return [...$reasons, '协议不兼容，仅支持 VLESS Reality'];
        if ((int) data_get($node->protocol_settings, 'tls') !== 2) $reasons[] = 'TLS 模式不兼容，需要 Reality';
        if (!in_array(strtolower((string) data_get($node->protocol_settings, 'network', 'tcp')), ['', 'tcp', 'raw'], true)) $reasons[] = '传输协议不兼容，需要 TCP';
        if (self::publicPort($node) === null) $reasons[] = '公网端口必须是 1–65535 的单一端口';
        $error = ServerRelayService::validateEntrySettings($node->type, (array) $node->protocol_settings, $node->host, $node->kernel_type);
        if ($error !== null) $reasons[] = $error;
        if ($first) {
            if (self::publicPort($node) !== self::publicPort($first)) $reasons[] = '公网端口与首选节点不同';
            foreach (['flow' => 'VLESS 流控', 'reality_settings.public_key' => 'Reality 公钥',
                'reality_settings.short_id' => 'Reality 短 ID', 'reality_settings.server_name' => 'Reality 握手域名',
                'reality_settings.server_port' => 'Reality 握手端口'] as $key => $label) {
                $value = trim((string) data_get($node->protocol_settings, $key, $key === 'reality_settings.server_port' ? 443 : ''));
                $reference = trim((string) data_get($first->protocol_settings, $key, $key === 'reality_settings.server_port' ? 443 : ''));
                if (in_array($key, ['reality_settings.short_id', 'reality_settings.server_name'], true)) {
                    $value = strtolower($value); $reference = strtolower($reference);
                }
                if ($value !== $reference) $reasons[] = $label . '与首选节点不同';
            }
        }
        return array_values(array_unique($reasons));
    }

    public static function dnsReference(BalancerPool $pool): Server
    {
        return new Server([...$pool->template_config, 'port' => (string) $pool->gateway_port]);
    }

    public static function memberCompatibility(BalancerPool $pool, Server $node): array
    {
        return self::dnsCompatibility($node, self::dnsReference($pool));
    }

    public static function memberOnline(BalancerPool $pool, BalancerMember $member): bool
    {
        $node = $member->runtimeNode;
        if (!$node) return false;
        return $node->available_status !== Server::STATUS_OFFLINE
            && $node->enabled !== false && $node->machine?->is_active === true && !MachineTrafficService::nodeBlocked($node);
    }

    public function save(array $data): BalancerPool
    {
        if (($data['mode'] ?? 'dns') !== 'dns') $this->invalid('mode', '前置池仅支持 DNS 用户分配');
        if (!empty($data['id'])) return Cache::lock('balancer:dns:' . $data['id'], 120)->block(2, fn () => $this->persist($data));
        return $this->persist($data);
    }

    private function persist(array $data): BalancerPool
    {
        return DB::transaction(function () use ($data) {
            $pool = isset($data['id']) ? BalancerPool::query()->lockForUpdate()->findOrFail($data['id']) : new BalancerPool();
            if ($pool->exists && $pool->mode !== 'dns') $this->invalid('mode', '请先在旧版本转换该池');
            $data['mode'] = 'dns';
            $data['personal_domain_enabled'] = true;
            // 兼容旧管理端传入的开关；池始终跟随节点配置的连接地址。
            unset($data['ddns_enabled']);
            $starting = (bool) $data['enabled'];
            $members = collect($data['members']);
            if ($members->isEmpty() || $members->pluck('node_id')->unique()->count() !== $members->count()) $this->invalid('members', '请选择不重复的前置节点');
            foreach ($members as $member) {
                if (($member['weight'] ?? 1) < 1 || ($member['weight'] ?? 1) > 1000) $this->invalid('members', '成员权重范围为 1–1000');
            }
            $sources = Server::with('machine')->whereIn('id', $members->pluck('node_id'))->lockForUpdate()->get()->keyBy('id');
            $stored = $pool->exists ? $pool->members()->get()->keyBy('source_node_id') : collect();
            // 参数已不兼容时仍允许只停用；不以停用操作修改节点或密钥。
            $statusOnly = $pool->exists && !$starting
                && $members->map(fn ($m) => [(int) $m['node_id'], (int) $m['priority'], (int) ($m['weight'] ?? 1)])->values()->all()
                    === $stored->map(fn ($m) => [(int) $m->source_node_id, (int) $m->priority, (int) $m->weight])->values()->all();
            foreach (collect($data)->except(['id', 'members', 'enabled']) as $key => $value) {
                $current = $pool->{$key};
                if ($key === 'scheduling_config') {
                    $current = array_replace(FrontPoolScheduler::DEFAULTS, $current ?? []);
                    $value = array_replace(FrontPoolScheduler::DEFAULTS, $value ?? []);
                }
                if ($current != $value) $statusOnly = false;
            }
            if ($statusOnly) {
                $pool->update(['enabled' => false]);
                DB::afterCommit(fn () => $this->notify($pool));
                return $pool->fresh(['members.sourceNode', 'members.runtimeNode']);
            }
            $first = $sources->get((int) $members->first()['node_id']);
            if (!$first) $this->invalid('members', '请选择已有的前置节点');
            foreach ($members as $member) {
                $source = $sources->get((int) $member['node_id']);
                if (!$source) $this->invalid('members', '前置节点不存在');
                $reasons = self::dnsCompatibility($source, $first);
                if ($reasons) $this->invalid('members', $source->name . '：' . implode('；', $reasons));
                if ($starting && !self::agentCompatible($source->machine)) $this->invalid('members', '前置需要先运行 Agent v2.25.0 或更新版本');
            }
            if ($sources->pluck('machine_id')->unique()->count() !== $sources->count()) $this->invalid('members', '每台服务器只能选择一个前置节点');
            $hosts = $sources->map(fn ($source) => strtolower(rtrim(trim($source->host, '[] '), '.')));
            if ($hosts->unique()->count() !== $hosts->count() || $hosts->contains(strtolower(rtrim($data['domain'], '.')))) $this->invalid('members', '成员地址必须分别直达各自服务器');
            $policy = array_replace(FrontPoolScheduler::DEFAULTS, $data['scheduling_config'] ?? $pool->scheduling_config ?? []);
            if (!in_array($policy['strategy'], ['priority', 'latency', 'latency_balanced', 'cycle'], true)) $this->invalid('scheduling_config', '请选择有效的前置选择方式');
            if (in_array($policy['strategy'], ['latency', 'latency_balanced'], true)) {
                $target = Server::find($policy['latency_node_id']);
                if (!$pool->exists || !$target || (int) $target->relay_pool_id !== (int) $pool->id || !SchedulingProbeService::target($target)) $this->invalid('scheduling_config.latency_node_id', '请选择绑定该池、使用 TCP 内部链路的落地作为测速目标');
                if ($starting && $sources->contains(fn ($source) => !LandingPoolService::compatibleAgent($source))) $this->invalid('members', '延迟选择需要前置运行 Agent v2.30.0 或更新版本');
            } else $policy['latency_node_id'] = null;
            $data['scheduling_config'] = $policy;
            $preset = \App\Models\BalancerDnsPreset::whereKey($data['dns_preset_id'] ?? 0)->lockForUpdate()->first();
            if (!$preset || $preset->provider !== 'powerdns') $this->invalid('dns_preset_id', '请选择 PowerDNS 域名配置');
            if ($data['domain'] !== $preset->domain && !str_ends_with($data['domain'], '.' . $preset->domain)) $this->invalid('domain', '池域名必须属于所选 PowerDNS 区域');
            // 32 位用户标签及最长成员标签都要满足完整域名长度限制。
            if (strlen($data['domain']) > 218) $this->invalid('domain', '池域名过长，无法生成用户独立域名');
            if (\App\Models\DdnsBinding::where('domain', $data['domain'])->exists()) $this->invalid('domain', '该域名已被节点 DDNS 使用');
            if ($pool->exists && ($pool->domain !== $data['domain'] || $pool->dns_preset_id != $data['dns_preset_id'])
                && \App\Models\PowerDnsRecord::where('owner_type', 'pool')->where('owner_id', $pool->id)->exists()) $this->invalid('domain', '修改域名前请先停用池并同步清理解析');
            $pool->fill(collect($data)->except(['id', 'members'])->all());
            $pool->protocol = $first->type;
            $pool->template_node_id = $first->id;
            $pool->template_config = collect($first->toArray())->only(['type', 'kernel_type', 'rate', 'group_ids', 'protocol_settings', 'route_ids', 'source_policy', 'rate_time_enable', 'rate_time_ranges'])->all();
            // 沿用数据库中的公网端口字段；不再创建转发入口或专用监听。
            $pool->gateway_port = self::publicPort($first);
            $pool->backend_port = $pool->gateway_port;
            $pool->gateway_machine_id = 0;
            $pool->gateway_address = '';
            $pool->gateway_source_ips = [];
            $pool->save();
            foreach ($members->values() as $position => $values) {
                $source = $sources[(int) $values['node_id']];
                $member = $stored->pull($source->id) ?? new BalancerMember(['pool_id' => $pool->id, 'source_node_id' => $source->id]);
                $member->runtime_node_id = $source->id;
                $member->priority = (int) $values['priority'];
                $member->weight = (int) ($values['weight'] ?? 1);
                $member->position = $position;
                $member->dns_label ??= bin2hex(random_bytes(12));
                $member->dns_addresses = DnsPoolService::addresses($source);
                if (!$member->dns_addresses) $this->invalid('members', $source->name . '：请填写有效的节点连接 IP 或可解析域名');
                $member->save();
            }
            foreach ($stored as $member) {
                $id = (int) $member->source_node_id;
                $member->delete();
                DB::afterCommit(fn () => NodeSyncService::notifyFullSync($id));
            }
            if ($starting && in_array($policy['strategy'], ['latency', 'latency_balanced'], true)
                && $sources->contains(fn ($source) => count(SchedulingProbeService::targets($source, false)) > 256)) $this->invalid('members', '前置探测目标超过 256 个');
            DB::afterCommit(fn () => $this->notify($pool));
            return $pool->fresh(['members.sourceNode', 'members.runtimeNode']);
        }, 3);
    }

    public static function entryForPool(int $poolId): ?Server
    {
        $pool = BalancerPool::with('members.runtimeNode.machine')->find($poolId);
        if (!$pool || !$pool->enabled) return null;
        $entry = $pool->members->pluck('runtimeNode')->filter(fn ($node) => $node && $node->enabled !== false
            && !MachineTrafficService::nodeBlocked($node)
            && self::memberCompatibility($pool, $node) === [])->first();
        if (!$entry) return null;
        $entry = clone $entry;
        $entry->host = $pool->domain;
        $entry->port = (string) $pool->gateway_port;
        $entry->server_port = (int) $pool->gateway_port;
        return $entry;
    }

    public function notify(BalancerPool $pool): void
    {
        $nodes = Server::whereIn('id', $pool->members()->pluck('runtime_node_id'))->get();
        foreach ($nodes->pluck('machine_id')->filter()->unique() as $machineId) NodeSyncService::notifyMachineNodesChanged((int) $machineId);
        foreach ($nodes as $node) NodeSyncService::notifyFullSync($node->id);
        foreach (Server::where('relay_pool_id', $pool->id)->pluck('id') as $id) NodeSyncService::notifyConfigUpdated((int) $id);
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
