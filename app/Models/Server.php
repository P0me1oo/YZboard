<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use App\Services\ServerRelayService;
use App\Utils\CacheKey;
use App\Utils\Helper;
use App\Models\User;
use Illuminate\Database\Eloquent\Casts\Attribute;

/**
 * App\Models\Server
 *
 * @property int $id
 * @property string $name 节点名称
 * @property string $type 服务类型
 * @property string $host 主机地址
 * @property string|int $port 端口
 * @property int|null $server_port 服务器端口
 * @property array|null $group_ids 分组IDs
 * @property array|null $route_ids 路由IDs
 * @property array|null $tags 标签
 * @property boolean $show 是否显示
 * @property string|null $allow_insecure 是否允许不安全
 * @property string|null $network 网络类型
 * @property int|null $parent_id 父节点ID（非空表示本节点是中转逻辑节点，父节点为客户端真实入口）
 * @property int|null $vless_route VLESS路由编号（写入客户端UUID第7、8字节）
 * @property float|null $rate 倍率
 * @property boolean $rate_time_enable 是否启用时间范围功能
 * @property array|null $rate_time_ranges 倍率时间范围
 * @property int|null $sort 排序
 * @property array|null $protocol_settings 协议设置
 * @property int $created_at
 * @property int $updated_at
 * 
 * @property-read Server|null $parent 父节点
 * @property-read \Illuminate\Database\Eloquent\Collection<int, StatServer> $stats 节点统计
 * 
 * @property-read int|null $last_check_at 最后检查时间（Unix时间戳）
 * @property-read int|null $last_push_at 最后推送时间（Unix时间戳）
 * @property-read int $online 在线用户数
 * @property-read int $online_conn 在线连接数
 * @property-read array|null $metrics 节点指标指标
 * @property-read int $is_online 是否在线（1在线 0离线）
 * @property-read string $available_status 可用状态描述
 * @property-read string $cache_key 缓存键
 * @property string|null $ports 端口范围
 * @property string|null $password 密码
 * @property int|null $u 上行流量
 * @property int|null $d 下行流量
 * @property int|null $total 总流量
 * @property-read array|null $load_status 负载状态（包含CPU、内存、交换区、磁盘信息）
 * 
 * @property int $transfer_enable 流量上限，0或者null表示不限制
 * @property int $u 当前上传流量
 * @property int $d 当前下载流量
 */
class Server extends Model
{
    public const TYPE_HYSTERIA = 'hysteria';
    public const TYPE_VLESS = 'vless';
    public const TYPE_TROJAN = 'trojan';
    public const TYPE_VMESS = 'vmess';
    public const TYPE_TUIC = 'tuic';
    public const TYPE_SHADOWSOCKS = 'shadowsocks';
    public const TYPE_ANYTLS = 'anytls';
    public const TYPE_SOCKS = 'socks';
    public const TYPE_NAIVE = 'naive';
    public const TYPE_HTTP = 'http';
    public const TYPE_MIERU = 'mieru';
    public const STATUS_OFFLINE = 0;
    public const STATUS_ONLINE_NO_PUSH = 1;
    public const STATUS_ONLINE = 2;

    public const CHECK_INTERVAL = 300; // 5 minutes in seconds

    /** VLESS 路由编号分配游标的设置项名称。删除节点不会回退该游标，避免立即复用编号。 */
    public const ROUTE_CURSOR_SETTING = 'vless_route_cursor';

    /** 路由编号占用 UUID 的两个字节。Xray 端口列表解析会丢弃数字 0，因此从 1 开始。 */
    public const ROUTE_ID_MIN = 1;
    public const ROUTE_ID_MAX = 65535;

    /** 第一版只支持 Shadowsocks 作为入口到落地之间的中转协议。 */
    public const RELAY_TRANSIT_TYPES = [
        self::TYPE_SHADOWSOCKS,
    ];

    private const CIPHER_CONFIGURATIONS = [
        '2022-blake3-aes-128-gcm' => [
            'serverKeySize' => 16,
            'userKeySize' => 16,
        ],
        '2022-blake3-aes-256-gcm' => [
            'serverKeySize' => 32,
            'userKeySize' => 32,
        ],
        '2022-blake3-chacha20-poly1305' => [
            'serverKeySize' => 32,
            'userKeySize' => 32,
        ]
    ];

    public const TYPE_ALIASES = [
        'v2ray' => self::TYPE_VMESS,
        'hysteria2' => self::TYPE_HYSTERIA,
    ];

