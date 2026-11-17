<?php

namespace App\Services;

use App\Models\BalancerPool;
use App\Models\Server;
use Illuminate\Support\Collection;

/** 直出池只有逻辑订阅节点，成员复用原监听，不生成内部中转。 */
class DirectPoolService
{
    public const ROLE = 'direct';

    public static function poolFor(Server $node): ?BalancerPool
    {
        if (!$node->balancer_pool_id || $node->balancer_role === self::ROLE) return null;
        return BalancerPool::whereKey($node->balancer_pool_id)->where('mode', 'direct')->first();
    }

    public static function node(BalancerPool $pool): ?Server
    {
        return Server::whereKey($pool->gateway_node_id)->where('balancer_role', self::ROLE)
            ->where('balancer_pool_id', $pool->id)->first();
    }

    public static function settings(BalancerPool $pool): array
    {
        return (array) data_get($pool->template_config, 'direct_settings', []);
    }

    public static function saveNode(BalancerPool $pool): void
    {
        $settings = self::settings($pool);
        $node = self::node($pool) ?? new Server();
        $node->fill(['name' => $pool->name, 'type' => $pool->protocol, 'host' => $pool->domain,
            'port' => (string) $pool->gateway_port, 'server_port' => $pool->gateway_port,
            'machine_id' => null, 'balancer_role' => self::ROLE, 'balancer_pool_id' => $pool->id,
            'group_ids' => $settings['group_ids'], 'rate' => $settings['rate'], 'rate_time_enable' => false,
            'show' => $settings['show'], 'sort' => $settings['sort'], 'enabled' => $pool->enabled,
            'kernel_type' => data_get($pool->template_config, 'kernel_type', 'xray'), 'protocol_settings' => []]);
        $node->saveQuietly();
        if ((int) $pool->gateway_node_id !== (int) $node->id) $pool->update(['gateway_node_id' => $node->id]);
    }

    public static function project(Server $node): ?Server
    {
        $pool = BalancerPool::find($node->balancer_pool_id);
        if (!$pool || $pool->mode !== 'direct' || !$pool->enabled || $node->enabled === false) return null;
        $reference = LoadBalancerService::dnsReference($pool);
        $node->protocol_settings = $reference->protocol_settings;
        $settings = $node->protocol_settings;
        unset($settings['reality_settings']['private_key'], $settings['encryption']['decryption']);
        $node->protocol_settings = $settings;
        $node->host = $pool->domain;
        $node->port = (int) $pool->gateway_port;
        $node->rate = $node->getCurrentRate();
        return $node;
    }

    public static function clientArray(Server $node): array
    {
        $pool = BalancerPool::with('members.runtimeNode.machine')->find($node->balancer_pool_id);
        $members = $pool?->members->filter(fn ($member) => $pool->enabled && LoadBalancerService::memberOnline($pool, $member)
            && LoadBalancerService::memberCompatibility($pool, $member->runtimeNode) === []
            && (!($pool->scheduling_config['health_enabled'] ?? false) || !empty($member->healthy_addresses))) ?? collect();
        $result = $node->toArray();
        $result['last_check_at'] = $members->map(fn ($member) => LoadBalancerService::heartbeat($member->runtimeNode))->max();
        $result['is_online'] = $members->isEmpty() ? 0 : 1;
        $result['last_push_at'] = $members->map(fn ($member) => $member->runtimeNode->last_push_at)->max();
        $result['available_status'] = $members->map(fn ($member) => $member->runtimeNode->available_status)->max() ?? Server::STATUS_OFFLINE;
        $result['cache_key'] = "{$node->type}-{$node->id}-{$node->updated_at}-{$result['is_online']}";
        return $result;
    }

    /** 运行监测只计算真实成员，权限和启停跟随池；一次读取供整批节点复用。 */
    public static function runtimeNodes(Collection $nodes): Collection
    {
        $pools = BalancerPool::with('members')->where('mode', 'direct')->get();
        if ($pools->isEmpty()) return $nodes;
        $virtualIds = $pools->pluck('gateway_node_id')->all();
        $members = [];
        foreach ($pools as $pool) foreach ($pool->members as $member) $members[$member->source_node_id] = $pool;
        return $nodes->reject(fn ($node) => in_array($node->id, $virtualIds))->map(function ($node) use ($members) {
            if (!isset($members[$node->id])) return $node;
            $node = clone $node;
            $pool = $members[$node->id];
            $node->group_ids = self::settings($pool)['group_ids'] ?? [];
            $node->enabled = $node->enabled !== false && $pool->enabled;
            return $node;
        })->values();
    }
}
