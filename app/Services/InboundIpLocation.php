<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class InboundIpLocation
{
    public function __construct(private readonly IpLocation $local, private readonly DeviceIpLocationService $external) {}

    public function refreshLocal(array $ips): void
    {
        $now = now()->timestamp;
        $rows = DB::table('v2_inbound_ip')->whereIn('ip', $ips)
            ->where('local_expires_at', '<=', $now)->get();
        foreach ($rows as $row) {
            $local = $this->local->lookup($row->ip);
            DB::table('v2_inbound_ip')->where('ip', $row->ip)->update([
                'region' => $local['region'], 'province' => $local['province'],
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
        $pending = $rows->filter(fn ($row) => ($row->external_expires_at <= $now || $row->ip_properties === null)
            && $row->lookup_after <= $now)->pluck('ip')->all();
        $locations = $pending === [] ? [] : $this->external->lookupMany($pending);
        foreach ($rows as $row) {
            $update = [];
            if (isset($locations[$row->ip])) {
                $location = $locations[$row->ip];
                $expires = (int) ($location['expires_at'] ?? $now + 300);
                $properties = $location['ip_properties'] ?? IpProperties::fromResponse();
                // 优先使用接口提供的运营商，缺失时补本地运营商，不用 ASN 编号猜测。
                $properties['isp'] ??= IpProperties::fromResponse(['isp' => $location['isp'] ?? null])['isp'];
                $update += ['asn' => $location['asn'], 'as_name' => $location['as_name'],
                    'ip_properties' => json_encode($properties, JSON_THROW_ON_ERROR),
                    'external_expires_at' => $expires, 'lookup_after' => $expires];
            }
            if ($update !== []) DB::table('v2_inbound_ip')->where('ip', $row->ip)->update($update);
        }
    }

    /** 网页补查只在定时任务执行，不写入在线设备共用的接口缓存。 */
    public function refreshWeb(array $ips): void
    {
        $now = now()->timestamp;
        $pending = DB::table('v2_inbound_ip')->whereIn('ip', array_unique($ips))
            ->where('web_expires_at', '<=', $now)->where('web_lookup_after', '<=', $now)->pluck('ip')->all();
        if ($pending === []) return;
        foreach (app(InboundIpWebService::class)->lookupMany($pending) as $ip => $result) {
            $update = ['web_lookup_after' => $result['retry_at']];
            if (is_array($result['data'])) {
                $update += ['web_data' => json_encode($result['data'], JSON_THROW_ON_ERROR), 'web_expires_at' => $result['expires_at']];
            }
            DB::table('v2_inbound_ip')->where('ip', $ip)->where('web_expires_at', '<=', $now)
                ->where('web_lookup_after', '<=', $now)->update($update);
        }
    }
}
