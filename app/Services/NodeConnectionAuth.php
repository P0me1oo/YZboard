<?php

namespace App\Services;

use App\Support\Setting;
use Workerman\Connection\TcpConnection;

/** 每次读写复核握手身份；数据库与缓存故障时关闭连接，不沿用旧授权。 */
class NodeConnectionAuth
{
    public static function bind(TcpConnection $conn, string $token): void
    {
        $conn->authTokenHash = hash('sha256', $token);
        $conn->authorizeSend = fn (?int $nodeId = null) => self::check($conn, $nodeId);
    }

    public static function check(TcpConnection $conn, ?int $nodeId = null): bool
    {
        try {
            $hash = $conn->authTokenHash ?? null;
            if (!is_string($hash)) return self::reject($conn);
            $metadata = app(NodeRuntimeMetadata::class);
            if (!empty($conn->machineId)) {
                $machine = $metadata->machineOrFail((int) $conn->machineId);
                if (!$machine->is_active || !hash_equals($hash, hash('sha256', $machine->token))) return self::reject($conn);
                if ($nodeId !== null) {
                    $node = $metadata->node($nodeId);
                    if (!$node || !$node->enabled || (int) $node->machine_id !== (int) $machine->id) return self::reject($conn);
                }
            } else {
                // Setting 是常驻实例，不能使用握手时留下的旧全局令牌。
                app()->forgetInstance(Setting::class);
                $token = app(Setting::class)->getOrFail('server_token', '');
                if (!is_string($token) || $token === '' || !hash_equals($hash, hash('sha256', $token))) return self::reject($conn);
                $node = $metadata->node((int) ($conn->nodeId ?? 0));
                if (!$node || !$node->enabled || ($nodeId !== null && $nodeId !== (int) $node->id)) return self::reject($conn);
            }
            return true;
        } catch (\Throwable $exception) {
            FailureLog::record('[WS] Node authentication check failed', $exception, ['node_id' => (int) ($conn->nodeId ?? 0), 'machine_id' => (int) ($conn->machineId ?? 0)], 'warning');
            return self::reject($conn);
        }
    }

    private static function reject(TcpConnection $conn): bool
    {
        $conn->close(json_encode(['event' => 'auth.error']));
        return false;
    }
}
