<?php

namespace App\Services;

class DeviceIpLocationService
{
    public function lookup(string $ip): array
    {
        $unknown = ['region' => '未知', 'isp' => '未知'];
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $unknown;
        }
        try {
            $parts = explode('|', (new \Ip2Region())->memorySearch($ip)['region'] ?? '');
            $places = array_values(array_unique(array_filter(
                array_slice($parts, 0, 4),
                static fn (string $part) => $part !== '' && $part !== '0'
            )));
            return [
                'region' => $places ? implode(' ', $places) : '未知',
                'isp' => isset($parts[4]) && $parts[4] !== '' && $parts[4] !== '0' ? $parts[4] : '未知',
            ];
        } catch (\Throwable) {
            return $unknown;
        }
    }
}
