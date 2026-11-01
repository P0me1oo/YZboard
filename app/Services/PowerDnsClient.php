<?php

namespace App\Services;

use App\Exceptions\DdnsSyncException;
use App\Models\BalancerDnsPreset;
use Illuminate\Support\Facades\Http;

class PowerDnsClient
{
    public function request(BalancerDnsPreset $preset, string $method, array $data = []): array
    {
        try {
            $url = rtrim($preset->api_url, '/') . '/api/v1/servers/' . rawurlencode($preset->server_id)
                . '/zones/' . rawurlencode(rtrim($preset->domain, '.') . '.');
            $response = Http::withHeaders(['X-API-Key' => $preset->api_token])->acceptJson()
                ->withOptions(['allow_redirects' => false])->connectTimeout(3)->timeout(10)->{$method}($url, $data);
            if (!$response->successful()) throw new \RuntimeException();
            if ($method === 'patch') return [];
            $zone = $response->json();
            if (!is_array($zone) || strtolower(rtrim($zone['name'] ?? '', '.')) !== $preset->domain
                || !is_array($zone['rrsets'] ?? null)) throw new \RuntimeException();
            return $zone;
        } catch (\Throwable) {
            throw new DdnsSyncException('PowerDNS API 请求失败，请检查地址、密钥、区域和网络');
        }
    }
}
