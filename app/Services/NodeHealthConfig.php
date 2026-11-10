<?php

namespace App\Services;

use App\Models\Server;

final class NodeHealthConfig
{
    public const SETTING = 'node_health_check';

    public static function defaults(): array
    {
        return ['enabled' => false, 'url' => 'https://www.gstatic.com/generate_204', 'interval' => 60];
    }

    public static function normalize(array $config): array
    {
        return ['enabled' => (bool) $config['enabled'], 'url' => $config['url'], 'interval' => (int) $config['interval']];
    }

    public static function global(): array
    {
        return self::normalize(array_replace(self::defaults(), (array) admin_setting(self::SETTING, [])));
    }

    public static function mode(Server $node): string
    {
        $config = $node->health_check ?? [];
        if (in_array($config['mode'] ?? null, ['inherit', 'on', 'off'], true)) return $config['mode'];
        // 旧版的明确开关继续作为单节点覆盖；未配置的节点跟随全局。
        return array_key_exists('enabled', $config) ? ($config['enabled'] ? 'on' : 'off') : 'inherit';
    }

    /** 下发格式保持 Agent 2.33.0 起使用的协议，地址和间隔统一取全局值。 */
    public static function effective(Server $node): array
    {
        $config = self::global();
        $config['enabled'] = match (self::mode($node)) {
            'on' => true,
            'off' => false,
            default => $config['enabled'],
        };
        if ($config['enabled'] && !empty($node->health_check['request'])) {
            $config['request'] = $node->health_check['request'];
        }
        return $config;
    }
}
