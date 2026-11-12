<?php

namespace App\Services;

use App\Models\BalancerPool;
use App\Models\Server;

class FrontPoolScheduler
{
    public const DEFAULTS = ['health_enabled' => false, 'strategy' => 'priority', 'latency_node_id' => null,
        'quota_enabled' => false, 'quota_percent' => 10,
        'dynamic_enabled' => false, 'bandwidth_threshold' => 80, 'connection_threshold' => 1000,
        'load_confirm_seconds' => 60, 'load_cooldown_seconds' => 300, 'carrier_enabled' => false];

    public function __construct(private readonly FrontPortProbe $probe) {}

    /** 返回待发布地址与运行状态；DNS API 成功后才记为实际切换。 */
    public function select(BalancerPool $pool): array
    {
        $config = array_replace(self::DEFAULTS, $pool->scheduling_config ?? []);
        if ($config['dynamic_enabled']) $config['strategy'] = 'priority';
        $all = []; $members = [];
        foreach ($pool->members as $member) {
            $node = $member->runtimeNode;
            // 每次同步读取节点连接地址，同时纠正旧池保存的机器上报地址。
            $addresses = $member->sourceNode ? DnsPoolService::addresses($member->sourceNode) : [];
            $member->dns_addresses = $addresses;
            $reason = !$pool->enabled ? 'disabled' : (!$node || !LoadBalancerService::memberOnline($pool, $member) ? 'offline'
                : (LoadBalancerService::memberCompatibility($pool, $node) ? 'incompatible' : (!$addresses ? 'no_address' : 'healthy')));
            $members[$member->id] = ['member' => $member, 'node' => $node, 'addresses' => $reason === 'healthy' ? $addresses : [], 'reason' => $reason];
            if ($reason === 'healthy') $all = array_merge($all, $addresses);
        }
        $probes = $config['health_enabled'] && $all ? $this->probe->check($all, $pool->gateway_port) : [];
        $lite = app(LiteMonitor::class)->schedulingSnapshot($pool->members
            ->map(fn ($member) => $member->runtimeNode?->machine_id)->filter()->unique()->values()->all());
        $eligible = []; $snapshot = []; $target = !$config['health_enabled'] && in_array($config['strategy'], ['latency', 'latency_balanced'], true) ? Server::find($config['latency_node_id']) : null;
        foreach ($members as $id => $item) {
            $member = $item['member']; $node = $item['node']; $state = $member->scheduling_state ?? [];
            $interrupted = in_array($item['reason'], ['disabled', 'offline', 'incompatible', 'no_address'], true);
            $recovering = in_array($state['reason'] ?? '', ['disabled', 'offline', 'incompatible', 'no_address'], true);
            // 没有解析结果时保留既有地址的失败状态；配置得到新地址后移除不再使用的状态。
            $ports = $config['health_enabled'] && $interrupted ? ($state['ports'] ?? []) : [];
            if ($ports && $member->dns_addresses) $ports = array_intersect_key($ports, array_flip($member->dns_addresses));
            foreach ($ports as &$portState) $portState = [...$portState, 'healthy' => false, 'good' => 0, 'bad' => 0];
            unset($portState);
            $addresses = [];
            foreach ($item['addresses'] as $ip) {
                $old = $state['ports'][$ip] ?? ['healthy' => !$recovering, 'good' => 0, 'bad' => 0];
                if ($config['health_enabled']) {
                    $ok = $probes[$ip]['ok'] ?? false;
                    $old['good'] = $ok ? min(2, $old['good'] + 1) : 0;
                    $old['bad'] = $ok ? 0 : min(2, $old['bad'] + 1);
                    if ($old['bad'] >= 2) $old['healthy'] = false;
                    if ($old['good'] >= 2) $old['healthy'] = true;
                    $old['rtt_ms'] = $probes[$ip]['rtt_ms'] ?? null;
                } else $old = ['healthy' => true, 'good' => 0, 'bad' => 0];
                $ports[$ip] = $old;
                if ($old['healthy']) $addresses[] = $ip;
            }
            $reason = $item['reason'];
            if ($reason === 'healthy' && !$addresses) $reason = 'port_failed';
            $latency = $node && $target ? SchedulingProbeService::result($node, $target) : null;
            if (!$config['health_enabled'] && $reason === 'healthy' && in_array($config['strategy'], ['latency', 'latency_balanced'], true)) {
                if (!LandingPoolService::compatibleAgent($node)) $reason = 'agent_required';
                elseif (!$latency) $reason = 'probe_pending';
                elseif (!$latency['healthy']) $reason = 'latency_failed';
            }
            $traffic = $node?->machine ? SchedulingTraffic::view($node->machine, $lite[$node->machine_id] ?? null)
                : ['remaining_percent' => null, 'reset_at' => null, 'traffic_status' => 'unavailable', 'traffic_source' => null];
            $ratio = $traffic['remaining_percent']; $resetAt = $traffic['reset_at'];
            $wasLow = ($state['traffic_source'] ?? 'local') === $traffic['traffic_source'] && ($state['quota_low'] ?? false);
            $low = $config['quota_enabled'] && ($ratio !== null
                ? $ratio < ($config['quota_percent'] + ($wasLow ? 5 : 0))
                : ($traffic['traffic_status'] === 'unavailable' && $wasLow));
            $state = [...$traffic, 'ports' => $ports, 'reason' => $reason, 'quota_low' => $low,
                'links' => $state['links'] ?? [],
                'load' => $config['dynamic_enabled'] ? FrontDynamicLoad::sample($member, $config) : [],
                'remaining_percent' => $ratio === null ? null : round($ratio, 1),
                'latency_group' => $state['latency_group'] ?? null,
                'latency_ms' => $latency && $latency['healthy'] ? $latency['rtt_ms'] : null];
            $member->scheduling_state = $state;
            $member->healthy_addresses = in_array($reason, ['healthy', 'probe_pending'], true) ? $addresses : [];
            $member->checked_at = now(); $member->save();
            $snapshot[$id] = ['node_id' => $member->source_node_id, 'name' => $node?->name ?? '', 'reason' => $reason, 'quota_low' => $low, 'weight' => (int) ($member->weight ?: 1)];
            // 测速尚未就绪时暂保留可达入口，所有测速失效时只沿用端口健康成员，不回落失效端口。
            if (in_array($reason, ['healthy', 'probe_pending'], true)) $eligible[$id] = [...$item, 'addresses' => $addresses, 'low' => $low,
                'ratio' => $ratio ?? ($traffic['traffic_status'] === 'unlimited' ? 101 : -1),
                'reset_at' => $resetAt, 'latency' => $latency];
        }
        // 线路健康、运营商和额度需要在同一候选范围内判断，保留所有端口健康成员供后续筛选。
        $available = array_map(fn ($item) => self::candidate($item), array_values($eligible));
        if ($config['quota_enabled'] && $eligible) {
            $enough = array_filter($eligible, fn ($item) => !$item['low']);
            if ($enough) $eligible = $enough;
            else {
                $best = max(array_column($eligible, 'ratio'));
                $eligible = array_filter($eligible, fn ($item) => $item['ratio'] === $best);
            }
        }
        if ($config['strategy'] === 'cycle' && $eligible) {
            $usable = array_filter($eligible, fn ($item) => $item['ratio'] != 0);
            if ($usable) $eligible = $usable;
        }
        // 链路与运营商筛选必须先于优先级，否则主线路故障时无法选择低优先级备用。
        if ($eligible) {
            $priority = min(array_map(fn ($item) => $item['member']->priority, $eligible));
            $eligible = array_filter($eligible, fn ($item) => $item['member']->priority === $priority);
        }
        if ($config['strategy'] === 'cycle' && $eligible) {
            // 同优先级先消耗最近重置且尚有可用配额的成员；无限流量仅作兜底。
            $metered = array_filter($eligible, fn ($item) => $item['reset_at'] !== null && $item['ratio'] > 0);
            if ($metered) {
                $next = min(array_column($metered, 'reset_at'));
                $eligible = array_filter($metered, fn ($item) => $item['reset_at'] === $next);
            }
        }
        if (!$config['health_enabled'] && $config['strategy'] === 'latency_balanced') {
            $measured = array_filter($eligible, fn ($item) => $item['latency'] && $item['latency']['healthy']);
            if ($measured) {
                $best = min(array_map(fn ($item) => $item['latency']['rtt_ms'], $measured));
                $eligible = [];
                foreach ($measured as $id => $item) {
                    $member = $item['member']; $memberState = $member->scheduling_state;
                    $wanted = $item['latency']['rtt_ms'] <= $best + max(10, $best * 0.15);
                    $group = $memberState['latency_group'] ?? ['included' => $wanted, 'pending' => $wanted, 'samples' => 0, 'sample_at' => null];
                    if ($group['pending'] !== $wanted) $group = [...$group, 'pending' => $wanted, 'samples' => 0, 'sample_at' => null];
                    if ($group['sample_at'] !== $item['latency']['checked_at']) { $group['samples'] = min(2, $group['samples'] + 1); $group['sample_at'] = $item['latency']['checked_at']; }
                    if ($group['samples'] >= 2) $group['included'] = $wanted;
                    $member->update(['scheduling_state' => [...$memberState, 'latency_group' => $group]]);
                    if ($group['included']) $eligible[$id] = $item;
                }
                // 新的最佳成员尚在确认窗口时保留至少一台实测可用入口。
                if (!$eligible) {
                    uasort($measured, fn ($a, $b) => $a['latency']['rtt_ms'] <=> $b['latency']['rtt_ms']);
                    $id = array_key_first($measured); $eligible[$id] = $measured[$id];
                }
            }
        }
        $state = $pool->scheduling_state ?? [];
        if (!$config['health_enabled'] && $config['strategy'] === 'latency') {
            $measured = array_filter($eligible, fn ($item) => $item['latency'] && $item['latency']['healthy']);
            if ($measured) {
                uasort($measured, fn ($a, $b) => $a['latency']['rtt_ms'] <=> $b['latency']['rtt_ms']);
                $best = array_key_first($measured); $current = $state['winner'] ?? null;
                if ($current && isset($measured[$current]) && $current !== $best) {
                    $old = $measured[$current]['latency']['rtt_ms']; $new = $measured[$best]['latency']['rtt_ms'];
                    $better = $old - $new >= 10 && $new <= $old * 0.85;
                    $sample = $measured[$best]['latency']['checked_at'];
                    if (!$better) { $best = $current; $state['pending'] = null; $state['samples'] = 0; }
                    else {
                        if (($state['pending'] ?? null) !== $best) $state = [...$state, 'pending' => $best, 'samples' => 0, 'sample_at' => null];
                        if (($state['sample_at'] ?? null) !== $sample) { $state['samples']++; $state['sample_at'] = $sample; }
                        if ($state['samples'] < 2) $best = $current;
                    }
                } else { $state['pending'] = null; $state['samples'] = 0; }
                $state['winner'] = $best; $eligible = [$best => $measured[$best]];
            } else { $state['winner'] = null; $state['pending'] = null; $state['samples'] = 0; }
        } else $state = [];
        $addresses = []; $candidates = [];
        foreach ($eligible as $id => $item) {
            $addresses = array_merge($addresses, $item['addresses']); $snapshot[$id]['selected'] = true;
            $candidates[] = self::candidate($item);
        }
        $addresses = array_values(array_unique($addresses)); sort($addresses);
        $state['candidates'] = $candidates;
        $state['available_candidates'] = $available;
        $state['snapshot'] = ['addresses' => $addresses, 'members' => array_values($snapshot)];
        $state['checked_at'] = now()->timestamp;
        return [$addresses, $state];
    }

    private static function candidate(array $item): array
    {
        $member = $item['member'];
        return ['member_id' => (int) $member->id, 'node_id' => (int) $member->source_node_id,
            'priority' => (int) $member->priority, 'weight' => (int) ($member->weight ?: 1), 'addresses' => $item['addresses'],
            'carriers' => $member->carriers ?: ['default'], 'load' => $member->scheduling_state['load'] ?? [],
            'reset_at' => $item['reset_at'], 'ratio' => $item['ratio'], 'quota_low' => $item['low']];
    }
}
