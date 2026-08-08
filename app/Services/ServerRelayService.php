<?php

namespace App\Services;

use App\Models\Server;
use App\Models\Setting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * 中转拓扑服务。
 *
 * 约定：节点的前置入口非空时，该节点是“中转逻辑节点”，前置入口是客户端真实连接的入口。
 * 逻辑节点自身的协议、地址、端口描述的是入口到落地服务器之间的内部链路，不会出现在用户订阅中。
 * 当前只支持“一个真实入口 + 一层落地”，内部链路可使用 Shadowsocks 或 VLESS。
 */
class ServerRelayService
{
    /** 入口节点必须使用的客户端协议。路由编号能力由 VLESS 入站提供。 */
    public const ENTRY_TYPE = Server::TYPE_VLESS;

    /** 内部中转链路支持的加密算法。键为算法名，值为 SS2022 的密钥字节长度（传统算法为 null）。 */
    private const TRANSIT_CIPHERS = [
        '2022-blake3-aes-128-gcm' => 16,
        '2022-blake3-aes-256-gcm' => 32,
        '2022-blake3-chacha20-poly1305' => 32,
        'aes-128-gcm' => null,
        'aes-192-gcm' => null,
        'aes-256-gcm' => null,
        'chacha20-ietf-poly1305' => null,
        'xchacha20-ietf-poly1305' => null,
    ];

    private const DEFAULT_TRANSIT_CIPHER = '2022-blake3-aes-128-gcm';

    /** 与当前 YZ-Xray-core 对齐的 VLESS 传输名称。H2 已由核心移除，不在此兼容。 */
    private const VLESS_NETWORKS = [
        'tcp' => 'tcp',
        'raw' => 'tcp',
        'ws' => 'ws',
        'websocket' => 'ws',
        'grpc' => 'grpc',
        'xhttp' => 'xhttp',
        'splithttp' => 'xhttp',
        'httpupgrade' => 'httpupgrade',
        'kcp' => 'kcp',
        'mkcp' => 'kcp',
        'hysteria' => 'hysteria',
    ];

    /** Reality 在当前核心中只允许 RAW、XHTTP 和 gRPC。 */
    private const REALITY_NETWORKS = ['tcp', 'xhttp', 'grpc'];

    /**
     * 内部中转出站/入站标签。标签必须稳定，落地节点的流量统计按标签回写到逻辑节点。
     */
    public static function outboundTag(int $childId): string
    {
        return "relay-{$childId}";
    }

    /**
     * 从出站标签还原逻辑节点 ID，无法识别时返回 null。
     */
    public static function nodeIdFromOutboundTag(string $tag): ?int
    {
        if (!preg_match('/^relay-(\d+)$/', $tag, $matches)) {
            return null;
        }
        $id = (int) $matches[1];
        return $id > 0 ? $id : null;
    }

    public static function isSupportedTransitCipher(?string $cipher): bool
    {
        return $cipher !== null && array_key_exists($cipher, self::TRANSIT_CIPHERS);
    }

    public static function supportedTransitCiphers(): array
    {
        return array_keys(self::TRANSIT_CIPHERS);
    }

    /**
     * 分配一个新的路由编号。
     *
     * 游标只增不减，因此删除节点后编号不会被立即复用。
     */
    public static function allocateRouteId(): int
    {
        $allocate = function (): int {
            // 游标直接读写数据库，绕开 admin_setting 的全局缓存，避免读到过期值导致编号重复。
            $cursor = (int) (Setting::where('name', Server::ROUTE_CURSOR_SETTING)->value('value') ?? 0);
            $maxUsed = (int) Server::max('vless_route');
            $next = max($cursor, $maxUsed, Server::ROUTE_ID_MIN - 1) + 1;

            if ($next > Server::ROUTE_ID_MAX) {
                $next = self::reclaimRouteId();
            }

            Setting::createOrUpdate(Server::ROUTE_CURSOR_SETTING, (string) min($next, Server::ROUTE_ID_MAX));

            return $next;
        };

        $lock = Cache::lock('vless_route_allocate', 10);
        if ($lock->get()) {
            try {
                return $allocate();
            } finally {
                $lock->release();
            }
        }

        return $allocate();
    }

