<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class InboundIpLocation
{
    public function __construct(private readonly QqwryLocation $local, private readonly DeviceIpLocationService $external) {}

    public function refreshLocal(array $ips): void
    {
        $now = now()->timestamp;
        $rows = DB::table('v2_inbound_ip')->whereIn('ip', $ips)->where('ip_version', 4)
            ->where('local_expires_at', '<=', $now)->get();
        foreach ($rows as $row) {
            $local = $this->local->lookup($row->ip);
            DB::table('v2_inbound_ip')->where('ip', $row->ip)->update($local + [
                'local_expires_at' => $now + ($local['region'] === '未知' ? 300 : 30 * 86400),
            ]);
        }
    }

    /** 仅处理到期地址。外部调用使用原有并发、每日限额和退避策略。 */
    public function refresh(array $ips): void
    {
        $now = now()->timestamp;
        $this->refreshLocal($ips);
        $rows = DB::table('v2_inbound_ip')->whereIn('ip', array_unique($ips))->get();
        $pending = $rows->filter(fn ($row) => $row->external_expires_at <= $now && $row->lookup_after <= $now)->pluck('ip')->all();
        $locations = $pending === [] ? [] : $this->external->lookupMany($pending);
        foreach ($rows as $row) {
            $update = [];
            if (isset($locations[$row->ip])) {
                $location = $locations[$row->ip];
                $expires = (int) ($location['expires_at'] ?? $now + 300);
                $update += ['asn' => $location['asn'], 'as_name' => $location['as_name'],
                    'external_expires_at' => $expires, 'lookup_after' => $expires];
                if ((int) $row->ip_version === 6) {
                    $update += ['region' => $location['location_source'] === 'ip2location' ? mb_substr($location['region'], 0, 512) : '未知',
                        'province' => mb_substr(IpProvince::fromExternal($location), 0, 128)];
                }
            }
            if ($update !== []) DB::table('v2_inbound_ip')->where('ip', $row->ip)->update($update);
        }
    }
}
