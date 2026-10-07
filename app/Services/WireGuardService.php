<?php

namespace App\Services;

use App\Models\Server;

/** 普通 WG 节点的身份与订阅；中转继续使用独立的链路身份。 */
class WireGuardService
{
    private static function key(Server $node, string $purpose): string
    {
        $secret = (string) config('app.key');
        if ($secret === '') {
            throw new \RuntimeException('生成 WireGuard 身份需要面板应用密钥');
        }
        $key = hash_hmac('sha256', implode('|', [
            'yz-wireguard-direct-v1', $node->id, $node->getRawOriginal('created_at'), $purpose,
        ]), $secret, true);
        $key[0] = chr(ord($key[0]) & 248);
        $key[31] = chr((ord($key[31]) & 127) | 64);
        return $key;
    }

    private static function userKey(Server $node, object $user): string
    {
        return self::key($node, 'user|' . $user->id . '|' . $user->uuid);
    }

    /** 同一节点内按用户编号分配唯一地址；刷新订阅和重启不会改变地址。 */
    public static function addresses(int $id): array
    {
        if ($id < 1 || $id > 16777212) {
            throw new \RuntimeException('用户编号超过 WireGuard IPv4 地址容量');
        }
        $host = $id + 1;
        return [sprintf('10.%d.%d.%d/32', ($host >> 16) & 255, ($host >> 8) & 255, $host & 255),
            sprintf('fd7a:797a:1::%x:%x/128', $host >> 16, $host & 65535)];
    }

    public static function nodeConfig(Server $node): array
    {
        return ['private_key' => base64_encode(self::key($node, 'server')),
            'address' => ['10.0.0.1/32', 'fd7a:797a:1::1/128'],
            'mtu' => (int) data_get($node->protocol_settings, 'mtu', 1420)];
    }

    /** 节点只接收用户公钥、地址和到期时间，不接收客户端私钥。 */
    public static function peer(Server $node, object $user): array
    {
        return ['public_key' => base64_encode(\ParagonIE_Sodium_Compat::crypto_scalarmult_base(self::userKey($node, $user))),
            'address' => self::addresses((int) $user->id), 'expires_at' => (int) ($user->expired_at ?? 0)];
    }

    public static function client(Server $node, object $user): array
    {
        return ['private_key' => base64_encode(self::userKey($node, $user)),
            'public_key' => base64_encode(\ParagonIE_Sodium_Compat::crypto_scalarmult_base(self::key($node, 'server'))),
            'address' => self::addresses((int) $user->id),
            'mtu' => (int) data_get($node->protocol_settings, 'mtu', 1420),
            'keepalive' => (int) data_get($node->protocol_settings, 'keepalive', 25)];
    }
}