    /**
     * 编号耗尽时回收一个未被占用的编号。找不到时抛出异常，避免静默产生重复编号。
     */
    private static function reclaimRouteId(): int
    {
        $used = Server::whereNotNull('vless_route')->pluck('vless_route')->all();
        $used = array_flip(array_map('intval', $used));

        for ($candidate = Server::ROUTE_ID_MIN; $candidate <= Server::ROUTE_ID_MAX; $candidate++) {
            if (!isset($used[$candidate])) {
                return $candidate;
            }
        }

        throw new \RuntimeException('VLESS 路由编号已耗尽');
    }

    /**
     * 确保节点拥有稳定的路由编号，返回该编号。
     */
    public static function ensureRouteId(Server $server): int
    {
        if ($server->vless_route !== null && $server->vless_route >= Server::ROUTE_ID_MIN) {
            return (int) $server->vless_route;
        }

        $routeId = self::allocateRouteId();
        $server->vless_route = $routeId;

        // 直接落库，避免触发 Server 的观察者和订阅缓存失效逻辑。
        DB::table('v2_server')->where('id', $server->id)->update(['vless_route' => $routeId]);

        return $routeId;
    }

    /**
     * 取得逻辑节点对应的真实入口节点；不是合法的中转逻辑节点时返回 null。
     */
    public static function entryFor(Server $child): ?Server
    {
        if (!$child->isRelayChild()) {
            return null;
        }

        $entry = $child->relayEntry;
        if (!$entry || $entry->id === $child->id) {
            return null;
        }

        // 只支持一层中转：入口自身不能再挂在别的入口下面。
        if ($entry->relayEntryId() !== null) {
            return null;
        }

        if ($entry->type !== self::ENTRY_TYPE) {
            return null;
        }

        if (self::validateTransitSettings(
            $entry->type,
            (array) $entry->protocol_settings,
            (string) $entry->host,
        ) !== null) {
            return null;
        }

        return $entry;
    }

    /**
     * 取得以该节点为入口、且处于启用状态的中转逻辑节点。
     */
    public static function childrenOf(Server $entry): Collection
    {
        if ($entry->relayEntryId() !== null || $entry->type !== self::ENTRY_TYPE
            || self::validateTransitSettings(
                $entry->type,
                (array) $entry->protocol_settings,
                (string) $entry->host,
            ) !== null) {
            return collect();
        }

        return Server::where('relay_entry_id', $entry->id)
            ->whereIn('type', Server::RELAY_TRANSIT_TYPES)
            ->where(function ($query) {
                $query->where('enabled', true)->orWhereNull('enabled');
            })
            ->orderBy('sort', 'ASC')
            ->get()
            ->filter(fn(Server $child) => self::validateTransitSettings(
                $child->type,
                (array) $child->protocol_settings,
                (string) $child->host,
            ) === null)
            ->values();
    }

    public static function hasRelayChildren(Server $entry): bool
    {
        return self::childrenOf($entry)->isNotEmpty();
    }

    /**
     * 生成入口与落地之间的内部认证信息。
     *
     * 由面板密钥派生，两端各自向面板拉取同一份结果，不写入数据库，也不下发给客户端。
     */
    public static function transitCredential(Server $child): array
    {
        $cipher = data_get($child->protocol_settings, 'cipher');
        if (!self::isSupportedTransitCipher($cipher)) {
            $cipher = self::DEFAULT_TRANSIT_CIPHER;
        }

        $material = hash_hmac(
            'sha256',
            implode('|', [
                'xboard-relay-shadowsocks',
                (string) $child->id,
                (string) $child->relay_entry_id,
                (string) $child->getRawOriginal('created_at'),
            ]),
            (string) config('app.key'),
            true
        );

        $keySize = self::TRANSIT_CIPHERS[$cipher];
        $password = $keySize === null
            ? substr(bin2hex($material), 0, 32)
            : base64_encode(substr($material, 0, $keySize));

        return [
            'cipher' => $cipher,
            'password' => $password,
        ];
    }

