<?php

namespace App\Services;

use App\Models\BalancerDnsPreset;
use App\Models\BalancerDnsRecord;
use App\Models\BalancerPool;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class BalancerDnsService
{
    public function sync(BalancerPool $pool): bool
    {
        return Cache::lock('balancer:dns:' . $pool->id, 120)->block(2, function () use ($pool) {
            try {
                $this->reconcile($pool->fresh());
                $pool->forceFill(['dns_error' => null, 'dns_synced_at' => now()])->saveQuietly();
                return true;
            } catch (\Throwable $error) {
                // 外部响应和请求头可能包含凭据，不将其写入日志或接口返回。
                $message = $error instanceof ValidationException ? '域名存在非本功能管理的记录，请检查后重试' : 'Cloudflare DNS 同步失败，请检查权限、网络和域名配置';
                $pool->forceFill(['dns_error' => $message])->saveQuietly();
                return false;
            }
        });
    }

    private function reconcile(BalancerPool $pool): void
    {
        $records = BalancerDnsRecord::where('pool_id', $pool->id)->get();
        $preset = $pool->dnsPreset;
        if (!$preset) {
            if ($records->isNotEmpty()) throw new RuntimeException('托管记录仍在使用配置');
            return;
        }
        if ($pool->domain === $preset->domain || !str_ends_with($pool->domain, '.' . $preset->domain)) {
            throw new RuntimeException('入口必须使用配置域名下的独立子域名');
        }
        $remote = $this->request($preset, 'get', '/dns_records', ['name' => $pool->domain, 'per_page' => 100]);
        if (count($remote) >= 100) throw new RuntimeException('域名记录过多');
        $owns = static fn (BalancerDnsRecord $local, array $remoteRecord): bool => $local->marker
            ? ($remoteRecord['comment'] ?? '') === $local->marker
            : ($local->record_id && $remoteRecord['id'] === $local->record_id);
        foreach ($remote as $record) {
            if (strtolower(rtrim($record['name'] ?? '', '.')) !== $pool->domain) throw new RuntimeException('DNS 返回的域名不匹配');
            if (in_array($record['type'], ['A', 'AAAA', 'CNAME'], true)
                && !$records->contains(fn ($local) => $owns($local, $record))) {
                throw ValidationException::withMessages(['domain' => '入口域名已有其他用途的解析记录']);
            }
        }
        // 域名指向统一调度入口；成员心跳影响入口内的分配，不再把成员 IP 轮流写入 DNS。
        $type = str_contains($pool->gateway_address, ':') ? 'AAAA' : 'A';
        $kept = null;
        foreach ($records as $record) {
            if ($record->zone_id !== $preset->zone_id || $record->name !== $pool->domain) {
                throw new RuntimeException('请先清理旧域名的托管记录');
            }
            $matches = collect($remote)->filter(fn ($item) => $owns($record, $item));
            if ($matches->count() > 1) throw new RuntimeException('托管域名存在重复记录');
            $exists = $matches->first();
            if ($pool->enabled && $kept === null) {
                if ($exists) {
                    $record->update(['record_id' => $exists['id']]);
                    if ($exists['type'] !== $type || @inet_pton($exists['content']) !== @inet_pton($pool->gateway_address)
                        || (bool) ($exists['proxied'] ?? false) || (int) ($exists['ttl'] ?? 0) !== 60) {
                        $this->request($preset, 'put', '/dns_records/' . $record->record_id, [
                            'type' => $type, 'name' => $pool->domain, 'content' => $pool->gateway_address, 'ttl' => 60, 'proxied' => false,
                            'comment' => $record->marker ?? ($exists['comment'] ?? 'YZboard 前置池 ' . $pool->id),
                        ]);
                    }
                    $record->update(['type' => $type, 'content' => $pool->gateway_address]);
                } else {
                    $record->update(['record_id' => null, 'marker' => $record->marker ?? 'YZPOOL:' . bin2hex(random_bytes(16))]);
                    $this->createRecord($preset, $pool, $record, $type);
                }
                $kept = $record;
            } else {
                if ($exists) $this->request($preset, 'delete', '/dns_records/' . $exists['id']);
                $record->delete();
            }
        }
        if ($pool->enabled && !$kept) {
            $record = BalancerDnsRecord::create(['pool_id' => $pool->id, 'record_id' => null, 'zone_id' => $preset->zone_id,
                'name' => $pool->domain, 'type' => $type, 'content' => $pool->gateway_address,
                'marker' => 'YZPOOL:' . bin2hex(random_bytes(16))]);
            $this->createRecord($preset, $pool, $record, $type);
        }
    }

    private function createRecord(BalancerDnsPreset $preset, BalancerPool $pool, BalancerDnsRecord $record, string $type): void
    {
        // 意图和随机归属已经持久化；响应丢失时，下次只认领带同一标记的记录。
        $response = $this->request($preset, 'post', '/dns_records', [
            'type' => $type, 'name' => $pool->domain, 'content' => $pool->gateway_address, 'ttl' => 60, 'proxied' => false,
            'comment' => $record->marker,
        ]);
        if (empty($response['id'])) throw new RuntimeException('DNS 接口没有返回记录编号');
        $record->update(['record_id' => $response['id'], 'type' => $type, 'content' => $pool->gateway_address]);
    }

    private function request(BalancerDnsPreset $preset, string $method, string $path, array $data = []): array
    {
        $client = Http::withToken($preset->api_token)->acceptJson()->connectTimeout(5)->timeout(15);
        $response = $client->{$method}('https://api.cloudflare.com/client/v4/zones/' . $preset->zone_id . $path, $data);
        if (!$response->successful() || $response->json('success') !== true || !is_array($response->json('result'))) {
            throw new RuntimeException('DNS 接口请求失败');
        }
        return $response->json('result');
    }
}
