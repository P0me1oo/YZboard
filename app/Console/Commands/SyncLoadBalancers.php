<?php

namespace App\Console\Commands;

use App\Models\BalancerPool;
use App\Services\BalancerDnsService;
use App\Services\NodeSyncService;
use Illuminate\Console\Command;

class SyncLoadBalancers extends Command
{
    protected $signature = 'balancer:sync';
    protected $description = '更新前置池的成员状态和托管 DNS';

    public function handle(BalancerDnsService $dns): int
    {
        foreach (BalancerPool::with('gateway')->get() as $pool) {
            if ($pool->gateway && $pool->enabled) NodeSyncService::notifyConfigUpdated($pool->gateway->id);
            if (!$pool->dns_synced_at || $pool->dns_error || $pool->updated_at->gt($pool->dns_synced_at)
                || $pool->dns_synced_at->lt(now()->subMinutes(10))) $dns->sync($pool);
        }
        return self::SUCCESS;
    }
}
