<?php

namespace App\Services;

use App\Support\Setting;

class LogSettings
{
    public const DEFAULT_DAYS = ['system' => 14, 'audit' => 90, 'agent' => 7, 'user' => 30, 'access' => 7];
    public const MAX_DAYS = 365;

    public static function all(): array
    {
        // 长连接、队列和日志处理器也必须读取最新策略，不能复用已加载的请求设置。
        $saved = (new Setting())->getOrFail('log_settings', []);
        $settings = [];
        foreach (self::DEFAULT_DAYS as $module => $days) {
            $value = $saved[$module] ?? [];
            $settings[$module] = [
                'enabled' => (bool) ($value['enabled'] ?? true),
                'retention_days' => max(1, min(self::MAX_DAYS, (int) ($value['retention_days'] ?? $days))),
                'record_from' => max(0, (int) ($value['record_from'] ?? 0)),
            ];
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
        $previous = self::all();
        foreach ($settings as $module => &$value) {
            $value['enabled'] = (bool) $value['enabled'];
            $value['retention_days'] = (int) $value['retention_days'];
            // 重新开启后不补存关闭期间的延迟上报。
            $value['record_from'] = !$previous[$module]['enabled'] && $value['enabled']
                ? now()->timestamp : $previous[$module]['record_from'];
        }
        unset($value);
        (new Setting())->set('log_settings', $settings);
    }
}
