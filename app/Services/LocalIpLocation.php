<?php

namespace App\Services;

/** 使用随源码交付的官方 IPv4、IPv6 数据库，不在运行时下载。 */
class LocalIpLocation
{
    private ?\Ip2Region $reader = null;

    public function __construct(
        private readonly ?string $ipv4Path = null,
        private readonly ?string $ipv6Path = null,
    ) {}

    public function lookup(string $ip): array
    {
        $unknown = ['region' => null, 'isp' => null, 'province' => '未知', 'country_code' => ''];
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return $unknown;
        }

        $ipv4Path = $this->ipv4Path ?? resource_path('ip/ip2region_v4.xdb');
        $ipv6Path = $this->ipv6Path ?? resource_path('ip/ip2region_v6.xdb');
        $path = str_contains($ip, ':') ? $ipv6Path : $ipv4Path;
        if (!is_file($path) || !is_readable($path)) return $unknown;

        try {
            $this->reader ??= new \Ip2Region('file', $ipv4Path, $ipv6Path);
            return self::parse($this->reader->search($ip));
        } catch (\Throwable) {
            return $unknown;
        }
    }

    /** XDB v3 的顺序为国家、省份、城市、运营商、国家代码。 */
    public static function parse(string $value): array
    {
        $parts = array_map('trim', explode('|', $value));
        if (count($parts) !== 5) return ['region' => null, 'isp' => null, 'province' => '未知', 'country_code' => ''];
        $valid = static fn (string $part) => !in_array($part, ['', '0', '-'], true);
        $places = array_values(array_unique(array_filter(array_slice($parts, 0, 3), $valid)));
        $code = preg_match('/^[A-Z]{2}$/D', $parts[4]) ? $parts[4] : '';
        return [
            'region' => $places ? mb_substr(implode(' ', $places), 0, 512) : null,
            'isp' => $valid($parts[3]) ? mb_substr($parts[3], 0, 200) : null,
            'province' => IpProvince::fromExternal(['country_code' => $code,
                'country_name' => $valid($parts[0]) ? $parts[0] : '', 'region_name' => $parts[1]]),
            'country_code' => $code,
        ];
    }
}
