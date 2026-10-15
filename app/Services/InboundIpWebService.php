<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class InboundIpWebService
{
    private const MAX_BODY = 262144;

    public function __construct(private readonly Ip2LocationWebParser $parser) {}

    /** 仅由入站 IP 后台任务调用，缓存和额度与在线设备查询隔离。 */
    public function lookupMany(array $ips): array
    {
        $result = $pending = $locks = $errors = [];
        try {
            foreach (array_unique($ips) as $ip) {
                if (PublicDeviceIp::normalize($ip) !== $ip) continue;
                // 避免重新补查时复用旧摘要结果；数据库里的完整有效结果继续使用。
                $key = 'inbound_ip_web:v3:' . hash('sha256', $ip);
                try {
                    $cached = Cache::get($key);
                    if (is_array($cached)) { $result[$ip] = $cached; continue; }
                    if (count($pending) >= 32) break;
                    $lock = Cache::lock($key . ':lock', 90);
                    if (!$lock->get()) continue;
                    $locks[$ip] = $lock;
                    // 获取锁后再次检查，避免两个批次使用同一份缓存未命中的快照。
                    $cached = Cache::get($key);
                    if (is_array($cached)) $result[$ip] = $cached;
                    else $pending[$ip] = $key;
                } catch (\Throwable) { $errors['cache'] = ($errors['cache'] ?? 0) + 1; }
            }
            if ($pending === []) return $result;

            $date = CarbonImmutable::now('UTC');
            $reservation = Cache::lock('inbound_ip_web:quota_lock', 5)->block(1, function () use ($date, $pending): array {
                $retry = (int) Cache::get('inbound_ip_web:backoff', 0);
                if ($retry > $date->timestamp) return ['allowed' => [], 'retry_at' => $retry];
                $key = 'inbound_ip_web:quota:' . $date->toDateString();
                $used = (int) Cache::get($key, 0);
                $allowed = array_slice(array_keys($pending), 0, max(0, 1000 - $used));
                if ($allowed !== [] && !Cache::put($key, $used + count($allowed), $date->addDay()->startOfDay())) {
                    throw new \RuntimeException('Unable to reserve IP web lookup quota');
                }
                return ['allowed' => $allowed, 'retry_at' => $date->addDay()->startOfDay()->timestamp];
            });
            $allowed = $reservation['allowed'];
            foreach (array_diff(array_keys($pending), $allowed) as $ip) {
                $result[$ip] = $this->failure((int) $reservation['retry_at']);
            }
            if ($allowed === []) return $result;

            $responses = Http::pool(function (Pool $pool) use ($allowed): void {
                foreach ($allowed as $ip) {
                    $pool->as($ip)->withHeaders(['Accept' => 'text/html', 'User-Agent' => 'YZboard-IP-Lookup/1.0'])
                        ->connectTimeout(2)->timeout(5)->withOptions([
                            'allow_redirects' => false,
                            'progress' => static function ($total, $downloaded): void {
                                if ($downloaded > self::MAX_BODY) throw new \RuntimeException('IP web lookup response too large');
                            },
                        // 官网 IPv6 路径保留冒号；前面已验证为规范化公网地址。
                        ])->get('https://www.ip2location.com/' . $ip);
                }
            }, 4);
            foreach ($allowed as $ip) {
                $response = $responses[$ip] ?? null;
                $retry = now()->timestamp + 300;
                $reason = null;
                $data = null;
                if (!$response instanceof Response) $reason = 'network';
                elseif ($response->status() === 429) {
                    $retry = $this->retryAfter($response);
                    Cache::put('inbound_ip_web:backoff', $retry, max(1, $retry - now()->timestamp));
                    $reason = 'http:429';
                } elseif (!$response->successful()) $reason = 'http:' . $response->status();
                elseif (strlen($response->body()) > self::MAX_BODY) $reason = 'oversized';
                else {
                    try { $data = $this->parser->parse($response->body(), $ip); }
                    catch (\Throwable) { $data = null; }
                    if ($data === null) $reason = 'invalid_page';
                }
                if ($reason !== null) $errors[$reason] = ($errors[$reason] ?? 0) + 1;
                $value = $data === null ? $this->failure($retry) : [
                    'data' => $data, 'expires_at' => now()->timestamp + 30 * 86400, 'retry_at' => now()->timestamp + 30 * 86400,
                ];
                $result[$ip] = $value;
                try { Cache::put($pending[$ip], $value, max(1, $value['retry_at'] - now()->timestamp)); }
                catch (\Throwable) { $errors['cache'] = ($errors['cache'] ?? 0) + 1; }
            }
        } catch (\Throwable) {
            $errors['batch'] = ($errors['batch'] ?? 0) + 1;
        } finally {
            foreach ($locks as $lock) {
                try { $lock->release(); } catch (\Throwable) {}
            }
            if ($errors !== []) Log::notice('入站 IP 网页补查未完成', ['reasons' => $errors]);
        }
        return $result;
    }

    private function failure(int $retry): array
    {
        return ['data' => null, 'expires_at' => 0, 'retry_at' => $retry];
    }

    private function retryAfter(Response $response): int
    {
        $now = now()->timestamp;
        $header = (string) $response->header('Retry-After');
        $seconds = ctype_digit($header) ? (int) $header : (strtotime($header) ?: 0) - $now;
        return $now + min(86400, max(300, $seconds));
    }
}
