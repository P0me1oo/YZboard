<?php

namespace App\Services;

use App\Support\SubscriptionProtectionIp;
use Illuminate\Http\Request;

class SubscriptionStatisticsCapture
{
    /** 只保存已知软件名称和数字版本，不保留任意原始请求头。 */
    public function client(string $agent): array
    {
        $agent = substr($agent, 0, 1024);
        foreach (['ClashVerge', 'ClashMeta', 'FlClash', 'Mihomo', 'Clash', 'sing-box', 'singbox',
            'Shadowrocket', 'QuantumultX', 'Quantumult', 'Surfboard', 'Surge', 'Stash', 'Loon',
            'Hiddify', 'v2rayN', 'v2rayNG', 'NekoBox', 'NekoRay', 'SagerNet', 'Karing', 'Potatso',
            'tidalab', 'tunnelab', 'subconverter', 'curl', 'Wget', 'Edg', 'Chrome', 'Firefox', 'Safari', 'Mozilla'] as $name) {
            if (preg_match('~(?<![a-z0-9])' . preg_quote($name, '~') . '(?![a-z0-9])(?:[/\s]+v?(\d{1,5}(?:\.\d{1,5}){0,3})(?![\d.]))?~i', $agent, $match)) {
                return ['client' => $name === 'singbox' ? 'sing-box' : $name, 'client_version' => $match[1] ?? ''];
            }
        }
        return ['client' => 'unknown', 'client_version' => ''];
    }

    public function record(Request $request, int $status): void
    {
        $userId = $request->attributes->get('subscription_statistics_user_id');
        if (!$userId || $request->attributes->get('subscription_statistics_recorded')) return;
        $request->attributes->set('subscription_statistics_recorded', true);
        try {
            $ip = SubscriptionProtectionIp::normalize($request->ip() ?? '') ?? 'unknown';
            $event = ['user_id' => (int) $userId, 'ip' => $ip] + $this->client($request->userAgent() ?? '') + [
                'format' => $request->attributes->get('subscription_statistics_format', 'unknown'),
                'status' => $status, 'at' => $request->attributes->get('subscription_statistics_at', now()->timestamp),
            ];
            app(SubscriptionStatisticsBuffer::class)->push($event);
        } catch (\Throwable) {
            app(SubscriptionStatisticsBuffer::class)->failure('capture');
        }
    }
}
