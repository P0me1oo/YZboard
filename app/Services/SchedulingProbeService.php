<?php

namespace App\Services;

use App\Models\BalancerPool;
use App\Models\LandingPool;
use App\Models\Server;
use Illuminate\Support\Facades\Cache;

/** 探测地址只由面板已有拓扑生成，不接受节点自行指定目标。 */
class SchedulingProbeService
{
    public static function target(Server $node): ?array
    {
        $port = LoadBalancerService::publicPort($node);
        if (!$port || !in_array($node->type, ['shadowsocks', 'vless'], true)) return null;
        if ($node->type === 'vless' && in_array(ServerRelayService::normalizeVlessNetwork(data_get($node->protocol_settings, 'network', 'tcp')), ['kcp', 'hysteria'], true)) return null;
        $address = trim($node->host, '[] ');
        if ($address === '' || str_contains($address, '/')) return null;
        return ['node_id' => (int) $node->id, 'address' => $address, 'port' => $port,
            'token' => hash('sha256', $node->id . '|' . $address . '|' . $port)];
    }

    public static function targets(Server $entry, bool $cached = true): array
    {
        if (!ServerRelayService::isSupportedEntry($entry)) return [];
        if ($cached) return Cache::remember('scheduling:targets:' . $entry->id, 10, fn () => self::targets($entry, false));
        $ids = [];
        foreach (BalancerPool::where('mode', 'dns')->where('enabled', true)->get() as $pool) {
            if (($pool->scheduling_config['strategy'] ?? 'priority') === 'latency'
                && $pool->members()->where('runtime_node_id', $entry->id)->exists()) $ids[] = (int) ($pool->scheduling_config['latency_node_id'] ?? 0);
        }
        foreach (LandingPool::with('node')->where('enabled', true)->get() as $pool) {
            if ($pool->node && ServerRelayService::ownsChild($entry, $pool->node)) $ids = array_merge($ids, $pool->members);
        }
        return Server::whereIn('id', array_unique($ids))->get()->map(fn ($node) => self::target($node))->filter()->values()->all();
    }

    public static function accept(Server $entry, mixed $rows): bool
    {
        if (!is_array($rows) || count($rows) > 256) return false;
        $changed = false;
        $allowed = collect(self::targets($entry))->keyBy('node_id');
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $target = $allowed->get($row['node_id'] ?? 0);
            $checked = $row['checked_at'] ?? null;
            if (!$target || !is_string($row['token'] ?? null) || !hash_equals($target['token'], $row['token'])
                || !is_bool($row['healthy'] ?? null) || !is_int($checked) || $checked > now()->timestamp + 10 || $checked < now()->timestamp - 90
                || !is_numeric($row['rtt_ms'] ?? null) || $row['rtt_ms'] < 0 || $row['rtt_ms'] > 10000) continue;
            $key = 'scheduling:probe:' . $entry->id . ':' . $target['node_id'];
            $previous = Cache::get($key);
            if ($previous && $previous['checked_at'] >= $row['checked_at']) continue;
            Cache::lock($key . ':lock', 5)->get(function () use ($key, $row, &$changed) {
                $previous = Cache::get($key);
                if ($previous && $previous['checked_at'] >= $row['checked_at']) return;
                Cache::put($key, array_intersect_key($row, array_flip(['node_id', 'token', 'healthy', 'rtt_ms', 'checked_at'])), 120);
                $changed = true;
            });
        }
        return $changed;
    }

    public static function result(Server $entry, Server $target): ?array
    {
        $expected = self::target($target);
        $result = Cache::get('scheduling:probe:' . $entry->id . ':' . $target->id);
        return $expected && $result && hash_equals($expected['token'], $result['token'])
            && $result['checked_at'] >= now()->timestamp - 90 ? $result : null;
    }
}
