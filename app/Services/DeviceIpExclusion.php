<?php

namespace App\Services;

/**
 * 前置服务器名单，用于排除转发机、链式前置机出口等共享地址。
 *
 * 名单保存在系统设置中，随节点配置下发；面板保存和读取设备记录时也按名单过滤，
 * 兼容尚未升级的 Node 和名单修改前留下的记录。
 */
class DeviceIpExclusion
{
    public const SETTING = 'device_ip_exclude';
    public const MAX_ENTRIES = 256;
    public const MIN_V4_BITS = 16;
    public const MIN_V6_BITS = 48;

    private static ?array $memoRaw = null;
    private static array $memoRules = ['exact' => [], 'prefixes' => []];

    /**
     * 规范化管理员填写的名单：单个地址或网段，IPv4 映射地址转为 IPv4，网段主机位清零，去重。
     *
     * @return array{0: list<string>, 1: list<string>} 规范化结果和错误信息
     */
    public static function parse(array $entries): array
    {
        $result = [];
        $errors = [];
        foreach (array_values($entries) as $index => $raw) {
            $entry = is_scalar($raw) ? trim((string) $raw) : '';
            if ($entry === '') {
                continue;
            }
            $normalized = self::normalizeEntry($entry, $error);
            if ($normalized === null) {
                $errors[] = sprintf('第 %d 项“%s”%s', $index + 1, $entry, $error);
                continue;
            }
            $result[$normalized] = true;
        }
        $result = array_map('strval', array_keys($result));
        if (count($result) > self::MAX_ENTRIES) {
            $errors[] = sprintf('最多填写 %d 项', self::MAX_ENTRIES);
        }

        return [$result, $errors];
    }

    /** 当前生效的规范化名单。 */
    public static function entries(): array
    {
        return self::parse(self::rawSetting())[0];
    }

    /** 先按原始地址过滤名单，再将公网 IPv6 合并到 /64；IPv4 仍按单个地址计数。 */
    public static function countKey(string $raw): ?string
    {
        $public = PublicDeviceIp::normalize($raw);
        if ($public === null) {
            return null;
        }
        $rules = self::rules();
        if ($rules['exact'] === [] && $rules['prefixes'] === []) {
            return self::deviceKey($public);
        }
        $binary = inet_pton($public);
        if (isset($rules['exact'][$binary])) {
            return null;
        }
        foreach ($rules['prefixes'] as [$network, $bits]) {
            if (strlen($network) === strlen($binary) && self::mask($binary, $bits) === $network) {
                return null;
            }
        }

        return self::deviceKey($public);
    }

    private static function deviceKey(string $public): string
    {
        $binary = inet_pton($public);
        return strlen($binary) === 16
            ? inet_ntop(substr($binary, 0, 8) . str_repeat("\0", 8))
            : $public;
    }

    private static function rawSetting(): array
    {
        $raw = admin_setting(self::SETTING, []);
        return is_array($raw) ? $raw : [];
    }

    /** 按设置原值缓存解析结果，设置变化后自动重建。 */
    private static function rules(): array
    {
        $raw = self::rawSetting();
        if (self::$memoRaw !== $raw) {
            $exact = [];
            $prefixes = [];
            foreach (self::parse($raw)[0] as $entry) {
                if (str_contains($entry, '/')) {
                    [$address, $bits] = explode('/', $entry, 2);
                    $prefixes[] = [inet_pton($address), (int) $bits];
                } else {
                    $exact[inet_pton($entry)] = true;
                }
            }
            self::$memoRaw = $raw;
            self::$memoRules = ['exact' => $exact, 'prefixes' => $prefixes];
        }

        return self::$memoRules;
    }

    private static function normalizeEntry(string $entry, ?string &$error): ?string
    {
        $error = '不是有效的 IP 地址或网段';
        $bits = null;
        $address = $entry;
        if (str_contains($entry, '/')) {
            [$address, $suffix] = explode('/', $entry, 2);
            if ($suffix === '' || !ctype_digit($suffix) || strlen($suffix) > 3) {
                return null;
            }
            $bits = (int) $suffix;
        }
        if ($address === '' || str_contains($address, '%')) {
            return null;
        }
        $binary = @inet_pton($address);
        if ($binary === false) {
            return null;
        }
        $max = strlen($binary) * 8;
        if ($bits !== null && $bits > $max) {
            return null;
        }
        if ($max === 128 && str_starts_with($binary, str_repeat("\0", 10) . "\xff\xff") && ($bits === null || $bits >= 96)) {
            $binary = substr($binary, 12);
            $bits = $bits === null ? null : $bits - 96;
            $max = 32;
        }
        $bits ??= $max;
        if ($bits < ($max === 32 ? self::MIN_V4_BITS : self::MIN_V6_BITS)) {
            $error = sprintf('网段过宽，IPv4 至少 /%d，IPv6 至少 /%d', self::MIN_V4_BITS, self::MIN_V6_BITS);
            return null;
        }
        $text = inet_ntop(self::mask($binary, $bits));

        return $bits === $max ? $text : "{$text}/{$bits}";
    }

    private static function mask(string $binary, int $bits): string
    {
        $masked = '';
        for ($i = 0, $length = strlen($binary); $i < $length; $i++) {
            $keep = max(0, min(8, $bits - $i * 8));
            $masked .= chr(ord($binary[$i]) & ((0xFF << (8 - $keep)) & 0xFF));
        }

        return $masked;
    }
}