    public const VALID_TYPES = [
        self::TYPE_HYSTERIA,
        self::TYPE_VLESS,
        self::TYPE_TROJAN,
        self::TYPE_VMESS,
        self::TYPE_TUIC,
        self::TYPE_SHADOWSOCKS,
        self::TYPE_ANYTLS,
        self::TYPE_SOCKS,
        self::TYPE_NAIVE,
        self::TYPE_HTTP,
        self::TYPE_MIERU,
    ];

    protected $table = 'v2_server';

    protected $guarded = ['id'];
    protected $casts = [
        'group_ids' => 'array',
        'route_ids' => 'array',
        'tags' => 'array',
        'protocol_settings' => 'array',
        'custom_outbounds' => 'array',
        'custom_routes' => 'array',
        'cert_config' => 'array',
        'last_check_at' => 'integer',
        'last_push_at' => 'integer',
        'show' => 'boolean',
        'enabled' => 'boolean',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'rate_time_ranges' => 'array',
        'rate_time_enable' => 'boolean',
        'transfer_enable' => 'integer',
        'u' => 'integer',
        'd' => 'integer',
        'machine_id' => 'integer',
        'vless_route' => 'integer',
    ];

    private const MULTIPLEX_CONFIGURATION = [
        'multiplex' => [
            'type' => 'object',
            'fields' => [
                'enabled' => ['type' => 'boolean', 'default' => false],
                'protocol' => ['type' => 'string', 'default' => 'yamux'],
                'max_connections' => ['type' => 'integer', 'default' => null],
                // 'min_streams' => ['type' => 'integer', 'default' => null],
                // 'max_streams' => ['type' => 'integer', 'default' => null],
                'padding' => ['type' => 'boolean', 'default' => false],
                'brutal' => [
                    'type' => 'object',
                    'fields' => [
                        'enabled' => ['type' => 'boolean', 'default' => false],
                        'up_mbps' => ['type' => 'integer', 'default' => null],
                        'down_mbps' => ['type' => 'integer', 'default' => null],
                    ]
                ]
            ]
        ]
    ];

    private const REALITY_CONFIGURATION = [
        'reality_settings' => [
            'type' => 'object',
            'fields' => [
                'server_name' => ['type' => 'string', 'default' => null],
                'server_port' => ['type' => 'string', 'default' => null],
                'public_key' => ['type' => 'string', 'default' => null],
                'private_key' => ['type' => 'string', 'default' => null],
                'short_id' => ['type' => 'string', 'default' => null],
                'allow_insecure' => ['type' => 'boolean', 'default' => false],
            ]
        ]
    ];

    private const UTLS_CONFIGURATION = [
        'utls' => [
            'type' => 'object',
            'fields' => [
                'enabled' => ['type' => 'boolean', 'default' => false],
                'fingerprint' => ['type' => 'string', 'default' => 'chrome'],
            ]
        ]
    ];

    private const ECH_CONFIGURATION = [
        'ech' => [
            'type' => 'object',
            'fields' => [
                'enabled' => ['type' => 'boolean', 'default' => false],
                'config' => ['type' => 'string', 'default' => null],
                'query_server_name' => ['type' => 'string', 'default' => null],
                'key' => ['type' => 'string', 'default' => null],
                'key_path' => ['type' => 'string', 'default' => null],
                'config_path' => ['type' => 'string', 'default' => null],
            ]
        ]
    ];

    private const TLS_SETTINGS_CONFIGURATION = [
        'type' => 'object',
        'fields' => [
            'server_name' => ['type' => 'string', 'default' => null],
            'allow_insecure' => ['type' => 'boolean', 'default' => false],
            ...self::ECH_CONFIGURATION,
        ]
    ];

    private const TLS_CONFIGURATION = [
        'type' => 'object',
        'fields' => [
            'server_name' => ['type' => 'string', 'default' => null],
            'allow_insecure' => ['type' => 'boolean', 'default' => false],
            ...self::ECH_CONFIGURATION,
        ]
    ];