    /**
     * 生成 VLESS 内部链路的稳定身份及传输认证信息。
     *
     * UUID 的路由字节固定为 0，避免内部链路身份意外携带面向客户端的 vlessRoute。
     */
    public static function vlessTransitCredential(Server $child): array
    {
        $material = self::deriveMaterial($child, 'xboard-relay-vless');
        $bytes = substr($material, 0, 16);
        $bytes[6] = "\x00";
        $bytes[7] = "\x00";
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return [
            'id' => sprintf(
                '%s-%s-%s-%s-%s',
                substr($hex, 0, 8),
                substr($hex, 8, 4),
                substr($hex, 12, 4),
                substr($hex, 16, 4),
                substr($hex, 20, 12),
            ),
            // Hysteria 是传输层，这个认证值与 VLESS UUID 使用不同派生域。
            'transport_auth' => rtrim(strtr(base64_encode(
                self::deriveMaterial($child, 'xboard-relay-vless-hysteria')
            ), '+/', '-_'), '='),
        ];
    }

    /**
     * 生成只供入口端使用的 VLESS 客户端配置。
     *
     * 这里采用白名单组装，Reality 私钥、VLESS decryption、证书和 ECH 私钥都不会下发给入口。
     */
    public static function vlessClientConfig(Server $child): array
    {
        $settings = (array) $child->protocol_settings;
        $credential = self::vlessTransitCredential($child);
        $network = self::normalizeVlessNetwork(data_get($settings, 'network')) ?? 'tcp';
        $tls = (int) data_get($settings, 'tls', 0);
        $flow = trim((string) data_get($settings, 'flow'));
        if ($flow === 'none') {
            $flow = '';
        }

        $config = [
            'id' => $credential['id'],
            'network' => $network,
            'network_settings' => self::vlessClientNetworkSettings(
                $network,
                (array) data_get($settings, 'network_settings', []),
            ),
            'tls' => $tls,
            'flow' => $flow,
            'encryption' => data_get($settings, 'encryption.enabled')
                ? trim((string) data_get($settings, 'encryption.encryption'))
                : 'none',
        ];

        if ($network === 'hysteria') {
            $config['transport_auth'] = $credential['transport_auth'];
        }

        if ($tls === 1) {
            $config['tls_settings'] = array_filter([
                'server_name' => trim((string) data_get($settings, 'tls_settings.server_name')),
                'fingerprint' => self::tlsFingerprint($settings),
            ], fn($value) => $value !== '');
        } elseif ($tls === 2) {
            $config['reality_settings'] = [
                'server_name' => trim((string) data_get($settings, 'reality_settings.server_name')),
                'public_key' => trim((string) data_get($settings, 'reality_settings.public_key')),
                'short_id' => trim((string) data_get($settings, 'reality_settings.short_id')),
                'fingerprint' => self::tlsFingerprint($settings) ?: 'chrome',
            ];
        }

        return $config;
    }

    private static function deriveMaterial(Server $child, string $domain): string
    {
        return hash_hmac(
            'sha256',
            implode('|', [
                $domain,
                (string) $child->id,
                (string) $child->relay_entry_id,
                (string) $child->getRawOriginal('created_at'),
            ]),
            (string) config('app.key'),
            true
        );
    }

    private static function tlsFingerprint(array $settings): string
    {
        if (!data_get($settings, 'utls.enabled')) {
            return '';
        }

        return trim((string) data_get($settings, 'utls.fingerprint', 'chrome')) ?: 'chrome';
    }

    /** 只复制传输客户端实际需要的字段，避免任意 extra 配置带出服务端私密字段。 */
    private static function vlessClientNetworkSettings(string $network, array $settings): array
    {
        $keys = match ($network) {
            'tcp' => ['header'],
            'ws' => ['path', 'host', 'headers', 'heartbeatPeriod'],
            'grpc' => ['serviceName', 'service_name', 'authority', 'multiMode', 'idle_timeout',
                'health_check_timeout', 'permit_without_stream', 'initial_windows_size', 'user_agent'],
            'httpupgrade' => ['path', 'host', 'headers'],
            'xhttp' => ['path', 'host', 'mode', 'headers', 'extra'],
            'kcp' => ['mtu', 'tti', 'uplinkCapacity', 'downlinkCapacity', 'cwndMultiplier', 'maxSendingWindow'],
            'hysteria' => ['udpIdleTimeout'],
            default => [],
        };

        $client = collect($settings)->only($keys)->all();
        if ($network === 'xhttp' && isset($client['extra']) && is_array($client['extra'])) {
            $client['extra'] = self::stripServerSecrets($client['extra']);
        }

        return $client;
    }

