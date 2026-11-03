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
                // 外部响应和请求头可能包含凭据，不将其写入日志或接口返回。
                $message = $error instanceof \App\Exceptions\DdnsSyncException ? $error->getMessage()
                    : ($error instanceof ValidationException ? '域名存在非本功能管理的记录，请检查后重试' : 'DNS 同步失败，请检查权限、网络和域名配置');
                if ($pool->dns_error !== $message) SchedulingEvents::record('front', $pool->id, $pool->name, 'dns_failed', ['message' => $pool->dns_error], ['message' => $message]);
                $pool->forceFill(['dns_error' => $message])->saveQuietly();
                return false;
            }
        });
    }

}
