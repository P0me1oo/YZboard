<?php

namespace App\Services;

use App\Exceptions\DdnsSyncException;
use App\Exceptions\DdnsSyncDeferred;
use App\Models\BalancerDnsPreset;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class CloudflareDnsClient
{
    private array $snapshots = [];
    private array $names = [];
    private array $failures = [];
    private ?float $deadline = null;

    public function reset(): void
    {
        $this->snapshots = [];
        $this->names = [];
        $this->failures = [];
        $this->deadline = null;
    }

    public function setDeadline(?float $deadline): void
    {
        $this->deadline = $deadline;
    }

    public function checkpoint(): void
    {
        if ($this->deadline !== null && microtime(true) >= $this->deadline - 0.1) {
            throw new DdnsSyncDeferred();
        }
    }

    public function request(BalancerDnsPreset $preset, string $method, string $path, array $data = []): array
    {
        $this->checkpoint();
        $failureKey = $preset->zone_id . ':' . hash('sha256', $preset->api_token);
        if (isset($this->failures[$failureKey])) throw new DdnsSyncException($this->failures[$failureKey]);
        if (Cache::has('ddns:cloudflare:backoff')) {
            throw new DdnsSyncException('Cloudflare 请求限流，稍后自动重试');
        }
        try {
            $timeout = $this->deadline === null ? 20 : min(20, max(0.1, $this->deadline - microtime(true)));
            $response = Http::withToken($preset->api_token)->acceptJson()->connectTimeout(min(5, $timeout))->timeout($timeout)
                ->{$method}('https://api.cloudflare.com/client/v4/zones/' . $preset->zone_id . $path, $data);
            if ($response->status() === 429) {
                Cache::put('ddns:cloudflare:backoff', true, max(30, min(600, (int) $response->header('Retry-After') ?: 300)));
                throw new DdnsSyncException('Cloudflare 请求限流，稍后自动重试');
            }
            if (!$response->successful() || $response->json('success') !== true || !is_array($response->json('result'))) {
                throw new DdnsSyncException('Cloudflare 同步失败，请检查凭据权限、域名和记录额度');
            }
            return $response->json();
        } catch (DdnsSyncException $error) {
            $this->failures[$failureKey] = $error->getMessage();
            throw $error;
        } catch (\Throwable) {
            $this->failures[$failureKey] = 'Cloudflare 连接失败，稍后自动重试';
            throw new DdnsSyncException($this->failures[$failureKey]);
        }
    }

    public function records(BalancerDnsPreset $preset): array
    {
        $key = $preset->zone_id;
        if (!isset($this->snapshots[$key])) {
            $records = [];
            $names = [];
            $page = 1;
            do {
                $this->checkpoint();
                $response = $this->request($preset, 'get', '/dns_records', ['per_page' => 1000, 'page' => $page]);
                foreach ($response['result'] as $record) {
                    $this->checkpoint();
                    $records[$record['id']] = $record;
                    $names[strtolower(rtrim($record['name'], '.'))][$record['id']] = true;
                }
                $pages = (int) ($response['result_info']['total_pages'] ?? 1);
                if ($pages > 10000) throw new DdnsSyncException('DNS 记录数量超出单次同步范围');
            } while ($page++ < $pages);
            $this->snapshots[$key] = $records;
            $this->names[$key] = $names;
        }
        return $this->snapshots[$key];
    }

    public function remember(BalancerDnsPreset $preset, array $record): void
    {
        $this->forget($preset, $record['id']);
        $this->snapshots[$preset->zone_id][$record['id']] = $record;
        $this->names[$preset->zone_id][strtolower(rtrim($record['name'], '.'))][$record['id']] = true;
    }

    public function forget(BalancerDnsPreset $preset, string $id): void
    {
        $record = $this->snapshots[$preset->zone_id][$id] ?? null;
        if ($record) unset($this->names[$preset->zone_id][strtolower(rtrim($record['name'], '.'))][$id]);
        unset($this->snapshots[$preset->zone_id][$id]);
    }

    public function recordsNamed(BalancerDnsPreset $preset, string $name): array
    {
        $this->records($preset);
        $matches = [];
        foreach ($this->names[$preset->zone_id][strtolower(rtrim($name, '.'))] ?? [] as $id => $_) {
            $matches[$id] = $this->snapshots[$preset->zone_id][$id];
        }
        return $matches;
    }

    public function usage(BalancerDnsPreset $preset): array
    {
        $usage = $this->request($preset, 'get', '/dns_records/usage')['result'];
        if (!array_key_exists('record_quota', $usage) || !isset($usage['record_usage'])) {
            throw new DdnsSyncException('Cloudflare 未返回有效的 DNS 记录额度');
        }
        return ['quota' => $usage['record_quota'], 'usage' => (int) $usage['record_usage']];
    }
}