    /** 递归移除 XHTTP downloadSettings 中可能嵌入的服务端私密字段。 */
    private static function stripServerSecrets(array $value): array
    {
        $blocked = [
            'privatekey',
            'decryption',
            'certificates',
            'echserverkeys',
            'keycontent',
        ];
        $result = [];
        foreach ($value as $key => $item) {
            $normalized = strtolower(str_replace(['_', '-'], '', (string) $key));
            if (in_array($normalized, $blocked, true)) {
                continue;
            }
            $result[$key] = is_array($item) ? self::stripServerSecrets($item) : $item;
        }

        return $result;
    }

    public static function normalizeVlessNetwork(?string $network): ?string
    {
        $network = strtolower(trim((string) $network));
        return self::VLESS_NETWORKS[$network] ?? null;
    }

    /** 校验协议自身是否能作为内部链路。合法时返回 null。 */
    public static function validateTransitSettings(?string $type, array $settings, ?string $host): ?string
    {
        $type = Server::normalizeType($type);
        if ($type === Server::TYPE_SHADOWSOCKS) {
            $cipher = data_get($settings, 'cipher');
            return self::isSupportedTransitCipher($cipher)
                ? null
                : '中转逻辑节点的加密算法不受支持，可选：' . implode('、', self::supportedTransitCiphers());
        }

        if ($type !== Server::TYPE_VLESS) {
            return '中转逻辑节点只支持 Shadowsocks 或 VLESS 协议作为入口到落地之间的中转';
        }

        $rawNetwork = strtolower(trim((string) data_get($settings, 'network')));
        // 兼容早期未显式保存 network 的 VLESS 节点；Xray 的默认传输即 RAW/TCP。
        if ($rawNetwork === '') {
            $rawNetwork = 'tcp';
        }
        if (in_array($rawNetwork, ['h2', 'http'], true)) {
            return '当前 YZ-Xray-core 已移除 H2/HTTP 传输，请改用 XHTTP';
        }
        $network = self::normalizeVlessNetwork($rawNetwork);
        if ($network === null) {
            return 'VLESS 中转不支持该传输，可选：RAW/TCP、WS、gRPC、XHTTP、HTTPUpgrade、mKCP、Hysteria';
        }

        $tls = (int) data_get($settings, 'tls', 0);
        if (!in_array($tls, [0, 1, 2], true)) {
            return 'VLESS 中转的传输安全只能是无、TLS 或 Reality';
        }
        if ($tls === 2 && !in_array($network, self::REALITY_NETWORKS, true)) {
            return 'Reality 仅支持 RAW/TCP、XHTTP 和 gRPC 传输';
        }
        if ($network === 'hysteria' && $tls !== 1) {
            return 'Hysteria 传输必须启用 TLS，且不能使用 Reality';
        }

        $flow = trim((string) data_get($settings, 'flow'));
        if (!in_array($flow, ['', 'none', 'xtls-rprx-vision'], true)) {
            return '当前 YZ-Xray-core 的 VLESS Flow 只支持空值或 xtls-rprx-vision';
        }

        if (data_get($settings, 'tls_settings.ech.enabled')) {
            return 'VLESS 中转暂不支持 ECH，请关闭 ECH 后再设置前置入口';
        }
        if ($tls === 1 && data_get($settings, 'tls_settings.allow_insecure')) {
            return '当前 YZ-Xray-core 已移除 allowInsecure，VLESS 中转不能启用“允许不安全连接”';
        }

        if ($tls === 2) {
            foreach (['server_name' => '伪装域名', 'public_key' => '公钥', 'private_key' => '私钥'] as $key => $label) {
                if (trim((string) data_get($settings, "reality_settings.{$key}")) === '') {
                    return "VLESS Reality 中转缺少{$label}";
                }
            }
        }

        $networkSettings = (array) data_get($settings, 'network_settings', []);
        if ($network === 'kcp' && (array_key_exists('header', $networkSettings) || array_key_exists('seed', $networkSettings))) {
            return '当前 YZ-Xray-core 已移除 mKCP header 和 seed，请删除后再使用';
        }

        $encryptionEnabled = (bool) data_get($settings, 'encryption.enabled', false);
        if ($encryptionEnabled) {
            if (!self::looksLikeVlessEncryption((string) data_get($settings, 'encryption.encryption'), false)
                || !self::looksLikeVlessEncryption((string) data_get($settings, 'encryption.decryption'), true)) {
                return 'VLESS Encryption 配置无效，请填写同一次 vlessenc 生成的 encryption 和 decryption';
            }
        } elseif ($tls === 0 && !self::isPrivateAddress((string) $host)) {
            return '公网 VLESS 中转在未启用 TLS/Reality 时必须启用 VLESS Encryption';
        }

        return null;
    }

