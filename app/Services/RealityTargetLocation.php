<?php

namespace App\Services;

class RealityTargetLocation
{
    private readonly IpLocation $local;

    public function __construct(?IpLocation $local = null)
    {
        $this->local = $local ?? new IpLocation();
    }

    /** 只读取随源码交付的 ip2region 库，不调用任何外部查询服务。 */
    public function enrich(array $results): array
    {
        $locations = [];
        foreach ($results as &$result) {
            $ip = $result['ip'] ?? '';
            if (!isset($locations[$ip])) {
                $local = $this->local->lookup($ip);
                $locations[$ip] = ['region' => $local['region'] === '未知' ? null : $local['region'],
                    'isp' => $local['isp'] === '未知' ? null : $local['isp'], 'source' => $local['source']];
            }
            $result['location'] = $locations[$ip];
        }
        unset($result);
        return $results;
    }

    public static function parse(string $value): array
    {
        $location = LocalIpLocation::parse($value);
        return ['region' => $location['region'], 'isp' => $location['isp'], 'source' => 'ip2region'];
    }
}
