<?php

namespace App\Services;

use App\Support\Setting;
use App\Models\Setting as SettingModel;
use Illuminate\Support\Facades\Cache;

class LogSettings
{
    public const DEFAULT_DAYS = ['system' => 14, 'audit' => 90, 'agent' => 7, 'user' => 30, 'access' => 7];
    public const MAX_DAYS = 365;

    public static function all(): array
    {
        // 长连接、队列和日志处理器也必须读取最新策略，不能复用已加载的请求设置。
        $saved = (new Setting())->getOrFail('log_settings', []);
        return self::normalize($saved);
    }

    private static function normalize(array $saved): array
    {
        $settings = [];
        foreach (self::DEFAULT_DAYS as $module => $days) {
            $value = $saved[$module] ?? [];
            $settings[$module] = [
                'enabled' => (bool) ($value['enabled'] ?? true),
                'retention_days' => max(1, min(self::MAX_DAYS, (int) ($value['retention_days'] ?? $days))),
                'record_from' => max(0, (int) ($value['record_from'] ?? 0)),
            ];
            if ($module === 'access') {
                $settings[$module]['generation'] = (string) ($value['generation'] ?? '');
            }
        }
        return $settings;
    }

    public static function view(): array
    {
        return array_map(fn ($value) => array_intersect_key($value, array_flip(['enabled', 'retention_days'])), self::all());
    }

    public static function days(string $module): int
    {
        return self::all()[$module]['retention_days'];
    }

    public static function enabled(string $module): bool
    {
        return self::all()[$module]['enabled'];
    }

    public static function recording(string $module): array
    {
        $value = self::all()[$module];
        return [$value['enabled'], max(now()->timestamp - $value['retention_days'] * 86400, $value['record_from'])];
    }

    public static function save(array $settings): void
    {
        // 按实际已保存状态串行切换，避免并发保存恢复旧的停用代次。
        Cache::lock('log_settings:save', 10)->block(5, function () use ($settings): void {
            $saved = SettingModel::where('name', 'log_settings')->value('value') ?? [];
            $previous = self::normalize($saved);
            foreach ($settings as $module => &$value) {
                $value['enabled'] = (bool) $value['enabled'];
                $value['retention_days'] = (int) $value['retention_days'];
                // 重新开启后不补存关闭期间的延迟上报。
                $value['record_from'] = !$previous[$module]['enabled'] && $value['enabled']
                    ? now()->timestamp : $previous[$module]['record_from'];
                if ($module === 'access') {
                    $value['generation'] = $previous[$module]['enabled'] && !$value['enabled']
                        ? bin2hex(random_bytes(16)) : $previous[$module]['generation'];
                }
            }
            unset($value);
            // 开关和代次保存在同一设置值中，不新增表或迁移。
            (new Setting())->set('log_settings', $settings);
        });
    }
}