    private static function looksLikeVlessEncryption(string $value, bool $decryption): bool
    {
        $parts = explode('.', trim($value));
        if (count($parts) < 4 || $parts[0] !== 'mlkem768x25519plus'
            || !in_array($parts[1], ['native', 'xorpub', 'random'], true)) {
            return false;
        }

        if (!$decryption && !in_array($parts[2], ['0rtt', '1rtt'], true)) {
            return false;
        }
        if ($decryption && !preg_match('/^\d+(?:-\d+)?s$/', $parts[2])) {
            return false;
        }

        $allowedKeyLengths = $decryption ? [32, 64] : [32, 1184];
        $hasKey = false;
        foreach (array_slice($parts, 3) as $part) {
            // 短段是 Xray 支持的可选 padding；较长的段必须是合法密钥。
            if (strlen($part) < 20) {
                continue;
            }

            $decodedLength = self::base64UrlDecodedLength($part);
            if ($decodedLength === null || !in_array($decodedLength, $allowedKeyLengths, true)) {
                return false;
            }
            $hasKey = true;
        }

        return $hasKey;
    }

    private static function base64UrlDecodedLength(string $value): ?int
    {
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $value) || strlen($value) % 4 === 1) {
            return null;
        }

        $padding = (4 - strlen($value) % 4) % 4;
        $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', $padding), true);

        return $decoded === false ? null : strlen($decoded);
    }

    private static function isPrivateAddress(string $host): bool
    {
        $host = trim($host, " \t\n\r\0\x0B[]");
        if ($host === '' || strtolower($host) === 'localhost' || str_ends_with(strtolower($host), '.local')) {
            return true;
        }
        if (!filter_var($host, FILTER_VALIDATE_IP)) {
            return false;
        }

        return filter_var(
            $host,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) === false;
    }

    /**
     * 校验前置入口设置。返回错误信息，合法时返回 null。
     *
     * @param int|null $selfId 正在保存的节点 ID，新建时为 null
     */
    public static function validateEntry(
        ?int $selfId,
        ?int $entryId,
        ?string $type,
        array $protocolSettings = [],
        ?string $host = null,
    ): ?string
    {
        // 0 与 null 都表示“不使用中转”，管理端会把“无”提交为 0。
        if (!$entryId) {
            // 当前节点若已被其它节点引用，就必须继续保持一个有效的 VLESS 入口。
            if ($selfId !== null && Server::where('relay_entry_id', $selfId)->exists()) {
                if (Server::normalizeType($type) !== self::ENTRY_TYPE) {
                    return '该节点已被其它节点用作前置入口，协议必须保持 VLESS';
                }
                if ($error = self::validateTransitSettings($type, $protocolSettings, $host)) {
                    return '该节点已被其它节点用作前置入口：' . $error;
                }
            }

            return null;
        }

        if ($selfId !== null && $selfId === $entryId) {
            return '前置入口不能是节点自身';
        }

        if ($error = self::validateTransitSettings($type, $protocolSettings, $host)) {
            return $error;
        }

        $entry = Server::find($entryId);
        if (!$entry) {
            return '前置入口节点不存在';
        }

        if ($entry->type !== self::ENTRY_TYPE) {
            return '前置入口必须是 VLESS 节点';
        }

        if ($error = self::validateTransitSettings(
            $entry->type,
            (array) $entry->protocol_settings,
            (string) $entry->host,
        )) {
            return '前置入口配置无效：' . $error;
        }

        if ($entry->relayEntryId() !== null) {
            return '不支持多层中转，入口节点自身不能再设置前置入口';
        }

        if ($selfId !== null && Server::where('relay_entry_id', $selfId)->exists()) {
            return '该节点已经是其它节点的前置入口，不能再设置前置入口';
        }

        return null;
    }
}
