<?php

namespace App\Services;

class RealityTargetLocation
{
    /** 只读取随依赖交付的 ip2region 库，不调用任何外部查询服务。 */
    public function enrich(array $results): array
    {
        $locations = [];
        $reader = null;
        $unavailable = false;
        foreach ($results as &$result) {
            $ip = $result['ip'] ?? '';
            if (!isset($locations[$ip])) {
                $location = ['region' => null, 'isp' => null, 'source' => 'ip2region'];
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
                    && !$unavailable) {
                    try {
                        $reader ??= new \Ip2Region();
                        $location = self::parse($reader->memorySearch($ip)['region'] ?? '');
                    } catch (\Throwable) {
                        // 库缺失或损坏不影响其他检测结果，也不重复尝试打开。
                        $unavailable = true;
                    }
                }
                $locations[$ip] = $location;
            }
            $result['location'] = $locations[$ip];
        }
        unset($result);
        return $results;
    }

    public static function parse(string $value): array
    {
        $parts = explode('|', $value);
        $places = array_values(array_unique(array_filter(array_slice($parts, 0, 4),
            static fn (string $part) => trim($part) !== '' && $part !== '0')));
        return [
            'region' => $places ? mb_substr(implode(' ', $places), 0, 512) : null,
            'isp' => isset($parts[4]) && trim($parts[4]) !== '' && $parts[4] !== '0' ? mb_substr($parts[4], 0, 200) : null,
            'source' => 'ip2region',
        ];
    }
}
