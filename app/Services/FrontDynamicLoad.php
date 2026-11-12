<?php

namespace App\Services;

use App\Models\BalancerMember;
use App\Utils\CacheKey;
use Illuminate\Support\Facades\Cache;

/** 带宽和连接数均换算成相对阈值的压力，混合配置不直接比较不同单位。 */
class FrontDynamicLoad
{
    public static function sample(BalancerMember $member, array $config): array
    {
        $node = $member->runtimeNode;
        $metrics = $node ? Cache::get(CacheKey::get('SERVER_' . strtoupper($node->type) . '_METRICS', $node->id), []) : [];
        $bandwidth = (float) $member->bandwidth_mbps;
        $kind = $bandwidth > 0 ? 'bandwidth' : 'connections';
        $signature = $kind . ':' . $bandwidth . ':' . $config['bandwidth_threshold'] . ':' . $config['connection_threshold'];
        $old = $member->scheduling_state['load'] ?? [];
        if (($old['signature'] ?? null) !== $signature) $old = [];
        $at = $metrics['updated_at'] ?? 0;
        $valid = is_numeric($at) && $at >= now()->timestamp - 120 && $at <= now()->timestamp + 10;
        $fields = $bandwidth > 0 ? ['inbound_speed', 'outbound_speed'] : ['active_connections'];
        foreach ($fields as $field) {
            if (!is_numeric($metrics[$field] ?? null) || $metrics[$field] < 0 || !is_finite((float) $metrics[$field])) $valid = false;
        }
        $state = [...$old, 'signature' => $signature, 'kind' => $kind, 'fresh' => $valid];
        if (!$valid) return [...$state, 'pending_since' => null, 'samples' => 0, 'overloaded' => false];
        if ($at <= ($old['sample_at'] ?? 0)) return $state;
        // 前置转发的双向数据不能相加，否则同一条流量可能被重复计算。
        $value = $bandwidth > 0 ? max($metrics['inbound_speed'], $metrics['outbound_speed']) * 8 / ($bandwidth * 1000000) * 100
            : (float) $metrics['active_connections'];
        $threshold = $bandwidth > 0 ? $config['bandwidth_threshold'] : $config['connection_threshold'];
        $continuous = $at - ($old['sample_at'] ?? 0) <= 120;
        $smooth = $continuous && isset($old['value']) ? $old['value'] * 0.7 + $value * 0.3 : $value;
        $pressure = $smooth / $threshold;
        $since = $pressure >= 1 ? ($continuous ? ($old['pending_since'] ?? $at) : $at) : null;
        $samples = $since !== null ? ($continuous ? ($old['samples'] ?? 0) : 0) + 1 : 0;
        return [...$state, 'value' => $smooth, 'pressure' => $pressure, 'sample_at' => (int) $at,
            'pending_since' => $since, 'samples' => $samples,
            'overloaded' => $samples >= 3 && $at - $since >= $config['load_confirm_seconds']];
    }

    public static function choose(string $label, array $candidates, array $previous, array $config, int &$moveBudget): array
    {
        $byId = array_column($candidates, null, 'member_id');
        $current = $previous['member_id'] ?? null;
        $dynamic = $config['dynamic_enabled'];
        $fresh = $candidates && !array_filter($candidates, fn ($c) => empty($c['load']['fresh']));
        $weighted = $candidates;
        if ($dynamic && $fresh) {
            foreach ($weighted as &$candidate) $candidate['weight'] = max(0.05, 1 - min(0.95, $candidate['load']['pressure']));
            unset($candidate);
        }
        if (!$dynamic) $next = FrontendAssignment::choose($label, $candidates);
        elseif (!$current || !isset($byId[$current])) $next = FrontendAssignment::choose($label, $weighted);
        else {
            $next = $current;
            // 缺测冻结健康成员的既有分配；健康故障仍由候选集移除，直接转移。
            if ($fresh && !empty($byId[$current]['load']['overloaded']) && $moveBudget > 0
                && now()->timestamp - ($previous['moved_at'] ?? 0) >= $config['load_cooldown_seconds']) {
                $better = array_filter($weighted, fn ($c) => $c['load']['pressure'] <= $byId[$current]['load']['pressure'] - 0.15);
                if ($better) { $next = FrontendAssignment::choose($label, $better); $moveBudget--; }
            }
        }
        return ['member_id' => $next, 'moved_at' => $next !== $current ? now()->timestamp : ($previous['moved_at'] ?? now()->timestamp)];
    }
}
