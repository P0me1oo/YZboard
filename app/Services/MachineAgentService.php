<?php

namespace App\Services;

use App\Models\ServerMachine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MachineAgentService
{
    public const ONLINE_SECONDS = 30;
    public const OPERATION_SECONDS = 900;
    // 某一地址族比另一地址族最近一次回报旧出这么久，视为已失效不再展示。
    public const ADDRESS_STALE_SECONDS = 900;
    private const ADDRESS_FAMILIES = ['ipv4', 'ipv6'];

    public static function expire(ServerMachine $machine): void
    {
        $operation = $machine->agent_operation;
        if ($operation && in_array($operation['status'], ['pending', 'running'], true)
            && $operation['expires_at'] <= time()) {
            $operation['status'] = 'timeout';
            $operation['error'] = 'timeout';
            $machine->forceFill(['agent_operation' => $operation])->saveQuietly();
        }
    }

    public static function queue(int $id, string $action): array
    {
        return DB::transaction(function () use ($id, $action) {
            $machine = ServerMachine::whereKey($id)->lockForUpdate()->first();
            if (!$machine) {
                return ['id' => $id, 'error' => 'not_found'];
            }
            self::expire($machine);
            $runtime = $machine->agent_runtime ?? [];
            $error = !$machine->is_active ? 'disabled'
                : (($runtime['reported_at'] ?? 0) < time() - self::ONLINE_SECONDS ? 'offline'
                : (!($runtime['manageable'] ?? false) ? 'unsupported' : null));
            $operation = $machine->agent_operation;
            if ($operation && in_array($operation['status'], ['pending', 'running'], true)) {
                $error = 'busy';
            }
            if ($error) {
                return ['id' => $id, 'error' => $error];
            }
            $operation = [
                'id' => (string) Str::uuid(), 'action' => $action, 'status' => 'pending',
                'created_at' => time(), 'expires_at' => time() + self::OPERATION_SECONDS,
                'boot_id' => $runtime['boot_id'], 'error' => null,
            ];
            $machine->forceFill(['agent_operation' => $operation])->saveQuietly();
            return ['id' => $id, 'operation' => $operation];
        });
    }

    public static function exchange(ServerMachine $authenticated, array $report, ?string $ip): ?array
    {
        return DB::transaction(function () use ($authenticated, $report, $ip) {
            $machine = ServerMachine::whereKey($authenticated->id)->lockForUpdate()->firstOrFail();
            self::expire($machine);
            $ip = self::publicIp($ip);
            $previous = $machine->agent_runtime ?? [];
            $runtime = [
                'version' => $report['version'], 'boot_id' => $report['boot_id'],
                'manageable' => $report['manageable'], 'reported_at' => time(),
                'public_ip' => $ip,
            ];
            // 控制请求只走系统默认的一种地址族，另一种由 agent 单独回报，这里要保留。
            foreach (self::ADDRESS_FAMILIES as $family) {
                foreach (["public_{$family}", "public_{$family}_at"] as $key) {
                    if (isset($previous[$key])) {
                        $runtime[$key] = $previous[$key];
                    }
                }
            }
            $runtime = self::withAddress($runtime, $ip);
            $operation = $machine->agent_operation;
            $result = $report['operation'] ?? null;
            if ($operation && in_array($operation['status'], ['pending', 'running'], true)
                && $result && hash_equals($operation['id'], $result['id'])) {
                if ($result['status'] === 'failed') {
                    $operation['status'] = 'failed';
                    $operation['error'] = $result['error'] ?? 'execution_failed';
                } elseif ($result['status'] === 'succeeded'
                    && ($runtime['boot_id'] !== $operation['boot_id']
                        || ($operation['action'] === 'upgrade'
                            && in_array($result['result'] ?? null, ['up_to_date', 'current_newer'], true)))) {
                    // 无需升级可由原进程确认；实际更新和手动重启仍须新进程回报。
                    $operation['status'] = 'succeeded';
                    if ($operation['action'] === 'upgrade' && isset($result['result'])) {
                        $operation['result'] = $result['result'];
                    }
                } else {
                    $operation['status'] = 'running';
                }
            }
            $machine->forceFill([
                'agent_runtime' => $runtime, 'agent_operation' => $operation, 'last_seen_at' => time(),
            ])->saveQuietly();
            return $operation && in_array($operation['status'], ['pending', 'running'], true) ? $operation : null;
        });
    }

    /**
     * agent 分别经 IPv4、IPv6 连接面板时，按来源地址族各记一份公网地址。
     */
    public static function recordAddress(ServerMachine $authenticated, ?string $ip): ?string
    {
        $ip = self::publicIp($ip);
        if ($ip === null) {
            return null;
        }
        DB::transaction(function () use ($authenticated, $ip) {
            $machine = ServerMachine::whereKey($authenticated->id)->lockForUpdate()->firstOrFail();
            $machine->forceFill(['agent_runtime' => self::withAddress($machine->agent_runtime ?? [], $ip)])->saveQuietly();
        });
        return $ip;
    }

    /**
     * 管理列表展示用：某一地址族长时间没有回报（比如服务器去掉了 IPv6）时不再展示。
     * 以两种地址族中较新的回报为基准，服务器整体离线时仍保留最后一次地址。
     */
    public static function runtimeView(?array $runtime): ?array
    {
        if (!$runtime) {
            return $runtime;
        }
        $latest = max(array_map(fn ($family) => (int) ($runtime["public_{$family}_at"] ?? 0), self::ADDRESS_FAMILIES));
        foreach (self::ADDRESS_FAMILIES as $family) {
            if ((int) ($runtime["public_{$family}_at"] ?? 0) < $latest - self::ADDRESS_STALE_SECONDS) {
                $runtime["public_{$family}"] = null;
            }
        }
        return $runtime;
    }

    private static function withAddress(array $runtime, ?string $ip): array
    {
        if ($ip !== null) {
            $family = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? 'ipv4' : 'ipv6';
            $runtime["public_{$family}"] = $ip;
            $runtime["public_{$family}_at"] = time();
        }
        return $runtime;
    }

    private static function publicIp(?string $ip): ?string
    {
        // 双栈监听可能把 IPv4 来源写成 ::ffff:a.b.c.d，先还原成 IPv4 再判断。
        if (is_string($ip) && preg_match('/^::ffff:(\d{1,3}(?:\.\d{1,3}){3})$/i', $ip, $matches)) {
            $ip = $matches[1];
        }
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) ? $ip : null;
    }
}