    private const PROTOCOL_CONFIGURATIONS = [
        self::TYPE_TROJAN => [
            'tls' => ['type' => 'integer', 'default' => 1],
            'network' => ['type' => 'string', 'default' => null],
            'network_settings' => ['type' => 'array', 'default' => null],
            'server_name' => ['type' => 'string', 'default' => null],
            'allow_insecure' => ['type' => 'boolean', 'default' => false],
            'tls_settings' => self::TLS_SETTINGS_CONFIGURATION,
            ...self::REALITY_CONFIGURATION,
            ...self::MULTIPLEX_CONFIGURATION,
            ...self::UTLS_CONFIGURATION
        ],
        self::TYPE_VMESS => [
            'tls' => ['type' => 'integer', 'default' => 0],
            'network' => ['type' => 'string', 'default' => null],
            'rules' => ['type' => 'array', 'default' => null],
            'network_settings' => ['type' => 'array', 'default' => null],
            'tls_settings' => self::TLS_SETTINGS_CONFIGURATION,
            ...self::MULTIPLEX_CONFIGURATION,
            ...self::UTLS_CONFIGURATION
        ],
        self::TYPE_VLESS => [
            'tls' => ['type' => 'integer', 'default' => 0],
            'tls_settings' => self::TLS_SETTINGS_CONFIGURATION,
            'flow' => ['type' => 'string', 'default' => null],
            'encryption' => [
                'type' => 'object',
                'default' => null,
                'fields' => [
                    'enabled' => ['type' => 'boolean', 'default' => false],
                    'encryption' => ['type' => 'string', 'default' => null],  // 客户端公钥
                    'decryption' => ['type' => 'string', 'default' => null],   // 服务端私钥
                ]
            ],
            'network' => ['type' => 'string', 'default' => null],
            'network_settings' => ['type' => 'array', 'default' => null],
            ...self::REALITY_CONFIGURATION,
            ...self::MULTIPLEX_CONFIGURATION,
            ...self::UTLS_CONFIGURATION
        ],
        self::TYPE_SHADOWSOCKS => [
            'cipher' => ['type' => 'string', 'default' => null],
            'obfs' => ['type' => 'string', 'default' => null],
            'obfs_settings' => ['type' => 'array', 'default' => null],
            'plugin' => ['type' => 'string', 'default' => null],
            'plugin_opts' => ['type' => 'string', 'default' => null]
        ],
        self::TYPE_HYSTERIA => [
            'version' => ['type' => 'integer', 'default' => 2],
            'bandwidth' => [
                'type' => 'object',
                'fields' => [
                    'up' => ['type' => 'integer', 'default' => null],
                    'down' => ['type' => 'integer', 'default' => null]
                ]
            ],
            'obfs' => [
                'type' => 'object',
                'fields' => [
                    'open' => ['type' => 'boolean', 'default' => false],
                    'type' => ['type' => 'string', 'default' => 'salamander'],
                    'password' => ['type' => 'string', 'default' => null]
                ]
            ],
            'tls' => self::TLS_CONFIGURATION,
            'hop_interval' => ['type' => 'integer', 'default' => null]
        ],
        self::TYPE_TUIC => [
            'version' => ['type' => 'integer', 'default' => 5],
            'congestion_control' => ['type' => 'string', 'default' => 'cubic'],
            'alpn' => ['type' => 'array', 'default' => ['h3']],
            'udp_relay_mode' => ['type' => 'string', 'default' => 'native'],
            'tls' => self::TLS_CONFIGURATION
        ],
        self::TYPE_ANYTLS => [
            'padding_scheme' => [
                'type' => 'array',
                'default' => [
                    "stop=8",
                    "0=30-30",
                    "1=100-400",
                    "2=400-500,c,500-1000,c,500-1000,c,500-1000,c,500-1000",
                    "3=9-9,500-1000",
                    "4=500-1000",
                    "5=500-1000",
                    "6=500-1000",
                    "7=500-1000"
                ]
            ],
            'tls' => self::TLS_CONFIGURATION
        ],
        self::TYPE_SOCKS => [
            'tls' => ['type' => 'integer', 'default' => 0],
            'tls_settings' => self::TLS_SETTINGS_CONFIGURATION
        ],
        self::TYPE_NAIVE => [
            'tls' => ['type' => 'integer', 'default' => 0],
            'tls_settings' => self::TLS_SETTINGS_CONFIGURATION
        ],
        self::TYPE_HTTP => [
            'tls' => ['type' => 'integer', 'default' => 0],
            'tls_settings' => self::TLS_SETTINGS_CONFIGURATION
        ],
        self::TYPE_MIERU => [
            'transport' => ['type' => 'string', 'default' => 'TCP'],
            'traffic_pattern' => ['type' => 'string', 'default' => ''],
            ...self::MULTIPLEX_CONFIGURATION,
        ]
    ];

