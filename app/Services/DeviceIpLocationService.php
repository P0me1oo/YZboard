<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class DeviceIpLocationService
{
    public function lookup(string $ip): array
    {
        $location = $this->localLocation($ip);
        return $location + $this->lookupAsn($ip);
    }

    private function localLocation(string $ip): array
    {
        $unknown = ['region' => '未知', 'isp' => '未知'];
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return $unknown;
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

    private function lookupAsn(string $ip): array
    {
        $unknown = ['asn' => null, 'as_name' => null];
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return $unknown;
        }
        $key = 'device_asn:v1:' . hash('sha256', $ip);
        try {
            $cached = Cache::get($key);
            if (is_array($cached)) return $cached;

            $date = CarbonImmutable::now('UTC');
            $allowed = Cache::lock('device_asn:quota_lock', 5)->block(1, function () use ($date): bool {
                $quotaKey = 'device_asn:quota:' . $date->toDateString();
                $used = (int) Cache::get($quotaKey, 0);
                if ($used >= 1000 || Cache::get('device_asn:backoff')) return false;
                return Cache::put($quotaKey, $used + 1, $date->addDay()->startOfDay());
            });
            if (!$allowed) return $unknown;

            $response = Http::acceptJson()->connectTimeout(2)->timeout(4)
                ->withOptions(['allow_redirects' => false])
                ->get('https://api.ip2location.io/', ['ip' => $ip, 'format' => 'json']);
            if ($response->status() === 429) {
                Cache::put('device_asn:backoff', true, CarbonImmutable::tomorrow('UTC'));
                return $unknown;
            }
            if (!$response->successful() || strlen($response->body()) > 65536) {
                Cache::put('device_asn:backoff', true, 300);
                Cache::put($key, $unknown, 300);
                return $unknown;
            }
            $body = $response->json();
            if (!is_array($body) || ($body['ip'] ?? null) !== $ip || isset($body['error'])) {
                Cache::put($key, $unknown, 300);
                return $unknown;
            }
            $asn = $body['asn'] ?? null;
            $number = is_scalar($asn) && preg_match('/^(?:AS)?([0-9]{1,10})$/iD', (string) $asn, $match)
                ? filter_var($match[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4294967295]])
                : false;
            $name = $body['as'] ?? null;
            $result = [
                'asn' => $number !== false ? 'AS' . $number : null,
                'as_name' => is_string($name) ? mb_substr(trim($name), 0, 200) : null,
            ];
            Cache::put($key, $result, 7 * 86400);
            return $result;
        } catch (\Throwable) {
            try { Cache::put('device_asn:backoff', true, 300); } catch (\Throwable) {}
            return $unknown;
        }
    }
}
