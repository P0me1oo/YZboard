<?php

namespace App\Services;

use App\Models\BalancerPool;
use App\Models\Server;
use Illuminate\Support\Facades\Cache;

/** 来源必须同时通过当前拓扑、逐目标选路和认证机器出口核对。 */
final class RelayFirewallService
{
    public const REPORT_SECONDS = 120;

    public static function targets(Server $entry): array
    {
        if (!ServerRelayService::isSupportedEntry($entry)) return [];
        $entry->loadMissing('machine');
        // 包含备用落地，不跟随当前调度结果缩小名单。
        return ServerRelayService::childrenQuery($entry)->whereIn('type', Server::RELAY_TRANSIT_TYPES)
            ->orderBy('id')->get()->map(fn (Server $landing) => self::target($entry, $landing))->values()->all();
    }

    private static function target(Server $entry, Server $landing): array
    {
        $endpoint = DdnsService::landingEndpoint($landing);
        $address = trim((string) ($endpoint['resolved_address'] ?? $endpoint['address']), '[] ');
        $port = (int) $landing->port;
        $identity = [$entry->id, $entry->machine_id, data_get($entry->machine?->agent_runtime, 'boot_id'),
            $landing->id, $landing->machine_id, $landing->relay_entry_id, $landing->relay_pool_id,
            $address, $port, $landing->server_port, $landing->type, $landing->protocol_settings,
            $entry->custom_outbounds, $entry->custom_routes, $entry->route_ids];
        return ['node_id' => (int) $landing->id, 'address' => $address, 'port' => $port,
            'token' => hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR))];
    }

    public static function policy(Server $landing): array
    {
        $pending = fn (string $message) => ['version' => 1, 'status' => 'pending', 'sources' => [],
            'valid_until' => 0, 'message' => $message . '；保留原规则，等待重新核对'];
        if (!$landing->auto_firewall) return $pending('该落地已关闭自动防火墙管理');
        if (!$landing->machine_id) return $pending('落地未绑定服务器，无法核对端口');
        if ($message = ServerPortService::conflictMessage($landing)) return $pending($message);
        if ($landing->relay_pool_id) {
            $pool = BalancerPool::with('members.runtimeNode.machine')->find($landing->relay_pool_id);
            if (!$pool || !$pool->enabled || $pool->mode !== 'dns' || $pool->members->isEmpty()) {
                return $pending('前置池不存在、已停用或没有成员');
            }
            // 不能只取 DNS 当前选中、健康或在线的成员。
            $entries = $pool->members->pluck('runtimeNode');
        } else {
            $entries = collect([Server::with('machine')->find($landing->relayEntryId())]);
        }
        $sources = [];
        $validUntil = now()->timestamp + self::REPORT_SECONDS;
        foreach ($entries as $entry) {
            if (!$entry || $entry->enabled === false || !ServerRelayService::isSupportedEntry($entry)) {
                return $pending('前置成员缺失、停用或配置不兼容');
            }
            $machine = $entry->machine;
            if (!$machine || !$machine->is_active || (int) $machine->id === (int) $landing->machine_id) {
                return $pending("前置 {$entry->id} 的服务器绑定不可核对");
            }
            $target = self::target($entry, $landing);
            $report = Cache::get('relay:egress:v1:' . $entry->id, []);
            $route = $report['children'][$landing->id] ?? null;
            $checkedAt = $report['checked_at'] ?? 0;
            if (!is_array($route) || $checkedAt < now()->timestamp - self::REPORT_SECONDS
                || !hash_equals($target['token'], $route['token']) || !$route['complete'] || $route['sources'] === []) {
                return $pending("前置 {$entry->id} 到本落地的出口尚未完成核对或已过期");
            }
            $validUntil = min($validUntil, $checkedAt + self::REPORT_SECONDS);
            foreach ($route['sources'] as $ip) {
                $family = str_contains($ip, ':') ? 'ipv6' : 'ipv4';
                $runtime = $machine->agent_runtime ?? [];
                $observed = self::publicIp($runtime['public_' . $family] ?? null);
                $expires = (int) ($runtime['public_' . $family . '_at'] ?? 0) + MachineAgentService::ADDRESS_STALE_SECONDS;
                if ($observed !== $ip || $expires <= now()->timestamp) {
                    return $pending("前置 {$entry->id} 的 {$family} 选路来源与认证出口不一致或已过期");
                }
                $sources[$ip] = true;
                $validUntil = min($validUntil, $expires);
            }
        }
        $sources = array_keys($sources);
        sort($sources, SORT_STRING);
        if ($sources === [] || count($sources) > 4096) return $pending('前置出口名单为空或超过容量');
        return ['version' => 1, 'status' => 'ready', 'sources' => $sources, 'valid_until' => $validUntil, 'message' => ''];
    }

    private static function publicIp(mixed $raw): ?string
    {
        if (!is_string($raw) || !filter_var($raw, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return null;
        $packed = inet_pton($raw);
        if (strlen($packed) === 4 ? ord($packed[0]) >= 224
            : ord($packed[0]) === 255 || substr($packed, 0, 12) === hex2bin('00000000000000000000ffff')) return null;
        return inet_ntop($packed);
    }

    public static function recordEgress(Server $entry, mixed $report): void
    {
        if (!is_array($report) || ($report['version'] ?? null) !== 1
            || !is_int($report['checked_at'] ?? null) || $report['checked_at'] > now()->timestamp + 10
            || $report['checked_at'] < now()->timestamp - self::REPORT_SECONDS
            || !is_array($report['children'] ?? null) || count($report['children']) > 1000) return;
        $allowed = collect(self::targets($entry))->keyBy('node_id');
        $children = [];
        foreach ($report['children'] as $row) {
            if (!is_array($row) || !is_int($row['node_id'] ?? null)) continue;
            $target = $allowed->get($row['node_id']);
            if (!$target || !is_string($row['token'] ?? null) || !hash_equals($target['token'], $row['token'])) continue;
            $sources = is_array($row['sources'] ?? null) ? $row['sources'] : [];
            $normalized = count($sources) <= 32 ? array_map(self::publicIp(...), $sources) : [];
            $complete = ($row['complete'] ?? false) === true && $normalized !== [] && !in_array(null, $normalized, true);
            // 部分失败和重复目标不能被当成完整测量。
            if (isset($children[$row['node_id']])) $complete = false;
            $normalized = $complete ? array_values(array_unique($normalized)) : [];
            sort($normalized, SORT_STRING);
            $children[$row['node_id']] = ['token' => $target['token'], 'complete' => $complete, 'sources' => $normalized];
        }
        $key = 'relay:egress:v1:' . $entry->id;
        $changed = Cache::lock($key . ':lock', 10)->block(2, function () use ($key, $report, $children) {
            $previous = Cache::get($key, []);
            if (($previous['checked_at'] ?? 0) >= $report['checked_at']) return false;
            Cache::put($key, ['checked_at' => $report['checked_at'], 'children' => $children], self::REPORT_SECONDS + 30);
            return true;
        });
        if ($changed) {
            foreach (ServerRelayService::childrenQuery($entry)->pluck('id') as $id) NodeSyncService::notifyConfigUpdated((int) $id);
        }
    }

    /** 弥补推送丢失、机器地址更新和报告过期；节点拉取也实时重算。 */
    public static function refresh(): void
    {
        // 目标指纹包含机器身份，机器重启及绑定变化后必须重新下发测量目标。
        $entries = [];
        Server::where(fn ($q) => $q->whereNotNull('relay_entry_id')->orWhereNotNull('relay_pool_id'))
            ->whereIn('type', Server::RELAY_TRANSIT_TYPES)->eachById(function (Server $landing) use (&$entries) {
                NodeSyncService::notifyConfigUpdated((int) $landing->id);
                foreach (LandingPoolService::entries($landing) as $entry) $entries[$entry->id] = true;
            });
        foreach (array_keys($entries) as $id) NodeSyncService::notifyConfigUpdated((int) $id);
    }
}
