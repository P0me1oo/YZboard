<?php

namespace App\Services;

use App\Models\Server;
use App\Models\ServerMachine;
use Illuminate\Support\Facades\Cache;

/** 内置中转落地的来源策略；地址只取已认证的前置机器回报，不解析订阅地址。 */
class RelayFirewallService
{
    public static function policy(Server $landing): array
    {
        $pending = fn (string $message) => ['status' => 'pending', 'sources' => [], 'message' => $message];
        $entry = Server::find($landing->relayEntryId());
        if (!$entry || $entry->enabled === false) {
            return $pending('前置入口不存在或已停用，请确认中转绑定；来源规则暂不变更');
        }
        if (!(int) $landing->machine_id) {
            return $pending('落地尚未绑定服务器，无法核对端口共用情况，请确认服务器绑定');
        }
        if ($message = ServerPortService::conflictMessage($landing)) {
            return $pending($message . '；来源规则暂不变更');
        }
        $machine = ServerMachine::find($entry->machine_id);
        if (!$machine || !$machine->is_active) {
            return $pending('前置尚未绑定可用服务器，无法确认出口 IP，请确认前置服务器绑定');
        }
        $runtime = $machine->agent_runtime ?? [];
        $sources = [];
        foreach (['ipv4', 'ipv6'] as $family) {
            $ip = $runtime["public_{$family}"] ?? null;
            if (($runtime["public_{$family}_at"] ?? 0) < time() - MachineAgentService::ADDRESS_STALE_SECONDS) {
                continue;
            }
            if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                $sources[] = inet_ntop(inet_pton($ip));
            }
        }
        $sources = array_values(array_unique($sources));
        sort($sources, SORT_STRING);
        $confirmationKey = hash('sha256', json_encode([
            $landing->id, $entry->id, $entry->machine_id, $landing->machine_id, $landing->host,
            $landing->port, $landing->server_port, $landing->type, $sources,
        ], JSON_THROW_ON_ERROR));
        $confirmation = data_get($landing->protocol_settings, 'relay_source_confirmation');
        if (is_array($confirmation) && ($confirmation['key'] ?? null) === $confirmationKey
            && self::validSources($confirmation['sources'] ?? null)) {
            return ['status' => 'ready', 'sources' => $confirmation['sources'], 'message' => null];
        }
        $pending = fn (string $message) => ['status' => 'pending', 'sources' => [],
            'message' => $message, 'confirmation_key' => $confirmationKey];
        if ($sources === []) {
            return $pending('尚未收到前置有效的出口 IP，请确认前置在线并已升级；来源规则暂不变更');
        }
        $route = Cache::get('relay:egress:' . $entry->id, [])[$landing->id] ?? null;
        if (!is_array($route) || ($route['checked_at'] ?? 0) < time() - 120
            || ($route['address'] ?? null) !== $landing->host || ($route['port'] ?? null) !== (int) $landing->port
            || empty($route['sources']) || array_diff($route['sources'], $sources) !== []) {
            return $pending('尚未确认前置到本落地的出口 IP，请确认前置已升级且使用内置中转出站；来源规则暂不变更');
        }
        return ['status' => 'ready', 'sources' => $route['sources'], 'message' => null];
    }

    public static function validSources(mixed $sources): bool
    {
        return is_array($sources) && count($sources) >= 1 && count($sources) <= 16
            && count(array_filter($sources, function ($ip): bool {
                if (!is_string($ip) || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE)) {
                    return false;
                }
                $packed = inet_pton($ip);
                // 只接受具体单播地址，避免把组播或映射地址错误地保存为可放行来源。
                return strlen($packed) === 4 ? ord($packed[0]) < 224
                    : ord($packed[0]) !== 255 && substr($packed, 0, 12) !== hex2bin('00000000000000000000ffff');
            })) === count($sources);
    }

    /** 报告已经过节点认证，仍限制数量、目标绑定和时间，拒绝过期的旧目标地址。 */
    public static function recordEgress(Server $entry, mixed $report): void
    {
        if (!is_array($report) || !is_int($report['checked_at'] ?? null)
            || abs(time() - $report['checked_at']) > 120 || !is_array($report['children'] ?? null)
            || count($report['children']) > 1000) {
            return;
        }
        $children = [];
        foreach ($report['children'] as $item) {
            if (!is_array($item) || !is_int($item['node_id'] ?? null) || !is_string($item['address'] ?? null)
                || !is_int($item['port'] ?? null) || !is_array($item['sources'] ?? null) || count($item['sources']) > 16) {
                continue;
            }
            $sources = array_values(array_unique(array_filter($item['sources'], fn ($ip) => is_string($ip)
                && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE))));
            // 有私网源地址时不能只丢掉它，再错误地确认剩余地址。
            if (count($sources) !== count(array_unique($item['sources'], SORT_REGULAR))) {
                $sources = [];
            }
            sort($sources, SORT_STRING);
            $children[$item['node_id']] = ['address' => $item['address'], 'port' => $item['port'],
                'sources' => $sources, 'checked_at' => $report['checked_at']];
        }
        $key = 'relay:egress:' . $entry->id;
        Cache::lock($key . ':lock', 5)->block(2, function () use ($key, $children, $report): void {
            $previous = Cache::get($key, []);
            if (is_array($previous) && max(array_column($previous, 'checked_at') ?: [0]) > $report['checked_at']) {
                return;
            }
            Cache::put($key, $children, 120);
        });
    }
}
