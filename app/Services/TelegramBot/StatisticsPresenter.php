<?php

namespace App\Services\TelegramBot;

use App\Services\InboundIpStatistics;
use App\Services\TrafficStatisticsService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class StatisticsPresenter
{
    private const PAGE_SIZE = 5;
    private const PERIODS = ['today' => '今天', '7d' => '近 7 天', '30d' => '近 30 天'];

    public function __construct(
        private readonly InboundIpStatistics $inbound,
        private readonly TrafficStatisticsService $traffic,
    ) {}

    /** 按钮只携带查询类型、日期范围和页码，用户始终取自当前绑定。 */
    public static function parseAction(string $action): ?array
    {
        if (!preg_match('/\A(inbound|traffic):(today|7d|30d):([1-9][0-9]{0,5})\z/', $action, $matches)) {
            return null;
        }
        return ['type' => $matches[1], 'period' => $matches[2], 'page' => (int) $matches[3]];
    }

    public function render(array $query, int $userId): array
    {
        $service = $query['type'] === 'inbound' ? $this->inbound : $this->traffic;
        $range = $service->range(new Request([
            'user_id' => $userId, 'period' => $query['period'], 'page' => $query['page'],
            'page_size' => self::PAGE_SIZE, 'unit' => 'day',
        ]));
        $data = $service->user($range);
        // 历史清理或筛选变化后，旧按钮仍能回到最后一个有效页面。
        if ($data['page'] > $data['last_page']) {
            $range['page'] = $data['last_page'];
            $data = $service->user($range);
        }
        $lines = [
            $query['type'] === 'inbound' ? '入站 IP' : '流量记录',
            '范围：' . $data['meta']['start_date'] . ' 至 ' . $data['meta']['end_date'],
            '时区：' . $data['meta']['timezone'],
        ];
        if ($query['type'] === 'inbound') {
            $lines[] = '共 ' . $data['ip_count'] . ' 个 IP';
        } else {
            $lines[] = '实际用量：' . $this->usage($data['actual_summary']);
            $lines[] = '扣费流量：' . $this->usage($data['billed_summary']);
            $lines[] = '共 ' . $data['total'] . ' 条明细';
        }
        $lines[] = '第 ' . $data['page'] . ' / ' . $data['last_page'] . ' 页';
        foreach ($data['list'] as $index => $row) {
            $number = ($data['page'] - 1) * self::PAGE_SIZE + $index + 1;
            $lines[] = '';
            if ($query['type'] === 'inbound') {
                $lines[] = $number . '. ' . $row['ip'];
                $lines[] = '归属地：' . $this->label($row['region'], 120);
                $lines[] = '网络：' . $this->label($row['as_name'], 80) . ' / ' . $this->label($row['asn'], 20);
                $lines[] = '首次出现：' . $this->date($row['first_seen_at']);
                $lines[] = '最近出现：' . $this->date($row['last_seen_at']);
            } else {
                $lines[] = $number . '. ' . $row['date'] . ' · ' . $this->label($row['server_name'], 100);
                $lines[] = ($row['kind'] === 'relay' ? '中转' : '直连') . ' · 倍率 ' . $row['rate'];
                $lines[] = '实际：' . $this->usage($row);
                $lines[] = '扣费：' . $this->usage($row, 'billed_');
            }
        }
        if ($data['list'] === []) {
            $lines[] = '';
            $lines[] = $query['type'] === 'inbound' ? '所选时段暂无入站 IP 记录。'
                : ($data['actual_summary']['total'] > 0 ? '所选时段有流量，暂无可展示的节点明细。' : '所选时段暂无流量记录。');
        } elseif ($query['type'] === 'inbound') {
            $lines[] = '';
            $lines[] = 'IP 归属地：纯真 / ip2region；ASN：https://www.ip2location.io';
        }
        return ['text' => implode("\n", $lines), 'keyboard' => $this->keyboard($query, $data)];
    }

    private function keyboard(array $query, array $data): array
    {
        $type = $query['type'];
        $period = $query['period'];
        $periods = [];
        foreach (self::PERIODS as $value => $label) {
            $periods[] = ['text' => $label, 'callback_data' => "$type:$value:1"];
        }
        $pages = [];
        if ($data['page'] > 1) {
            $pages[] = ['text' => '上一页', 'callback_data' => "$type:$period:" . ($data['page'] - 1)];
        }
        if ($data['page'] < $data['last_page']) {
            $pages[] = ['text' => '下一页', 'callback_data' => "$type:$period:" . ($data['page'] + 1)];
        }
        $keyboard = [$periods];
        if ($pages !== []) $keyboard[] = $pages;
        $keyboard[] = [
            ['text' => '刷新', 'callback_data' => "$type:$period:" . $data['page']],
            ['text' => '返回主菜单', 'callback_data' => 'menu'],
        ];
        return $keyboard;
    }

    private function usage(array $row, string $prefix = ''): string
    {
        return $this->bytes((int) $row[$prefix . 'total'])
            . '（上传 ' . $this->bytes((int) $row[$prefix . 'upload'])
            . ' / 下载 ' . $this->bytes((int) $row[$prefix . 'download']) . '）';
    }

    private function bytes(int $bytes): string
    {
        $value = max(0, $bytes);
        foreach (['B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB'] as $unit) {
            if ($value < 1024 || $unit === 'EB') {
                return number_format($value, $unit === 'B' ? 0 : 2, '.', '') . ' ' . $unit;
            }
            $value /= 1024;
        }
    }

    /** 保留完整 IP 和流量，只限制自由文本，确保五条明细不会超过 Telegram 消息上限。 */
    private function label(?string $value, int $limit): string
    {
        $value = trim(preg_replace('/[\p{C}\s]+/u', ' ', $value ?? ''));
        if ($value === '') return '未知';
        return mb_strlen($value) > $limit ? mb_substr($value, 0, $limit) . '…' : $value;
    }

    private function date(string $value): string
    {
        return CarbonImmutable::parse($value)->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
    }
}
