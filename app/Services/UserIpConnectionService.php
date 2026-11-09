<?php

namespace App\Services;

use App\Models\Server;
use Illuminate\Support\Facades\Cache;

/** 用户详情的完整公网 IP 连接数；设备名额仍按原有规则计算。 */
class UserIpConnectionService
{
    public function replace(Server $node, ?array $counts): void
    {
        if ($node->isRelayChild() || $node->relay_pool_id) return;
        if ($counts === null) {
            Cache::forget($this->key((int) $node->id));
            return;
        }
        NodeStateValidator::validate(['ip_connection_counts' => $counts]);
        Cache::put($this->key((int) $node->id), $counts,
            max(60, (int) admin_setting('server_push_interval', 60) * 3));
    }

    /** 仅对已打开详情的用户读取；每个实际入口只汇总一次，中转落地不重复计入。 */
    public function forUsers(iterable $users, array $legacyIPs, ?array $runtime = null, ?iterable $nodes = null): array
    {
        $nodes ??= Server::query()->where(fn ($query) => $query->whereNull('relay_entry_id')->orWhere('relay_entry_id', 0))
            ->whereNull('relay_pool_id')->get(['id', 'group_ids', 'enabled']);
        $nodes = collect($nodes);
        try {
            $runtime ??= app(RealtimeStateStore::class)->readMany($nodes->map(fn ($node) => 'node:' . $node->id)->all());
            $keys = $nodes->filter(fn ($node) => ($runtime['node:' . $node->id] ?? null) === null)
                ->map(fn ($node) => $this->key((int) $node->id))->values()->all();
            $legacy = $keys === [] ? [] : Cache::many($keys);
        } catch (\Throwable) {
            $runtime = $legacy = [];
        }
        $result = [];
        foreach ($users as $user) {
            $ips = array_fill_keys($legacyIPs[$user->id] ?? [], true);
            $totals = [];
            $known = $missing = false;
            foreach ($nodes as $node) {
                $state = $runtime['node:' . $node->id] ?? null;
                $data = $state['data'] ?? [];
                $counts = $state === null ? ($legacy[$this->key((int) $node->id)] ?? null)
                    : (($state['fresh'] ?? false) ? ($data['ip_connection_counts'] ?? null) : null);
                $userCounts = $counts[$user->id] ?? [];
                $alive = ($state['fresh'] ?? false) ? ($data['alive'][$user->id] ?? []) : [];
                if ($userCounts === [] && $alive === [] && empty($data['connection_counts'][$user->id])
                    && ($node->enabled === false || !array_intersect($user->effectiveGroupIds(), $node->group_ids ?? []))) continue;
                if (is_array($counts)) {
                    $known = true;
                    foreach ($userCounts as $raw => $count) {
                        if (($ip = $this->publicIP((string) $raw)) !== null) {
                            $totals[$ip] = ($totals[$ip] ?? 0) + $count;
                            if ($count > 0) $ips[$ip] = true;
                        }
                    }
                } else {
                    $missing = true;
                }
                foreach ($alive as $raw) $ips[(string) $raw] = true;
            }
            $normalized = [];
            foreach (array_keys($ips) as $raw) {
                if (($ip = $this->publicIP((string) $raw)) !== null) $normalized[$ip] = true;
            }
            ksort($normalized, SORT_STRING);
            $result[$user->id] = [];
            foreach ($normalized as $ip => $_) {
                $result[$user->id][] = ['ip' => $ip, 'connection_count' => $known ? ($totals[$ip] ?? 0) : null,
                    'connection_count_partial' => $known && $missing];
            }
        }
        return $result;
    }

    private function publicIP(string $raw): ?string
    {
        return DeviceIpExclusion::countKey($raw) === null ? null : PublicDeviceIp::normalize($raw);
    }

    private function key(int $nodeId): string
    {
        return 'user_ip_connections:v1:node:' . $nodeId;
    }
}
