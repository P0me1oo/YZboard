<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\IpUtils;

/** 订阅白名单只接受地址或网段，不解析域名、端口和通配符。 */
class SubscriptionProtectionIp
{
    public static function normalize(string $value): ?string
    {
        $parts = explode('/', trim($value));
        if (count($parts) > 2 || str_contains($parts[0], '%')) {
            return null;
        }
        $binary = @inet_pton($parts[0]);
        if ($binary === false) {
            return null;
        }
        $maximum = strlen($binary) * 8;
        if (isset($parts[1]) && (!preg_match('/^(0|[1-9][0-9]{0,2})$/D', $parts[1]) || (int) $parts[1] > $maximum)) {
            return null;
        }
        $bits = isset($parts[1]) ? (int) $parts[1] : $maximum;
        if ($maximum === 128 && $bits >= 96 && str_starts_with($binary, str_repeat("\0", 10) . "\xff\xff")) {
            $binary = substr($binary, 12);
            $bits -= 96;
            $maximum = 32;
        }
        for ($index = 0; $index < strlen($binary); $index++) {
            $keep = max(0, min(8, $bits - $index * 8));
            $binary[$index] = chr(ord($binary[$index]) & ((0xff << (8 - $keep)) & 0xff));
        }
        $address = inet_ntop($binary);
        return $bits === $maximum ? $address : "$address/$bits";
    }

    public static function matches(string $ip, array $entries): bool
    {
        return $entries !== [] && IpUtils::checkIp($ip, $entries);
    }
}