    protected static function booted(): void
    {
        // 路由编号由面板自动分配，管理端无需填写；新建节点（含复制）始终获得独立编号。
        static::creating(function (self $server) {
            $server->vless_route = ServerRelayService::allocateRouteId();
        });
    }

    private function castValueWithConfig($value, array $config)
    {
        if ($value === null && $config['type'] !== 'object') {
            return $config['default'] ?? null;
        }

        return match ($config['type']) {
            'integer' => (int) $value,
            'boolean' => (bool) $value,
            'string' => (string) $value,
            'array' => (array) $value,
            'object' => is_array($value) ?
            $this->castSettingsWithConfig($value, $config['fields']) :
            $config['default'] ?? null,
            default => $value
        };
    }

    private function castSettingsWithConfig(array $settings, array $configs): array
    {
        $result = [];
        foreach ($configs as $key => $config) {
            $value = $settings[$key] ?? null;
            $result[$key] = $this->castValueWithConfig($value, $config);
        }
        return $result;
    }

    public function getProtocolSettingsAttribute($value)
    {
        $settings = json_decode($value, true) ?? [];
        $configs = self::PROTOCOL_CONFIGURATIONS[$this->type] ?? [];
        return $this->castSettingsWithConfig($settings, $configs);
    }

    public function setProtocolSettingsAttribute($value)
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        $configs = self::PROTOCOL_CONFIGURATIONS[$this->type] ?? [];
        $castedSettings = $this->castSettingsWithConfig($value ?? [], $configs);

