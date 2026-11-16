<?php

namespace App\Services;

use App\Models\Server;
use App\Utils\CacheKey;
use Illuminate\Support\Facades\Cache;

/** 在线来源按完整公网 IP 去重，不使用账号设备数或连接条数。 */
class FrontSourceIpLoad
{
    public static function record(int $nodeId, array $alive, ?int $receivedAt = null): void
    {
        $at = $receivedAt ?? now()->timestamp;
        $key = CacheKey::get('SERVER_RAW_SOURCE_IPS', $nodeId);
        if ((Cache::get($key, [])['updated_at'] ?? 0) > $at) return;
        $ips = [];
        foreach ($alive as $userId => $sources) {
            if (!is_numeric($userId) || !is_array($sources)) continue;
            foreach ($sources as $source) {
                if (is_string($source) && ($ip = PublicDeviceIp::normalize($source)) !== null) $ips[$ip] = true;
            }
        }
        Cache::put($key, ['ips' => array_keys($ips), 'updated_at' => $at], max(300, self::freshSeconds()));
    }

    /** 一台服务器的多个独立入口共用 IP 集合，内部落地和共享子节点不重复计数。 */
    public static function machines(array $machineIds): array
    {
        if (!$machineIds) return [];
        $rows = [];
        $nodes = Server::whereIn('machine_id', $machineIds)->whereNull('parent_id')
            ->whereNull('relay_entry_id')->whereNull('relay_pool_id')->get(['id', 'machine_id', 'type']);
        foreach ($nodes as $node) {
            $heartbeat = LoadBalancerService::heartbeat($node);
            if ($heartbeat === null || $heartbeat < now()->timestamp - self::freshSeconds()) continue;
            $sample = Cache::get(CacheKey::get('SERVER_RAW_SOURCE_IPS', $node->id), []);
            $at = $sample['updated_at'] ?? null;
            $valid = is_array($sample['ips'] ?? null) && is_numeric($at);
            $fresh = $valid && $at >= now()->timestamp - self::freshSeconds() && $at <= now()->timestamp + 10;
            $row = $rows[$node->machine_id] ?? ['ips' => [], 'fresh' => true, 'updated_at' => null, 'known' => false];
            $row['fresh'] = $row['fresh'] && $fresh;
            if ($valid) {
                foreach ($sample['ips'] as $ip) $row['ips'][$ip] = true;
                $row['known'] = true;
                $row['updated_at'] = $row['updated_at'] === null ? (int) $at : min($row['updated_at'], (int) $at);
            }
            $rows[$node->machine_id] = $row;
        }
        return array_map(fn ($row) => ['count' => $row['known'] ? count($row['ips']) : null,
            'fresh' => $row['fresh'], 'updated_at' => $row['updated_at']], $rows);
    }

    private static function freshSeconds(): int
    {
        return max(120, (int) admin_setting('server_push_interval', 60) * 3);
    }

    public static function eligible(array $candidates, array $config): array
    {
        if (!$config['source_ip_enabled'] || !$candidates) return $candidates;
        $usable = array_filter($candidates, fn ($c) => ($c['traffic_status'] ?? null) !== 'available'
            || !is_numeric($c['traffic_remaining'] ?? null) || $c['traffic_remaining'] > 0);
        if ($usable) $candidates = array_values($usable);
        $known = array_filter($candidates, fn ($c) => !empty($c['source_ip']['fresh']) && is_int($c['source_ip']['count'] ?? null));
        $free = array_filter($known, fn ($c) => $c['source_ip']['count'] < $config['source_ip_threshold']);
        if ($free) return array_values($free);
        if (!$known) return $candidates;
        // 全满时保留现有健康分配，新分配再从最少在线 IP 的入口中选择。
        return array_values($known);
    }

    public static function hasFree(array $candidates, array $config): bool
    {
        foreach ($candidates as $candidate) {
            if (!empty($candidate['source_ip']['fresh']) && is_int($candidate['source_ip']['count'] ?? null)
                && $candidate['source_ip']['count'] < $config['source_ip_threshold']) return true;
        }
        return false;
    }

    public static function policy(array $candidates, array $config): array
    {
        if (!$candidates) return [];
        if (!self::hasFree($candidates, $config)) {
            $known = array_filter($candidates, fn ($c) => !empty($c['source_ip']['fresh']) && is_int($c['source_ip']['count'] ?? null));
            if ($known) {
                $least = min(array_map(fn ($c) => $c['source_ip']['count'], $known));
                $candidates = array_values(array_filter($known, fn ($c) => $c['source_ip']['count'] === $least));
            }
        }
        $candidates = FrontRouteHealth::prioritize($candidates);
        $unlimited = array_filter($candidates, fn ($c) => ($c['traffic_status'] ?? null) === 'unlimited');
        $metered = array_filter($candidates, fn ($c) => ($c['traffic_status'] ?? null) === 'available'
            && is_numeric($c['traffic_remaining'] ?? null) && $c['traffic_remaining'] > 0);
        if ($config['source_ip_strategy'] === 'cycle') {
            $dated = array_filter($metered, fn ($c) => is_numeric($c['reset_at'] ?? null) && $c['reset_at'] > now()->timestamp);
            if ($dated) {
                $next = min(array_column($dated, 'reset_at'));
                $metered = array_filter($dated, fn ($c) => $c['reset_at'] === $next);
            } else {
                if ($unlimited) return array_values($unlimited);
                $metered = [];
            }
        } else {
            if ($unlimited) return array_values($unlimited);
        }
        if ($metered) {
            $most = max(array_column($metered, 'traffic_remaining'));
            return array_values(array_filter($metered, fn ($c) => $c['traffic_remaining'] == $most));
        }
        // 无有效重置时间或额度数据时沿用健康候选及原权重，不猜测缺失数据。
        return $candidates;
    }

    public static function choose(string $label, array $candidates, array $previous, array $config): array
    {
        $current = $previous['member_id'] ?? null;
        $next = $current && in_array($current, array_column($candidates, 'member_id'), true)
            ? $current : FrontendAssignment::choose($label, self::policy($candidates, $config));
        return ['member_id' => $next, 'moved_at' => $next !== $current ? now()->timestamp : ($previous['moved_at'] ?? now()->timestamp)];
    }
}
