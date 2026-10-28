<?php

namespace App\Services;

use App\Models\ServerGroup;
use App\Models\Setting as SettingModel;
use App\Models\User;
use App\Support\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** 只检查下载软件上报的名称，订阅格式参数不参与判断。 */
class SubscriptionClientControl
{
    public const SETTING = 'subscribe_client_control';
    private const SETTINGS_LOCK = 'subscription-client-control:settings-lock';

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

    /** 保存与删除权限组串行执行，提交后重新失效缓存。 */
    public static function changeSettings(callable $change): mixed
    {
        $cache = Cache::store('redis');
        return $cache->lock(self::SETTINGS_LOCK, 10)->block(2, function () use ($cache, $change) {
            try {
                return DB::transaction($change);
            } finally {
                $cache->forget(Setting::CACHE_KEY);
                app()->forgetInstance(Setting::class);
            }
        });
    }

    public static function saveSettings(array $settings): void
    {
        self::changeSettings(function () use ($settings): void {
            $settings = self::normalize($settings);
            $existing = ServerGroup::whereIn('id', $settings['exempt_group_ids'])->pluck('id')->all();
            // 请求校验完成后仍可能发生删除，写入前在同一把锁内再次核对。
            foreach ($settings['exempt_group_ids'] as $index => $id) {
                if (!in_array($id, $existing)) {
                    throw ValidationException::withMessages([
                        self::SETTING . ".exempt_group_ids.$index" => "豁免权限组 #$id 已删除或不存在，请移除后重试。",
                    ]);
                }
            }
            admin_setting([self::SETTING => $settings]);
        });
    }

    /** 在删除权限组的事务内调用，仅修改该组的豁免引用。 */
    public static function removeExemptGroup(int $groupId): void
    {
        $settings = SettingModel::where('name', self::SETTING)->lockForUpdate()->first()?->value;
        if (!is_array($settings) || !in_array($groupId, $settings['exempt_group_ids'] ?? [])) {
            return;
        }
        $settings['exempt_group_ids'] = array_values(array_filter(
            $settings['exempt_group_ids'], fn ($id) => (int) $id !== $groupId
        ));
        admin_setting([self::SETTING => $settings]);
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
