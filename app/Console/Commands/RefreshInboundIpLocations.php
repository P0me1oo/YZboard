<?php

namespace App\Console\Commands;

use App\Services\InboundIpLocation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RefreshInboundIpLocations extends Command
{
    protected $signature = 'inbound-ip:refresh';
    protected $description = '更新入站 IP 的地区和 ASN 缓存';

    public function handle(InboundIpLocation $locations): int
    {
        $query = DB::table('v2_inbound_ip')->where(fn ($query) => $query->where('lookup_after', '<=', now()->timestamp)
            ->orWhere(fn ($part) => $part->where('ip_version', 4)->where('local_expires_at', '<=', now()->timestamp)))
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('v2_stat_user_inbound_ip')
                ->whereColumn('v2_stat_user_inbound_ip.ip', 'v2_inbound_ip.ip')
                ->where('record_at', '>=', now()->startOfDay()->subDays(29)->timestamp))
            ->orderBy('lookup_after')->orderBy('ip')->limit(32);
        \App\Services\DeviceIpExclusion::constrainQuery($query, 'v2_inbound_ip');
        $ips = $query->pluck('ip')->all();
        $locations->refresh($ips);
        return self::SUCCESS;
    }
}
