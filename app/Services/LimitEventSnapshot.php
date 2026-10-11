<?php

namespace App\Services;

class LimitEventSnapshot
{
    /** 快照只接收固定字段，不把节点原始载荷写入提醒记录。 */
    public static function normalize(mixed $input): ?array
    {
        if (!is_array($input)) return null;
        $at = filter_var($input['occurred_at'] ?? null, FILTER_VALIDATE_INT);
        $source = self::address($input['source_ip'] ?? null);
        $limit = filter_var($input['limit'] ?? null, FILTER_VALIDATE_INT);
        $observed = filter_var($input['observed'] ?? null, FILTER_VALIDATE_INT);
        if (!$at || $at < 1 || $at > now()->timestamp + 60 || $source === null || !$limit || $limit < 0 || $observed === false || $observed < 0) return null;
        $active = [];
        foreach (is_array($input['active_ips'] ?? null) ? $input['active_ips'] : [] as $raw) {
            $ip = self::address($raw, true);
            if ($ip !== null && !in_array($ip, $active, true)) $active[] = $ip;
            if (count($active) >= 5) break;
        }
        $known = ($input['active_known'] ?? false) === true;
        return [
            'occurred_at' => $at, 'source_ip' => $source, 'limit' => $limit, 'observed' => $observed,
            'active_ips' => $known ? $active : [], 'active_known' => $known,
            'active_total' => $known ? max(count($active), min(1000000, (int) ($input['active_total'] ?? 0))) : 0,
        ];
    }

    private static function address(mixed $raw, bool $allowPrefix = false): ?string
    {
        if (!is_string($raw)) return null;
        $prefix = $allowPrefix && str_ends_with($raw, '/64');
        $ip = $prefix ? substr($raw, 0, -3) : $raw;
        if (filter_var($ip, FILTER_VALIDATE_IP) === false || ($prefix && !str_contains($ip, ':'))) return null;
        $binary = inet_pton($ip);
        if ($prefix) $binary = substr($binary, 0, 8) . str_repeat("\0", 8);
        return inet_ntop($binary) . ($prefix ? '/64' : '');
    }
}
