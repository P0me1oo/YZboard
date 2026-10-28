<?php

namespace App\Services;

use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UserEventLog
{
    public const RETENTION_DAYS = 30;

    public function account(User $user, string $event, string $result): void
    {
        // 日志写入失败不改变登录和改密的结果，也不记录密码、令牌或请求正文。
        try {
            if (!LogSettings::enabled('user')) return;
            $ip = request()?->ip();
            DB::table('v2_user_event_log')->insert([
                'user_id' => $user->id, 'event' => $event, 'result' => $result,
                'source_ip' => filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null,
                'count' => 1, 'occurred_at' => now()->timestamp, 'last_at' => now()->timestamp,
            ]);
        } catch (\Throwable) {
            try { \Log::warning('用户事件日志保存失败'); } catch (\Throwable) {}
        }
    }

    public function limits(Server $node, array $events, ?string $reportId): void
    {
        [$enabled, $cutoff] = LogSettings::recording('user');
        if (!$enabled) return;
        $users = User::whereIn('id', array_column($events, 'user_id'))->pluck('id')->flip();
        $rows = [];
        foreach ($events as $event) {
            if ($event['count'] <= 0 || $event['limit'] <= 0 || !$users->has($event['user_id'])) continue;
            $snapshot = $event['snapshot'] ?? [];
            $at = (int) ($snapshot['occurred_at'] ?? $event['occurred_at'] ?? now()->timestamp);
            if ($at < $cutoff) continue;
            $kind = $event['kind'];
            $key = $reportId ? hash('sha256', $node->id . '|' . $reportId . '|' . $event['user_id'] . '|' . $kind) : null;
            $rows[] = [
                'event_key' => $key, 'user_id' => $event['user_id'], 'node_id' => $node->id,
                'machine_id' => $node->machine_id, 'node_name' => $node->name,
                'event' => ['conn' => 'connection_limit', 'rate' => 'connection_rate', 'device' => 'device_limit', 'speed' => 'speed_limit'][$kind],
                'result' => $kind === 'speed' ? 'throttled' : 'rejected',
                'source_ip' => $snapshot['source_ip'] ?? null,
                'count' => min(PHP_INT_MAX, $event['count']), 'limit_value' => $event['limit'],
                'observed' => $snapshot['observed'] ?? $event['observed'],
                'occurred_at' => $at, 'last_at' => min(now()->timestamp, max($at, (int) ($event['last_at'] ?? $at))),
            ];
        }
        foreach (array_chunk($rows, 100) as $chunk) DB::table('v2_user_event_log')->insertOrIgnore($chunk);
    }
}
