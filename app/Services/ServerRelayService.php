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
 * 约定：节点的父级节点非空时，该节点是“中转逻辑节点”，父级节点是客户端真实连接的入口。
 * 逻辑节点自身的协议、地址、端口描述的是入口到落地服务器之间的内部链路，不会出现在用户订阅中。
 * 第一版只支持“一个真实入口 + 一层落地”，且入口到落地固定使用 Shadowsocks。
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

        $entry = $child->parent;
        if (!$entry || $entry->id === $child->id) {
            return null;
        }

        // 只支持一层中转：入口自身不能再挂在别的节点下面。
        if ($entry->relayParentId() !== null) {
            return null;
        }

        if ($entry->type !== self::ENTRY_TYPE) {
            return null;
        }

        return $entry;
    }

    /**
     * 取得以该节点为入口、且处于启用状态的中转逻辑节点。
     */
    public static function childrenOf(Server $entry): Collection
    {
        if ($entry->relayParentId() !== null || $entry->type !== self::ENTRY_TYPE) {
            return collect();
        }

        return Server::where('parent_id', $entry->id)
            ->whereIn('type', Server::RELAY_TRANSIT_TYPES)
            ->where(function ($query) {
                $query->where('enabled', true)->orWhereNull('enabled');
            })
            ->orderBy('sort', 'ASC')
            ->get()
            ->filter(fn(Server $child) => self::isSupportedTransitCipher(
                data_get($child->protocol_settings, 'cipher')
            ))
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
                (string) $child->parent_id,
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
     * 校验父级节点设置。返回错误信息，合法时返回 null。
     *
     * @param int|null $selfId 正在保存的节点 ID，新建时为 null
     */
    public static function validateParent(?int $selfId, ?int $parentId, ?string $type, ?string $cipher): ?string
    {
        // 0 与 null 都表示“没有父级节点”，历史数据用 0 填充。
        if (!$parentId) {
            return null;
        }

        if ($selfId !== null && $selfId === $parentId) {
            return '父级节点不能是节点自身';
        }

        $normalizedType = Server::normalizeType($type);
        if (!in_array($normalizedType, Server::RELAY_TRANSIT_TYPES, true)) {
            return '中转逻辑节点当前只支持 Shadowsocks 协议作为入口到落地之间的中转';
        }

        if (!self::isSupportedTransitCipher($cipher)) {
            return '中转逻辑节点的加密算法不受支持，可选：' . implode('、', self::supportedTransitCiphers());
        }

        $parent = Server::find($parentId);
        if (!$parent) {
            return '父级节点不存在';
        }

        if ($parent->type !== self::ENTRY_TYPE) {
            return '父级节点必须是 VLESS 入口节点';
        }

        if ($parent->relayParentId() !== null) {
            return '不支持多层中转，父级节点自身不能再设置父级节点';
        }

        if ($selfId !== null && Server::where('parent_id', $selfId)->exists()) {
            return '该节点已经是其它节点的父级入口，不能再设置父级节点';
        }

        return null;
    }
}
