<?php

namespace App\Support;

class AdminAppearance
{
    public const SETTING = 'admin_sidebar_hidden';

    // 系统管理和系统设置始终保留，避免隐藏恢复入口。
    public const ITEMS = [
        'dashboard', 'statistics', 'traffic-overview', 'inbound-ip', 'subscription-statistics',
        'telegram-bot', 'plugin-management', 'theme-config', 'notice-management',
        'payment-config', 'knowledge-management', 'node-management',
        'machine-management', 'node-manage', 'permission-group-management', 'route-management',
        'subscription-management', 'plan-management', 'subscription-client-control', 'subscription-protection',
        'order-management', 'coupon-management', 'gift-card-management',
        'user-management', 'user-manage', 'ticket-management',
    ];

    public static function hidden(): array
    {
        $value = admin_setting(self::SETTING, []);
        return is_array($value)
            ? array_values(array_unique(array_filter($value, fn ($id) => is_string($id) && in_array($id, self::ITEMS, true))))
            : [];
    }
}
