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
        $success = true;
        foreach (\App\Models\LandingPool::with('node')->get() as $landing) {
            try { app(\App\Services\LandingPoolService::class)->sync($landing); }
            catch (\Throwable $error) { report($error); $success = false; }
        }
        \App\Models\SchedulingEvent::where('created_at', '<', now()->subDays(30))->delete();
        foreach (BalancerPool::with('gateway')->get() as $pool) {
            if ($pool->gateway && $pool->enabled) NodeSyncService::notifyConfigUpdated($pool->gateway->id);
            if ($pool->mode === 'dns' || !$pool->dns_synced_at || $pool->dns_error || $pool->updated_at->gt($pool->dns_synced_at)
                || $pool->dns_synced_at->lt(now()->subMinutes(10))) {
                try { if (!$dns->sync($pool)) $success = false; }
                catch (\Throwable) { $success = false; }
            }
        }
        return $success ? self::SUCCESS : self::FAILURE;
    }
}
