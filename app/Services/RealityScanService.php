<?php

namespace App\Services;

use App\Models\ServerMachine;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RealityScanService
{
    public const DEFAULTS = [
        'www.microsoft.com:443', 'www.amazon.com:443', 'aws.amazon.com:443',
        'www.samsung.com:443', 'www.nvidia.com:443', 'www.amd.com:443',
        'www.intel.com:443', 'www.sony.com:443', 'dl.google.com:443',
    ];

    public static function targets(?string $input, string $sni = ''): array
    {
        $targets = preg_split('/[\s,]+/', trim($input ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: self::DEFAULTS;
        if (count($targets) > 64 || ($sni !== '' && (count($targets) !== 1 || !self::domain($sni)))) {
            throw ValidationException::withMessages(['targets' => '目标格式无效，最多输入 64 项；单目标检测的 SNI 必须是域名。']);
        }
        $total = 0;
        foreach ($targets as $target) {
            if (str_contains($target, '/')) {
                [$ip, $prefix] = explode('/', $target, 2);
                $bits = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? 32 : 128;
                if (!filter_var($ip, FILTER_VALIDATE_IP) || !ctype_digit($prefix)
                    || (int) $prefix > $bits || (int) $prefix < $bits - 8 || $sni !== ''
                    || str_starts_with(strtolower($ip), '::ffff:')) {
                    throw ValidationException::withMessages(['targets' => '网段格式无效；每个网段最多 256 个地址，IPv4 至少 /24，IPv6 至少 /120。']);
                }
                $total += 2 ** ($bits - (int) $prefix);
            } else {
                $host = $target;
                if (!filter_var($host, FILTER_VALIDATE_IP)) {
                    if (!preg_match('/\A(?:\[([^\]]+)\]|([^:]+))(?::([0-9]+))?\z/D', $target, $parts)) {
                        throw ValidationException::withMessages(['targets' => '目标应为域名、IP、域名:端口或 [IPv6]:端口。']);
                    }
                    $host = $parts[1] !== '' ? $parts[1] : ($parts[2] ?? '');
                    $port = $parts[3] ?? '443';
                    if ((int) $port < 1 || (int) $port > 65535) {
                        throw ValidationException::withMessages(['targets' => '目标端口必须在 1 到 65535 之间。']);
                    }
                }
                if (!filter_var($host, FILTER_VALIDATE_IP) && !self::domain($host)) {
                    throw ValidationException::withMessages(['targets' => '请输入有效的公网域名或 IP，不要包含协议和路径。']);
                }
                $total++;
            }
        }
        if ($total > 512) {
            throw ValidationException::withMessages(['targets' => '每次最多检测 512 个地址。']);
        }
        return array_values(array_unique($targets));
    }

    private static function domain(string $value): bool
    {
        $value = rtrim($value, '.');
        return strlen($value) <= 253 && str_contains($value, '.')
            && !filter_var($value, FILTER_VALIDATE_IP)
            && (bool) filter_var($value, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME);
    }

    private static function cache() { return Cache::store('redis'); }
    private static function key(int $id): string { return "reality_scan:machine:{$id}"; }

    private static function expire(?array $task): ?array
    {
        if ($task && in_array($task['status'], ['pending', 'running'], true) && $task['expires_at'] <= time()) {
            $task['status'] = 'failed';
            $task['error'] = 'timeout';
            self::cache()->put(self::key($task['machine_id']), $task, 300);
            self::cache()->forget(self::key($task['machine_id']) . ':active');
        }
        return $task;
    }

    public static function view(int $machineId): ?array
    {
        return self::cache()->lock(self::key($machineId) . ':lock', 5)->block(2, fn () => self::expire(self::cache()->get(self::key($machineId))));
    }

    public static function start(ServerMachine $machine, ?string $input, string $sni = ''): array
    {
        $targets = self::targets($input, $sni);
        $runtime = $machine->agent_runtime ?? [];
        $error = !$machine->is_active ? '服务器已停用。'
            : (($runtime['reported_at'] ?? 0) < time() - MachineAgentService::ONLINE_SECONDS ? '服务器离线，请等待 Node 恢复连接。'
            : (!($runtime['reality_scan_supported'] ?? false) ? '此 Node 尚不支持检测，请升级到 v2.12.0 或更高版本。' : null));
        if ($error) { throw ValidationException::withMessages(['machine_id' => $error]); }

        return self::cache()->lock(self::key($machine->id) . ':lock', 5)->block(2, function () use ($machine, $runtime, $targets, $sni) {
            $previous = self::expire(self::cache()->get(self::key($machine->id)));
            if ($previous && in_array($previous['status'], ['pending', 'running'], true)) {
                if ($previous['targets'] === $targets && $previous['sni'] === $sni) { return $previous; }
                throw ValidationException::withMessages(['targets' => '该服务器已有检测任务，请等待完成后重试。']);
            }
            $task = [
                'id' => (string) Str::uuid(), 'machine_id' => $machine->id,
                'boot_id' => $runtime['boot_id'], 'targets' => $targets, 'sni' => $sni,
                'status' => 'pending', 'created_at' => time(), 'expires_at' => time() + 180,
                'results' => [], 'error' => null,
            ];
            self::cache()->put(self::key($machine->id), $task, 480);
            self::cache()->put(self::key($machine->id) . ':active', true, 180);
            return $task;
        });
    }

    // 只接受当前机器、进程和任务的结果；断线重发不会覆盖后来的任务。
    public static function exchange(ServerMachine $machine, array $report): ?array
    {
        // 没有在途检测时不读取大结果集，也不为普通心跳获取检测锁。
        if (!self::cache()->has(self::key($machine->id) . ':active')) { return null; }
        return self::cache()->lock(self::key($machine->id) . ':lock', 5)->block(2, function () use ($machine, $report) {
            $task = self::expire(self::cache()->get(self::key($machine->id)));
            if (!$task || !in_array($task['status'], ['pending', 'running'], true)) { return null; }
            if (!$machine->is_active || $task['boot_id'] !== $report['boot_id']) {
                $task['status'] = 'failed';
                $task['error'] = 'node_restarted';
            } else {
                $result = $report['reality_scan'] ?? null;
                if ($result && hash_equals($task['id'], $result['id'])) {
                    $task['status'] = $result['status'];
                    $task['results'] = $result['status'] === 'succeeded' ? ($result['results'] ?? []) : [];
                    $task['error'] = $result['error'] ?? null;
                }
            }
            self::cache()->put(self::key($machine->id), $task, 480);
            if (!in_array($task['status'], ['pending', 'running'], true)) {
                self::cache()->forget(self::key($machine->id) . ':active');
                return null;
            }
            return array_intersect_key($task, array_flip(['id', 'targets', 'sni', 'expires_at']));
        });
    }
}
