<?php

namespace App\Utils;

final class SourcePolicy
{
    public static function enabled(?array $policy): bool
    {
        return match ($policy['mode'] ?? null) {
            'blacklist', 'whitelist' => true,
            null => !empty($policy['block_cn']),
            default => false,
        };
    }
}
