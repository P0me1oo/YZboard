<?php

namespace Tests\Unit\Services;

use App\Services\LimitEventSnapshot;
use App\Services\DeviceIpLocationService;
use App\Services\TelegramBot\LimitReminderPresenter;
use Tests\TestCase;

class LimitEventSnapshotTest extends TestCase
{
    private function snapshot(array $changes = []): array
    {
        return array_replace(['occurred_at' => now()->timestamp, 'limit' => 2, 'observed' => 2,
            'source_ip' => '203.0.113.1', 'active_ips' => ['198.51.100.1'], 'active_known' => true, 'active_total' => 1], $changes);
    }

    public function test_normalization_rejects_invalid_events_and_caps_deduplicated_addresses(): void
    {
        foreach ([['source_ip' => 'bad'], ['occurred_at' => -1], ['occurred_at' => now()->timestamp + 61],
            ['limit' => 0], ['observed' => -1]] as $changes) {
            $this->assertNull(LimitEventSnapshot::normalize($this->snapshot($changes)));
        }
        $result = LimitEventSnapshot::normalize($this->snapshot(['active_ips' => [
            'bad', 123, '2001:db8::123/64', '2001:db8::/64', '198.51.100.1', '198.51.100.2',
            '198.51.100.3', '198.51.100.4', '198.51.100.5',
        ], 'active_total' => 6]));
        $this->assertCount(5, $result['active_ips']);
        $this->assertSame('2001:db8::/64', $result['active_ips'][0]);
        $this->assertSame(6, $result['active_total']);
        $unknown = LimitEventSnapshot::normalize($this->snapshot(['active_known' => false]));
        $this->assertSame([], $unknown['active_ips']);
        $this->assertSame(0, $unknown['active_total']);
    }

    public function test_long_connection_notice_fits_telegram_and_preserves_footers(): void
    {
        $ips = array_map(fn ($i) => "2001:db8:1234:5678:abcd:abcd:abcd:$i", range(1, 5));
        $this->mock(DeviceIpLocationService::class)->shouldReceive('lookupMany')->once()->andReturnUsing(
            fn ($addresses) => array_fill_keys($addresses, ['region' => str_repeat("\u{20000}", 150),
                'isp' => str_repeat("\u{20000}", 150), 'asn' => 'AS4294967295', 'location_source' => 'ip2location'])
        );
        $events = [];
        foreach (['conn', 'rate'] as $kind) {
            $events[$kind] = ['limit' => 100, 'snapshot' => $this->snapshot(['active_ips' => $ips, 'active_total' => 20])];
        }
        $message = app(LimitReminderPresenter::class)->render('connection', ['events' => $events, 'event_at' => now()->timestamp]);
        $this->assertLessThanOrEqual(4096, strlen(mb_convert_encoding($message, 'UTF-16LE', 'UTF-8')) / 2);
        $this->assertStringContainsString('另有 15 个 IP 未展开', $message);
        $this->assertStringContainsString('如有误报，请向管理员反馈。', $message);
        $this->assertStringContainsString('Bot → 订阅信息 → 重置订阅', $message);
    }
}
