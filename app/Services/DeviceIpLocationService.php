<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class DeviceIpLocationService
{
    public function lookup(string $ip): array
    {
        return $this->lookupMany([$ip])[$ip];
    }

    /** 同一用户的公网 IP 并发查询，避免逐个等待外部接口超时。 */
    public function lookupMany(array $ips): array
    {
        $unknown = ['asn' => null, 'as_name' => null];
        $result = [];
        $pending = [];
        foreach (array_unique($ips) as $ip) {
            $result[$ip] = $this->localLocation($ip) + $unknown + ['location_source' => 'local'];
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                continue;
            }
            $key = 'device_location:v3:' . hash('sha256', $ip);
            try {
                $cached = Cache::get($key);
                if (is_array($cached)) {
                    $result[$ip] = array_replace($result[$ip], $cached);
                } else {
                    $pending[$ip] = $key;
                }
            } catch (\Throwable) {
                // 缓存故障不影响本地 IP 信息。
            }
        }
        if ($pending === []) return $result;

        try {
            $date = CarbonImmutable::now('UTC');
            $allowed = Cache::lock('device_asn:quota_lock', 5)->block(1, function () use ($date, $pending): array {
                if (Cache::get('device_asn:backoff')) return [];
                $quotaKey = 'device_asn:quota:' . $date->toDateString();
                $used = (int) Cache::get($quotaKey, 0);
                $ips = array_slice(array_keys($pending), 0, max(0, 1000 - $used));
                if ($ips === [] || !Cache::put($quotaKey, $used + count($ips), $date->addDay()->startOfDay())) {
                    return [];
                }
                return $ips;
            });
            if ($allowed === []) return $result;

            $responses = Http::pool(function (Pool $pool) use ($allowed): void {
                foreach ($allowed as $ip) {
                    $pool->as($ip)->acceptJson()->connectTimeout(1)->timeout(2)
                        ->withOptions(['allow_redirects' => false])
                        ->get('https://api.ip2location.io/', ['ip' => $ip, 'format' => 'json']);
                }
            }, 8);
            foreach ($allowed as $ip) {
                $result[$ip] = array_replace($result[$ip], $this->parseLocation($ip, $responses[$ip] ?? null, $pending[$ip]));
            }
        } catch (\Throwable) {
            try { Cache::put('device_asn:backoff', true, 300); } catch (\Throwable) {}
        }
        return $result;
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

    private function parseLocation(string $ip, mixed $response, string $key): array
    {
        $unknown = ['asn' => null, 'as_name' => null];
        try {
            if (!$response instanceof Response) {
                Cache::put('device_asn:backoff', true, 300);
                Cache::put($key, $unknown, 300);
                return $unknown;
            }
            if ($response->status() === 429) {
                Cache::put('device_asn:backoff', true, CarbonImmutable::tomorrow('UTC'));
                Cache::put($key, $unknown, 300);
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
            foreach (['country_code', 'country_name', 'region_name'] as $field) {
                $value = $body[$field] ?? null;
                $result[$field] = is_string($value) && trim($value) !== '-' ? mb_substr(trim($value), 0, 200) : '';
            }
            $places = [];
            foreach (['country_name', 'region_name', 'city_name'] as $field) {
                $place = $body[$field] ?? null;
                if (is_string($place) && trim($place) !== '' && trim($place) !== '-') {
                    $places[] = mb_substr(trim($place), 0, 200);
                }
            }
            if ($places !== []) {
                $result['region'] = implode(' ', array_unique($places));
                $result['location_source'] = 'ip2location';
            }
            $result['expires_at'] = now()->timestamp + ($places !== [] ? 30 * 86400 : 300);
            Cache::put($key, $result, $places !== [] ? 30 * 86400 : 300);
            return $result;
        } catch (\Throwable) {
            try { Cache::put('device_asn:backoff', true, 300); } catch (\Throwable) {}
            return $unknown;
        }
    }
}
