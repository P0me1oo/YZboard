<?php

namespace App\Services;

use App\Models\BalancerPool;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class BalancerDnsService
{
    public function sync(BalancerPool $pool): bool
    {
        return Cache::lock('balancer:dns:' . $pool->id, 120)->block(2, function () use ($pool) {
            try {
                $current = $pool->fresh();
                app(DnsPoolService::class)->sync($current);
                if ($pool->dns_error) SchedulingEvents::record('front', $pool->id, $pool->name, 'dns_recovered', ['message' => $pool->dns_error], ['status' => 'ready']);
                $pool->forceFill(['dns_error' => null, 'dns_synced_at' => now()])->saveQuietly();
                return true;
            } catch (\Throwable $error) {
                FailureLog::record('前置池 DNS 同步失败', $error,
                    ['pool_id' => (int) $pool->id, 'event' => 'dns.sync'], 'warning', includeExpected: true);
                // 诊断经过统一脱敏；公开错误仍使用固定提示或已脱敏的 DNS 异常。
                $message = $error instanceof \App\Exceptions\DdnsSyncException ? $error->getMessage()
                    : ($error instanceof \Illuminate\Contracts\Cache\LockTimeoutException ? 'DNS 同步锁繁忙，稍后自动重试'
                        : ($error instanceof ValidationException ? '域名存在非本功能管理的记录，请检查后重试' : 'DNS 同步失败，请检查权限、网络和域名配置'));
                if ($pool->dns_error !== $message) SchedulingEvents::record('front', $pool->id, $pool->name, 'dns_failed', ['message' => $pool->dns_error], ['message' => $message]);
                $pool->forceFill(['dns_error' => $message])->saveQuietly();
                return false;
            }
        });
    }

}
