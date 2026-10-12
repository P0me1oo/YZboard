<?php

namespace App\Services\TelegramBot;

use App\Services\DeviceIpLocationService;
use Carbon\CarbonImmutable;

class LimitReminderPresenter
{
    public function render(string $kind, array $details, bool $forAdmin = false): string
    {
        $events = $details['events'] ?? [];
        $addresses = [];
        foreach ($events as $event) {
            $snapshot = $event['snapshot'] ?? null;
            foreach ($snapshot ? array_merge([$snapshot['source_ip']], $snapshot['active_ips']) : ($event['ips'] ?? []) as $ip) {
                $addresses[] = explode('/', $ip, 2)[0];
            }
        }
        try {
            $locations = app(DeviceIpLocationService::class)->lookupMany(array_values(array_unique($addresses)));
        } catch (\Throwable) {
            $locations = [];
        }
        $lines = [$kind === 'device'
            ? ($forAdmin ? '用户设备数限制告警' : '设备数超限提醒')
            : ($forAdmin ? '用户连接数限制告警' : '连接数超限提醒')];
        foreach ($events as $type => $event) {
            $snapshot = $event['snapshot'] ?? null;
            $limit = $snapshot['limit'] ?? $event['limit'];
            $label = match ($type) {
                'device' => '设备数上限', 'conn' => '同时连接数上限', 'rate' => '每秒新建连接数上限',
            };
            $lines[] = '';
            $at = $snapshot['occurred_at'] ?? $details['event_at'];
            $lines[] = ($snapshot ? '发生时间：' : '上报时间：')
                . CarbonImmutable::createFromTimestamp($at, config('app.timezone'))->format('Y-m-d H:i:s');
            $lines[] = "{$label}：{$limit}";
            if ($snapshot && $type !== 'rate') {
                $lines[] = ($type === 'device' ? '已占用设备名额：' : '已占用连接数：') . $snapshot['observed'];
            }
            $lines[] = '';
            $lines[] = '被拒绝的 IP';
            $this->addresses($lines, $snapshot ? [$snapshot['source_ip']] : ($event['ips'] ?? []), $locations, '未提供');
            $lines[] = '';
            $lines[] = $type === 'rate' ? '已连接的 IP' : '占用名额的 IP';
            if (!$snapshot || !$snapshot['active_known']) {
                $lines[] = '未提供';
            } else {
                $this->addresses($lines, $snapshot['active_ips'], $locations, '无已记录的 IP');
                $remaining = $snapshot['active_total'] - count($snapshot['active_ips']);
                if ($remaining > 0) $lines[] = "另有 {$remaining} 个 IP 未展开";
            }
        }
        $lines[] = '';
        if ($forAdmin) {
            $lines[] = '节点已触发限制，请检查该账号的使用情况。';
        } else {
            $lines[] = $kind === 'device'
                ? '请减少同时使用的设备或网络后重试。'
                : '请减少同时连接数或降低新建连接频率后重试。';
            $lines[] = '如有误报，请向管理员反馈。';
            $lines[] = '如发现陌生 IP，可能是订阅泄露，请在 Bot → 订阅信息 → 重置订阅中重置。';
        }
        if (collect($locations)->contains(fn ($location) => ($location['location_source'] ?? '') === 'ip2location')) {
            $lines[] = 'IP 位置来源：https://www.ip2location.io/';
        }
        return implode("\n", $lines);
    }

    private function addresses(array &$lines, array $ips, array $locations, string $empty): void
    {
        if ($ips === []) { $lines[] = $empty; return; }
        foreach ($ips as $index => $ip) {
            if ($index > 0) $lines[] = '';
            $location = $locations[explode('/', $ip, 2)[0]] ?? [];
            $region = $this->text($location['region'] ?? '未知', 48);
            if (($location['location_source'] ?? '') === 'local') $region .= '（本地）';
            $network = $location['isp'] ?? '未知';
            if ($network === '未知') $network = $location['as_name'] ?? '未知';
            $asn = $this->text($location['asn'] ?? 'ASN 未知', 20);
            $lines[] = $ip;
            $lines[] = $region;
            $lines[] = $this->text($network, 48) . ' · ' . $asn;
        }
    }

    private function text(mixed $value, int $max): string
    {
        if (!is_string($value) || trim($value) === '') return '未知';
        return mb_substr(trim(preg_replace('/[\p{C}\s]+/u', ' ', $value) ?? ''), 0, $max);
    }
}
