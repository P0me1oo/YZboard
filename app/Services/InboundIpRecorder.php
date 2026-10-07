<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class InboundIpRecorder
{
    /** 同一用户的同一完整 IP 每天一行；重试、乱序和跨节点重复只扩展观察时间。 */
    public function record(array $users, ?int $receivedAt = null): void
    {
        $at = $receivedAt ?? now()->timestamp;
        $day = CarbonImmutable::createFromTimestamp($at, config('app.timezone'))->startOfDay()->timestamp;
        if ($day < now()->startOfDay()->subDays(29)->timestamp || $at > now()->timestamp) return;
        $rows = [];
        $addresses = [];
        foreach ($users as $key => $ips) {
            $userId = filter_var($key, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4294967295]]);
            if ($userId === false || !is_array($ips)) continue;
            foreach ($ips as $raw) {
                if (!is_string($raw)) continue;
                $ip = PublicDeviceIp::normalize($raw);
                if ($ip === null || DeviceIpExclusion::countKey($ip) === null) continue;
                $binary = inet_pton($ip);
                $addresses[$ip] = ['ip' => $ip, 'ip_key' => str_pad(bin2hex($binary), 32, '0', STR_PAD_LEFT),
                    'ip_version' => strlen($binary) === 4 ? 4 : 6];
                $rows[$userId . ':' . $ip] = ['user_id' => $userId, 'ip' => $ip, 'record_at' => $day,
                    'first_seen_at' => $at, 'last_seen_at' => $at];
            }
        }
        if ($rows === []) return;
        $mysql = DB::connection()->getDriverName() === 'mysql';
        $sqlite = DB::connection()->getDriverName() === 'sqlite';
        $first = $mysql ? 'VALUES(first_seen_at)' : 'excluded.first_seen_at';
        $last = $mysql ? 'VALUES(last_seen_at)' : 'excluded.last_seen_at';
        $table = 'v2_stat_user_inbound_ip';
        DB::transaction(function () use ($rows, $addresses, $sqlite, $first, $last, $table): void {
            foreach (array_chunk(array_values($addresses), 128) as $chunk) DB::table('v2_inbound_ip')->insertOrIgnore($chunk);
            foreach (array_chunk(array_values($rows), 128) as $chunk) {
                DB::table($table)->upsert($chunk, ['user_id', 'ip', 'record_at'], [
                    'first_seen_at' => DB::raw(($sqlite ? 'MIN' : 'LEAST') . "($table.first_seen_at, $first)"),
                    'last_seen_at' => DB::raw(($sqlite ? 'MAX' : 'GREATEST') . "($table.last_seen_at, $last)"),
                ]);
            }
        }, 3);
    }
}
