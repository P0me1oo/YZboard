<?php

namespace App\Services;

use App\Models\ServerMachine;

/** 调度只投影流量来源，不修改本地流量保护和扣费数据。 */
final class SchedulingTraffic
{
    public static function view(ServerMachine $machine, ?array $lite): array
    {
        if ($lite === null) {
            $traffic = MachineTrafficService::view($machine);
            return ['traffic_source' => 'local', 'traffic_status' => $traffic['limit'] > 0 ? 'available' : 'unlimited',
                'traffic_limit' => $traffic['limit'], 'traffic_used' => $traffic['used'],
                'remaining_percent' => $traffic['limit'] > 0 ? max(0, ($traffic['limit'] - $traffic['used']) / $traffic['limit'] * 100) : null,
                'reset_at' => $traffic['limit'] > 0 ? (int) $traffic['period_end'] : null,
                'reset_timezone' => config('app.timezone')];
        }

        $view = ['traffic_source' => 'lite', 'traffic_status' => 'unavailable', 'traffic_limit' => null,
            'traffic_used' => null, 'remaining_percent' => null, 'reset_at' => null, 'reset_timezone' => null];
        if (!empty($lite['error']) || !empty($lite['missing']) || empty($lite['fresh']) || ($lite['online'] ?? null) !== true) return $view;
        $traffic = $lite['traffic'] ?? [];
        $limit = LiteMonitor::number($traffic['limit'] ?? null);
        $used = LiteMonitor::number($traffic['used'] ?? null);
        if ($limit === null || $used === null) return $view;
        $reset = $traffic['reset_at'] ?? null;
        // Lite 提供带偏移量的绝对时间；不使用面板时区猜测月份、月底或夏令时。
        $resetAt = is_string($reset) && preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})\z/', $reset)
            ? strtotime($reset) : false;
        if ($resetAt !== false && $resetAt <= now()->timestamp) return $view;
        $timezone = $traffic['timezone'] ?? null;
        try { if (!is_string($timezone) || $timezone === '') $timezone = null; else new \DateTimeZone($timezone); }
        catch (\Throwable) { $timezone = null; }
        return array_replace($view, ['traffic_status' => $limit > 0 ? 'available' : 'unlimited',
            'traffic_limit' => $limit, 'traffic_used' => $used,
            'remaining_percent' => $limit > 0 ? max(0, ($limit - $used) / $limit * 100) : null,
            'reset_at' => $limit > 0 && $resetAt !== false ? $resetAt : null, 'reset_timezone' => $timezone]);
    }
}
