<?php

namespace App\Services;

use App\Models\ServerMachine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MachineAgentService
{
    public const ONLINE_SECONDS = 30;
    public const OPERATION_SECONDS = 900;

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
            $runtime = [
                'version' => $report['version'], 'boot_id' => $report['boot_id'],
                'manageable' => $report['manageable'], 'reported_at' => time(),
                'public_ip' => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) ? $ip : null,
            ];
            $operation = $machine->agent_operation;
            $result = $report['operation'] ?? null;
            if ($operation && in_array($operation['status'], ['pending', 'running'], true)
                && $result && hash_equals($operation['id'], $result['id'])) {
                if ($result['status'] === 'failed') {
                    $operation['status'] = 'failed';
                    $operation['error'] = $result['error'] ?? 'execution_failed';
                } elseif ($result['status'] === 'succeeded' && $runtime['boot_id'] !== $operation['boot_id']) {
                    // 只有独立执行器完成且新进程已回报，才确认成功。
                    $operation['status'] = 'succeeded';
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
}
