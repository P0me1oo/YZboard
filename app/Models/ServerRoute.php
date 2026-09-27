<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * App\Models\ServerRoute
 *
 * @property int $id
 * @property string $remarks 备注
 * @property array $match 目标域名或 IP 网段，任一命中即可
 * @property array|null $protocol 内核嗅探识别的协议，目前只有 bittorrent
 * @property string|null $port 目标端口或范围，逗号分隔
 * @property string|null $network tcp 或 udp，空值表示不限
 * @property string $action 动作：block/direct/dns/proxy
 * @property string|null $action_value 动作参数
 * @property bool $apply_to_new_nodes 新建节点时是否默认选中
 */
class ServerRoute extends Model
{
    /** 可按协议匹配的取值，依赖 Node 内核嗅探识别。 */
    public const PROTOCOLS = ['bittorrent'];

    public const NETWORKS = ['tcp', 'udp'];

    public const ACTION_DNS = 'dns';

    protected $table = 'v2_server_route';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'match' => 'array',
        'protocol' => 'array',
        'apply_to_new_nodes' => 'boolean',
    ];

    /**
     * 下发给 Node 的路由。未设置的附加条件不下发，未使用新条件的路由与旧版格式相同。
     */
    public function toNodeConfig(): array
    {
        $config = [
            'id' => (int) $this->id,
            'match' => self::normalizeMatch($this->match),
            'action' => $this->action,
            'action_value' => $this->action_value,
        ];
        if ($protocol = self::normalizeProtocol($this->protocol)) {
            $config['protocol'] = $protocol;
        }
        if (filled($this->port)) {
            $config['port'] = (string) $this->port;
        }
        if (in_array($this->network, self::NETWORKS, true)) {
            $config['network'] = $this->network;
        }

        return $config;
    }

    /** 去掉空白项和重复项，并保证是 JSON 数组而不是对象。 */
    public static function normalizeMatch(mixed $match): array
    {
        $result = [];
        foreach ((array) ($match ?? []) as $item) {
            if (!is_scalar($item)) {
                continue;
            }
            $item = trim((string) $item);
            if ($item !== '' && !in_array($item, $result, true)) {
                $result[] = $item;
            }
        }

        return $result;
    }

    public static function normalizeProtocol(mixed $protocol): array
    {
        $result = [];
        foreach ((array) ($protocol ?? []) as $item) {
            $item = is_scalar($item) ? strtolower(trim((string) $item)) : '';
            if (in_array($item, self::PROTOCOLS, true) && !in_array($item, $result, true)) {
                $result[] = $item;
            }
        }

        return $result;
    }
}
