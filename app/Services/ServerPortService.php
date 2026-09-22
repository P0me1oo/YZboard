<?php

namespace App\Services;

use App\Models\Server;
use App\Models\ServerMachine;
use Illuminate\Validation\ValidationException;

/** 按面板下发给 Node 的监听配置检查内部端口，不检查客户端连接端口。 */
class ServerPortService
{
    /**
     * 返回固定内部端口实际使用的传输方式，不能用能否代理 UDP 流量代替监听判断。
     * 与 Node 的两个内核配置生成器及固定核心依赖保持一致。
     */
    public static function transports(Server $server): array
    {
        $settings = (array) $server->protocol_settings;
        $kernel = Server::effectiveKernelType($server->kernel_type);
        $network = strtolower(trim((string) ($settings['network'] ?? 'tcp')));
        $stream = in_array($network, ['kcp', 'mkcp', 'quic', 'hysteria'], true) ? ['udp'] : ['tcp'];

        return match ($server->type) {
            Server::TYPE_HYSTERIA, Server::TYPE_TUIC => ['udp'],
            Server::TYPE_SHADOWSOCKS, Server::TYPE_NAIVE => ['tcp', 'udp'],
            // sing-box 的 SOCKS UDP 转发使用临时端口，Xray 则同时监听固定 UDP 端口。
            Server::TYPE_SOCKS => $kernel === 'singbox' ? ['tcp'] : ['tcp', 'udp'],
            Server::TYPE_MIERU => strtoupper((string) ($settings['transport'] ?? 'TCP')) === 'UDP' ? ['udp'] : ['tcp'],
            Server::TYPE_VMESS, Server::TYPE_VLESS, Server::TYPE_TROJAN => $stream,
            Server::TYPE_HTTP => $kernel === 'xray' && (int) ($settings['tls'] ?? 0) === 1 ? $stream : ['tcp'],
            default => ['tcp'],
        };
    }

    public static function conflictMessage(Server $server, ?Server $previous = null, bool $lock = false): ?string
    {
        $machineId = (int) $server->machine_id;
        $port = (int) $server->server_port;
        if ($machineId <= 0 || $port <= 0) {
            return null;
        }

        $transports = self::transports($server);
        // 允许复制后修改名称等无关字段；修改监听占用或重新启用时再检查。
        if ($previous !== null
            && $machineId === (int) $previous->machine_id
            && $port === (int) $previous->server_port
            && $transports === self::transports($previous)
            && !($server->enabled === true && $previous->enabled !== true)) {
            return null;
        }

        if ($lock) {
            // 即使机器尚无节点也有可锁定的行，使同一机器的普通保存串行检查。
            ServerMachine::whereKey($machineId)->lockForUpdate()->first(['id']);
        }

        $query = Server::where('machine_id', $machineId)
            ->where('server_port', $port)
            ->when($server->exists, fn ($query) => $query->where('id', '!=', $server->id))
            ->orderBy('id');
        if ($lock) {
            // 使用当前读，避免事务快照遗漏等待机器锁期间已经提交的节点。
            $query->lockForUpdate();
        }

        // 隐藏或停用不释放配置中的端口，避免后续启用时才发现重复。
        foreach ($query->get(['id', 'name', 'type', 'kernel_type', 'protocol_settings']) as $other) {
            $overlap = array_intersect($transports, self::transports($other));
            if ($overlap !== []) {
                $network = implode('/', array_map('strtoupper', $overlap));
                return "内部端口 {$port}/{$network} 已被当前绑定服务器上的节点「{$other->name}」（ID：{$other->id}）使用，请更换端口";
            }
        }

        return null;
    }

    /** 调用方在保存事务内执行；复制接口生成的副本默认关闭，不在复制时检查，开启时再校验。 */
    public static function validateForSave(Server $server, ?Server $previous = null): void
    {
        $message = self::conflictMessage($server, $previous, lock: true);
        if ($message !== null) {
            throw ValidationException::withMessages(['server_port' => $message]);
        }
    }
}
