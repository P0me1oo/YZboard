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
        $normalized = [];
        foreach ($users as $key => $ips) {
            $userId = filter_var($key, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4294967295]]);
            if ($userId === false || !is_array($ips)) continue;
            foreach ($ips as $raw) {
                if (!is_string($raw)) continue;
                if (!array_key_exists($raw, $normalized)) {
                    $ip = PublicDeviceIp::normalize($raw);
                    $normalized[$raw] = $ip !== null && DeviceIpExclusion::countKey($ip) !== null ? $ip : null;
                }
                $ip = $normalized[$raw];
                if ($ip === null) continue;
                $binary = inet_pton($ip);
                $addresses[$ip] = ['ip' => $ip, 'ip_key' => str_pad(bin2hex($binary), 32, '0', STR_PAD_LEFT),
                    'ip_version' => strlen($binary) === 4 ? 4 : 6];
                $rows[$userId . ':' . $ip] = ['user_id' => $userId, 'ip' => $ip, 'record_at' => $day,
                    'first_seen_at' => $at, 'last_seen_at' => $at];
            }
        }
        if ($rows === []) return;
        $mysql = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
        $sqlite = DB::connection()->getDriverName() === 'sqlite';
        $first = $mysql ? 'VALUES(first_seen_at)' : 'excluded.first_seen_at';
        $last = $mysql ? 'VALUES(last_seen_at)' : 'excluded.last_seen_at';
        $table = 'v2_stat_user_inbound_ip';
        DB::transaction(function () use ($rows, $addresses, $mysql, $sqlite, $first, $last, $table): void {
            // 其他驱动沿用原写入路径，避免依赖其外连接锁定语法。
            if (!$mysql && !$sqlite) {
                foreach (array_chunk(array_values($addresses), 128) as $chunk) DB::table('v2_inbound_ip')->insertOrIgnore($chunk);
                foreach (array_chunk(array_values($rows), 128) as $chunk) {
                    DB::table($table)->upsert($chunk, ['user_id', 'ip', 'record_at'], [
                        'first_seen_at' => DB::raw("LEAST($table.first_seen_at, $first)"),
                        'last_seen_at' => DB::raw("GREATEST($table.last_seen_at, $last)"),
                    ]);
                }
                return;
            }
            // 固定顺序锁定本次实际涉及的地址和历史，避免读后清理导致跳过补建。
            // 不保存跨调用命中状态：清理、恢复或事务回滚后下一次仍以数据库为准。
            uasort($rows, fn ($a, $b) => [$a['ip'], $a['user_id']] <=> [$b['ip'], $b['user_id']]);
            foreach (array_chunk(array_values($rows), 256) as $chunk) {
                $pairs = [];
                foreach ($chunk as $row) {
                    $pairs[$row['ip']][] = $row['user_id'];
                }
                $existing = DB::table('v2_inbound_ip as address')
                    ->leftJoin($table . ' as history', function ($join) use ($pairs, $chunk): void {
                        $join->on('history.ip', '=', 'address.ip')->where('history.record_at', $chunk[0]['record_at'])
                            ->where(function ($query) use ($pairs): void {
                                foreach ($pairs as $ip => $userIds) {
                                    $query->orWhere(fn ($pair) => $pair->where('history.ip', $ip)->whereIn('history.user_id', $userIds));
                                }
                            });
                    })->whereIn('address.ip', array_keys($pairs))
                    ->select('history.user_id', 'address.ip',
                        'history.first_seen_at', 'history.last_seen_at')
                    ->orderBy('address.ip')->orderBy('history.user_id')->lockForUpdate()->get();
                $knownAddresses = $existing->keyBy('ip');
                $history = $existing->keyBy(fn ($row) => $row->user_id . ':' . $row->ip);
                $missing = [];
                $changed = [];
                foreach ($chunk as $row) {
                    $known = $history[$row['user_id'] . ':' . $row['ip']] ?? null;
                    if (!isset($knownAddresses[$row['ip']])) $missing[$row['ip']] = $addresses[$row['ip']];
                    if ($known === null || $row['first_seen_at'] < $known->first_seen_at
                        || $row['last_seen_at'] > $known->last_seen_at) {
                        $changed[] = $row;
                    }
                }
                foreach (array_chunk(array_values($missing), 128) as $insert) DB::table('v2_inbound_ip')->insertOrIgnore($insert);
                foreach (array_chunk($changed, 128) as $update) {
                    DB::table($table)->upsert($update, ['user_id', 'ip', 'record_at'], [
                        'first_seen_at' => DB::raw(($sqlite ? 'MIN' : 'LEAST') . "($table.first_seen_at, $first)"),
                        'last_seen_at' => DB::raw(($sqlite ? 'MAX' : 'GREATEST') . "($table.last_seen_at, $last)"),
                    ]);
                }
            }
        }, 3);
    }
}
