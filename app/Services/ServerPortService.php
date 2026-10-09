<?php

namespace App\Services;

use App\Models\Server;
use Illuminate\Support\Collection;

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
            Server::TYPE_HYSTERIA, Server::TYPE_TUIC, Server::TYPE_WIREGUARD => ['udp'],
            Server::TYPE_SHADOWSOCKS, Server::TYPE_NAIVE => ['tcp', 'udp'],
            // sing-box 的 SOCKS UDP 转发使用临时端口，Xray 则同时监听固定 UDP 端口。
            Server::TYPE_SOCKS => $kernel === 'singbox' ? ['tcp'] : ['tcp', 'udp'],
            Server::TYPE_MIERU => strtoupper((string) ($settings['transport'] ?? 'TCP')) === 'UDP' ? ['udp'] : ['tcp'],
            Server::TYPE_VMESS, Server::TYPE_VLESS, Server::TYPE_TROJAN => $stream,
            Server::TYPE_HTTP => $kernel === 'xray' && (int) ($settings['tls'] ?? 0) === 1 ? $stream : ['tcp'],
            default => ['tcp'],
        };
    }

    public static function conflictMessage(Server $server): ?string
    {
        $machineId = (int) $server->machine_id;
        $port = (int) $server->server_port;
        if ($machineId <= 0 || $port <= 0) {
            return null;
        }

        $transports = self::transports($server);
        $query = Server::where('machine_id', $machineId)
            ->where('server_port', $port)
            ->when($server->exists, fn ($query) => $query->where('id', '!=', $server->id))
            ->orderBy('id');
        // 检查配置中的重叠，包含隐藏或停用节点；不据此判断服务器的实际监听状态。
        foreach ($query->get(['id', 'name', 'type', 'kernel_type', 'protocol_settings']) as $other) {
            $overlap = array_intersect($transports, self::transports($other));
            if ($overlap !== []) {
                return self::message($port, $overlap, $other);
            }
        }

        return null;
    }

    /** 从管理列表一次计算全部提醒，不逐节点查询数据库，也不受列表筛选或分页影响。 */
    public static function conflictMessages(Collection $servers): array
    {
        $listeners = $transports = $messages = [];
        $ordered = $servers->sortBy('id');
        foreach ($ordered as $server) {
            if ((int) $server->machine_id <= 0 || (int) $server->server_port <= 0) {
                continue;
            }
            $transports[$server->id] = self::transports($server);
            foreach ($transports[$server->id] as $transport) {
                $key = "{$server->machine_id}:{$server->server_port}:{$transport}";
                // 只需两个节点即可为组内任意节点找到另一方，避免重复端口较多时两两遍历。
                if (count($listeners[$key] ?? []) < 2) {
                    $listeners[$key][] = $server;
                }
            }
        }
        foreach ($ordered as $server) {
            $other = null;
            foreach ($transports[$server->id] ?? [] as $transport) {
                $key = "{$server->machine_id}:{$server->server_port}:{$transport}";
                foreach ($listeners[$key] as $candidate) {
                    if ($candidate->id !== $server->id && ($other === null || $candidate->id < $other->id)) {
                        $other = $candidate;
                    }
                }
            }
            if ($other !== null) {
                $overlap = array_intersect($transports[$server->id], $transports[$other->id]);
                $messages[$server->id] = self::message((int) $server->server_port, $overlap, $other);
            }
        }
        return $messages;
    }

    private static function message(int $port, array $overlap, Server $other): string
    {
        $network = implode('/', array_map('strtoupper', $overlap));
        return "内部端口 {$port}/{$network} 可能与当前绑定服务器上的节点「{$other->name}」（ID：{$other->id}）冲突";
    }
}