        $this->attributes['protocol_settings'] = json_encode($castedSettings);
    }

    public function generateServerPassword(User $user): string
    {
        if ($this->type !== self::TYPE_SHADOWSOCKS) {
            return $user->uuid;
        }


        $cipher = data_get($this, 'protocol_settings.cipher');
        if (!$cipher || !isset(self::CIPHER_CONFIGURATIONS[$cipher])) {
            return $user->uuid;
        }

        $config = self::CIPHER_CONFIGURATIONS[$cipher];
        // 旧语义的子节点与父节点共用 SS2022 服务端密钥；中转逻辑节点不向用户下发 SS 凭据，使用自身时间戳。
        $serverCreatedAt = ($this->parent_id && !$this->isRelayChild())
            ? $this->parent->created_at
            : $this->created_at;
        $serverKey = Helper::getServerKey($serverCreatedAt, $config['serverKeySize']);
        $userKey = Helper::uuidToBase64($user->uuid, $config['userKeySize']);
        return "{$serverKey}:{$userKey}";
    }

    public static function normalizeType(?string $type): string | null
    {
        return $type ? strtolower(self::TYPE_ALIASES[$type] ?? $type) : null;
    }
    
    public static function isValidType(?string $type): bool
    {
        return $type ? in_array(self::normalizeType($type), self::VALID_TYPES, true) : true;
    }

    public function getAvailableStatusAttribute(): int
    {
        $now = time();
        if (!$this->last_check_at || ($now - self::CHECK_INTERVAL) >= $this->last_check_at) {
            return self::STATUS_OFFLINE;
        }
        if (!$this->last_push_at || ($now - self::CHECK_INTERVAL) >= $this->last_push_at) {
            return self::STATUS_ONLINE_NO_PUSH;
        }
        return self::STATUS_ONLINE;
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id', 'id');
    }

    /**
     * 以本节点为入口的中转逻辑节点。
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id', 'id');
    }

    /**
     * 规范化后的父级节点 ID。
     *
     * 历史数据用 0 表示“没有父节点”（v2board 迁移遗留），自增主键也不会是 0，
     * 因此 0 和 null 一律视为未设置。所有中转判定都必须经过这里，不能直接比较 null。
     */
    public function relayParentId(): ?int
    {
        $parentId = (int) $this->parent_id;
        return $parentId > 0 ? $parentId : null;
    }

    /**
     * 是否为中转逻辑节点。
     *
     * 父级节点已设置且协议属于支持的中转协议时成立。协议不在支持范围内的历史数据
     * 仍按旧的“共享父节点运行状态”语义处理，避免升级后破坏既有节点。
     */
    public function isRelayChild(): bool
    {
        return $this->relayParentId() !== null
            && in_array($this->type, self::RELAY_TRANSIT_TYPES, true);
    }

    /**
     * 状态缓存所属的节点 ID。
     *
     * 中转逻辑节点在落地服务器上有自己的 Node 实例，心跳、在线数和负载都应使用自身
     * 数据；只有旧语义的子节点才继续读取父节点缓存。
     */
    protected function statusOwnerId(): int
    {
        if ($this->isRelayChild()) {
            return (int) $this->id;
        }
        return (int) ($this->parent_id ?: $this->id);
    }

    /**
     * 实际参与用户扣费的倍率。
     *
     * 中转逻辑节点强制继承入口节点的基础倍率和动态时段倍率，自身填写的倍率不生效。
     */
    public function getEffectiveRate(): float
    {
        if ($this->isRelayChild() && $this->parent) {
            return $this->parent->getCurrentRate();
        }
        return $this->getCurrentRate();
    }

    public function stats(): HasMany
    {
        return $this->hasMany(StatServer::class, 'server_id', 'id');
    }

    public function machine(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ServerMachine::class, 'machine_id');
    }

    public function groups()
    {
        return ServerGroup::whereIn('id', $this->group_ids ?? [])->get();
    }

    public function routes()
    {
        return ServerRoute::whereIn('id', $this->route_ids)->get();
    }

    /**
     * 最后检查时间访问器
     */
    protected function lastCheckAt(): Attribute
    {
        return Attribute::make(
            get: function () {
                $type = strtoupper($this->type);
                $serverId = $this->statusOwnerId();
                return Cache::get(CacheKey::get("SERVER_{$type}_LAST_CHECK_AT", $serverId));
            }
        );
    }

    /**
     * 最后推送时间访问器
     */
    protected function lastPushAt(): Attribute
    {
        return Attribute::make(
            get: function () {
                $type = strtoupper($this->type);
                $serverId = $this->statusOwnerId();
                return Cache::get(CacheKey::get("SERVER_{$type}_LAST_PUSH_AT", $serverId));
            }
        );
    }

    /**
     * 在线用户数访问器
     */
    protected function online(): Attribute
    {
        return Attribute::make(
            get: function () {
                $type = strtoupper($this->type);
                $serverId = $this->statusOwnerId();
                return Cache::get(CacheKey::get("SERVER_{$type}_ONLINE_USER", $serverId)) ?? 0;
            }
        );
    }

    /**
     * 是否在线访问器
     */
    protected function isOnline(): Attribute
    {
        return Attribute::make(
            get: function () {
                return (time() - 300 > $this->last_check_at) ? 0 : 1;
            }
        );
    }

    /**
     * 缓存键访问器
     */
    protected function cacheKey(): Attribute
    {
        return Attribute::make(
            get: function () {
                return "{$this->type}-{$this->id}-{$this->updated_at}-{$this->is_online}";
            }
        );
    }

    /**
     * 服务器密钥访问器
     */
    protected function serverKey(): Attribute
    {
        return Attribute::make(
            get: function () {
                if ($this->type === self::TYPE_SHADOWSOCKS) {
                    return Helper::getServerKey($this->created_at, 16);
                }
                return null;
            }
        );
    }

    /**
     * 指标指标访问器
     */
    protected function metrics(): Attribute
    {
        return Attribute::make(
            get: function () {
                $type = strtoupper($this->type);
                $serverId = $this->statusOwnerId();
                return Cache::get(CacheKey::get("SERVER_{$type}_METRICS", $serverId));
            }
        );
    }

    /**
     * 在线连接数访问器
     */
    protected function onlineConn(): Attribute
    {
        return Attribute::make(
            get: function () {
                return $this->metrics['active_connections'] ?? 0;
            }
        );
    }

    /**
     * 负载状态访问器
     */
    protected function loadStatus(): Attribute
    {
        return Attribute::make(
            get: function () {
                $type = strtoupper($this->type);
                $serverId = $this->statusOwnerId();
                return Cache::get(CacheKey::get("SERVER_{$type}_LOAD_STATUS", $serverId));
            }
        );
    }

    public function getCurrentRate(): float
    {
        if (!$this->rate_time_enable) {
            return (float) $this->rate;
        }

        $now = now()->format('H:i');
        $ranges = $this->rate_time_ranges ?? [];
        $matchedRange = collect($ranges)
            ->first(fn($range) => $now >= $range['start'] && $now <= $range['end']);
        
        return $matchedRange ? (float) $matchedRange['rate'] : (float) $this->rate;
    }
}
