<?php

namespace App\Services;

use App\Models\User;

/** 只检查下载软件上报的名称，订阅格式参数不参与判断。 */
class SubscriptionClientControl
{
    public const SETTING = 'subscribe_client_control';

    public static function defaults(): array
    {
        return [
            'mode' => 'off',
            'clients' => [
                ['name' => 'Clash', 'enabled' => true, 'keywords' => ['clash', 'mihomo']],
                ['name' => 'sing-box', 'enabled' => true, 'keywords' => ['sing-box', 'singbox']],
            ],
            'exempt_group_ids' => [],
        ];
    }

    public static function settings(): array
    {
        return admin_setting(self::SETTING, self::defaults());
    }

    /** 保存整个规则对象，避免模式和名单分开写入时出现中间状态。 */
    public static function normalize(array $settings): array
    {
        return [
            'mode' => $settings['mode'],
            'clients' => array_map(fn (array $client) => [
                'name' => trim($client['name']),
                'enabled' => (bool) $client['enabled'],
                'keywords' => array_values(array_unique(array_map(
                    fn (string $keyword) => mb_strtolower(trim($keyword)),
                    $client['keywords']
                ))),
            ], $settings['clients']),
            'exempt_group_ids' => array_values(array_unique(array_map('intval', $settings['exempt_group_ids']))),
        ];
    }

    public function allows(User $user, string $userAgent): bool
    {
        $settings = self::settings();
        if ($settings['mode'] === 'off') {
            return true;
        }

        $groupIds = $user->effectiveGroupIds();
        // 无权限组不豁免；全部当前有效权限组都在名单内才跳过检查。
        if ($groupIds && !array_diff($groupIds, $settings['exempt_group_ids'])) {
            return true;
        }

        $matched = false;
        foreach ($settings['clients'] as $client) {
            if (!$client['enabled']) {
                continue;
            }
            foreach ($client['keywords'] as $keyword) {
                // 关键词按字面包含匹配，不执行正则或通配符。
                if ($keyword !== '' && mb_stripos($userAgent, $keyword) !== false) {
                    $matched = true;
                    break 2;
                }
            }
        }

        return $settings['mode'] === 'whitelist' ? $matched : !$matched;
    }
}
