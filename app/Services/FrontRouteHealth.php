<?php

namespace App\Services;

use App\Models\BalancerPool;
use App\Models\LandingPool;
use App\Models\Server;

class FrontRouteHealth
{
    /** 每个前置、每条逻辑落地独立确认。缺测短暂保持，超时退出后必须稳定恢复。 */
    public static function update(array $old, ?array $probe, string $token): array
    {
        $now = now()->timestamp;
        if (($old['token'] ?? null) !== $token) $old = ['token' => $token, 'healthy' => !$old, 'started_at' => $now];
        if (!$probe) {
            $old['good'] = 0; $old['bad'] = 0; $old['good_since'] = null;
            if ($now - ($old['sample_at'] ?? $old['started_at']) > 180) $old['healthy'] = false;
            return [...$old, 'reason' => $old['healthy'] ? 'probe_pending' : 'probe_stale'];
        }
        if ($probe['checked_at'] <= ($old['sample_at'] ?? 0)) return $old;
        $continuous = $probe['checked_at'] - ($old['sample_at'] ?? $probe['checked_at']) <= 90;
        $ok = $probe['healthy'];
        $good = $ok ? ($continuous ? ($old['good'] ?? 0) : 0) + 1 : 0;
        $bad = $ok ? 0 : ($continuous ? ($old['bad'] ?? 0) : 0) + 1;
        $since = $ok ? ($continuous ? ($old['good_since'] ?? $probe['checked_at']) : $probe['checked_at']) : null;
        $healthy = $old['healthy'];
        if ($bad >= 2) $healthy = false;
        if ($good >= 2 && $probe['checked_at'] - $since >= 15) $healthy = true;
        return [...$old, 'healthy' => $healthy, 'good' => min(3, $good), 'bad' => min(3, $bad),
            'good_since' => $since, 'sample_at' => $probe['checked_at'], 'rtt_ms' => $ok ? $probe['rtt_ms'] : null,
            'reason' => $healthy ? 'healthy' : 'link_failed'];
    }

    public static function select(BalancerPool $pool, Server $route, array $candidates): array
    {
        $managed = LandingPool::where('node_id', $route->id)->where('enabled', true)->first();
        $selected = [];
        foreach ($candidates as $candidate) {
            $member = $pool->members->firstWhere('id', $candidate['member_id']);
            $entry = $member->runtimeNode;
            $target = $managed ? LandingPoolService::targetFor($route, $entry, $managed) : $route;
            $old = $member->scheduling_state ?? [];
            if ($route->enabled === false || !$target || $target->enabled === false || !$target->machine?->is_active || MachineTrafficService::blocked($target->machine)
                || $target->available_status === Server::STATUS_OFFLINE) $link = ['healthy' => false, 'reason' => 'offline'];
            elseif (!LandingPoolService::compatibleAgent($entry)) $link = ['healthy' => false, 'reason' => 'agent_required'];
            elseif ($probeTarget = SchedulingProbeService::target($target)) {
                $link = self::update($old['links'][$route->id] ?? [], SchedulingProbeService::result($entry, $target), $probeTarget['token']);
            } else $link = ['healthy' => true, 'reason' => 'heartbeat_only'];
            $old['links'][$route->id] = $link;
            $member->update(['scheduling_state' => $old]);
            if ($link['healthy']) $selected[] = [...$candidate, 'latency_ms' => $link['rtt_ms'] ?? null, 'sample_at' => $link['sample_at'] ?? null];
        }
        return $selected;
    }

    public static function prioritize(array $candidates): array
    {
        if (!$candidates) return [];
        $priority = min(array_column($candidates, 'priority'));
        return array_values(array_filter($candidates, fn ($c) => $c['priority'] === $priority));
    }

    public static function eligible(array $candidates, array $config): array
    {
        if ($config['quota_enabled'] && $candidates) {
            $enough = array_filter($candidates, fn ($c) => !$c['quota_low']);
            $best = max(array_column($candidates, 'ratio'));
            $candidates = $enough ?: array_filter($candidates, fn ($c) => $c['ratio'] === $best);
        }
        if (!$config['dynamic_enabled'] && $config['strategy'] === 'cycle') {
            $candidates = array_filter($candidates, fn ($c) => $c['ratio'] != 0) ?: $candidates;
        }
        return self::prioritize(array_values($candidates));
    }

    public static function policy(array $candidates, array $config, array &$state): array
    {
        if (!$candidates || $config['dynamic_enabled']) return $candidates;
        if ($config['strategy'] === 'cycle') {
            $metered = array_filter($candidates, fn ($c) => $c['reset_at'] !== null && $c['ratio'] > 0);
            if ($metered) {
                $next = min(array_column($metered, 'reset_at'));
                return array_values(array_filter($metered, fn ($c) => $c['reset_at'] === $next));
            }
        }
        if (!in_array($config['strategy'], ['latency', 'latency_balanced'], true)) return $candidates;
        $measured = array_filter($candidates, fn ($c) => isset($c['latency_ms']));
        if (!$measured) return $candidates;
        usort($measured, fn ($a, $b) => $a['latency_ms'] <=> $b['latency_ms']);
        $best = $measured[0];
        if ($config['strategy'] === 'latency_balanced') {
            $selected = [];
            foreach ($measured as $candidate) {
                $id = $candidate['member_id'];
                $wanted = $candidate['latency_ms'] <= $best['latency_ms'] + max(10, $best['latency_ms'] * 0.15);
                $group = $state[$id] ?? ['included' => $wanted, 'pending' => $wanted, 'samples' => 0];
                if ($group['pending'] !== $wanted) $group = [...$group, 'pending' => $wanted, 'samples' => 0];
                if (($group['sample_at'] ?? null) !== $candidate['sample_at']) $group['samples']++;
                $group['sample_at'] = $candidate['sample_at'];
                if ($group['samples'] >= 2) $group['included'] = $wanted;
                $state[$id] = $group;
                if ($group['included']) $selected[] = $candidate;
            }
            return $selected ?: [$best];
        }
        $current = collect($measured)->firstWhere('member_id', $state['winner'] ?? null);
        if ($current && $current['member_id'] !== $best['member_id']) {
            $better = $current['latency_ms'] - $best['latency_ms'] >= 10 && $best['latency_ms'] <= $current['latency_ms'] * 0.85;
            if (!$better) { $state['pending'] = null; $state['samples'] = 0; return [$current]; }
            if (($state['pending'] ?? null) !== $best['member_id']) $state = [...$state, 'pending' => $best['member_id'], 'samples' => 0, 'sample_at' => null];
            if ($state['sample_at'] !== $best['sample_at']) $state['samples']++;
            $state['sample_at'] = $best['sample_at'];
            if ($state['samples'] < 2) return [$current];
        }
        $state['winner'] = $best['member_id'];
        return [$best];
    }
}
