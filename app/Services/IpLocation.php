<?php

namespace App\Services;

/** 中国大陆 IPv4 用纯真，其他 IPv4 和全部 IPv6 用 ip2region。 */
class IpLocation
{
    public function __construct(
        private readonly LocalIpLocation $ip2region = new LocalIpLocation(),
        private readonly QqwryLocation $qqwry = new QqwryLocation(),
    ) {}

    public function lookup(string $ip): array
    {
        $location = $this->ip2region->lookup($ip);
        $source = 'ip2region';
        if (!str_contains($ip, ':') && $location['country_code'] === 'CN') {
            $domestic = $this->qqwry->lookup($ip);
            if ($domestic['region'] !== '未知') {
                $location = array_replace($location, $domestic);
                $source = 'qqwry';
            }
        }
        return [
            'region' => $location['region'] ?? '未知',
            'isp' => $location['isp'] ?? '未知',
            'province' => $location['province'],
            'source' => $source,
            'location_source' => 'local',
        ];
    }
}
