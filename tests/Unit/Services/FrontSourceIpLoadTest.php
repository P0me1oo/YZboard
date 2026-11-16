<?php

namespace Tests\Unit\Services;

use App\Services\FrontPoolScheduler;
use App\Services\FrontRouteHealth;
use App\Services\FrontSourceIpLoad;
use App\Utils\CacheKey;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class FrontSourceIpLoadTest extends TestCase
{
    private function config(string $strategy = 'remaining'): array
    {
        return [...FrontPoolScheduler::DEFAULTS, 'source_ip_enabled' => true, 'source_ip_strategy' => $strategy];
    }

    private function candidate(int $id, int $count, int $remaining = 100): array
    {
        return ['member_id' => $id, 'priority' => 1, 'weight' => 1, 'addresses' => ['192.0.2.' . $id],
            'source_ip' => ['fresh' => true, 'count' => $count], 'quota_low' => false, 'ratio' => 50,
            'traffic_status' => 'available', 'traffic_remaining' => $remaining, 'reset_at' => now()->timestamp + 3600];
    }

    public function test_reports_deduplicate_accounts_and_keep_distinct_full_ipv6_addresses(): void
    {
        FrontSourceIpLoad::record(1, [1 => ['1.1.1.1', '1.1.1.1:443', '::ffff:1.1.1.1'],
            2 => ['1.1.1.1', '2400:cb00:1:2::1', '2400:cb00:1:2::2', '10.0.0.1', 'invalid']]);
        $key = CacheKey::get('SERVER_RAW_SOURCE_IPS', 1);
        $this->assertSame(['1.1.1.1', '2400:cb00:1:2::1', '2400:cb00:1:2::2'], Cache::get($key)['ips']);
        FrontSourceIpLoad::record(1, [1 => ['8.8.8.8']], now()->timestamp - 1);
        $this->assertCount(3, Cache::get($key)['ips']);
        FrontSourceIpLoad::record(1, []);
        $this->assertSame([], Cache::get($key)['ips']);
    }

    public function test_existing_assignment_moves_at_three_ips_without_load_cooldown_or_batch_limit(): void
    {
        $a = $this->candidate(1, 2, 100); $b = $this->candidate(2, 0, 1000);
        $config = $this->config(); $previous = ['member_id' => 1, 'moved_at' => now()->timestamp];
        $options = FrontRouteHealth::eligible([$a, $b], $config);
        $this->assertSame(1, FrontSourceIpLoad::choose('stable', $options, $previous, $config)['member_id']);
        $a['source_ip']['count'] = 3;
        $b['priority'] = 2;
        $options = FrontRouteHealth::eligible([$a, $b], $config);
        $this->assertSame([2], array_column($options, 'member_id'));
        $this->assertSame(2, FrontSourceIpLoad::choose('stable', $options, $previous, $config)['member_id']);
    }

    public function test_existing_alive_report_processing_records_the_dns_scheduling_snapshot(): void
    {
        $alive = [1 => ['1.1.1.1', '2.2.2.2'], 2 => ['2.2.2.2']];
        $recorder = \Mockery::mock(\App\Services\InboundIpRecorder::class);
        $recorder->shouldReceive('record')->once()->with($alive, now()->timestamp);
        $devices = \Mockery::mock(\App\Services\DeviceStateService::class);
        $devices->shouldReceive('replaceNodeDevices')->once()->with(7, $alive);
        $this->app->instance(\App\Services\InboundIpRecorder::class, $recorder);
        $this->app->instance(\App\Services\DeviceStateService::class, $devices);
        \App\Services\ServerService::processAlive(7, $alive, now()->timestamp);
        $this->assertSame(['1.1.1.1', '2.2.2.2'], Cache::get(CacheKey::get('SERVER_RAW_SOURCE_IPS', 7))['ips']);
    }

    public function test_capacity_is_checked_before_quota_and_priority_within_the_healthy_scope(): void
    {
        $full = $this->candidate(1, 3); $free = $this->candidate(2, 1);
        $free['quota_low'] = true; $free['ratio'] = 5; $free['priority'] = 2;
        $this->assertSame([$free], FrontRouteHealth::eligible([$full, $free], [...$this->config(), 'quota_enabled' => true]));
        // 此线路只有满额成员健康时仍保留解析，不能重新加入故障成员。
        $this->assertSame([$full], FrontRouteHealth::eligible([$full], $this->config()));
    }

    public function test_remaining_strategy_compares_bytes_rather_than_percentages_and_prefers_unlimited(): void
    {
        $a = $this->candidate(1, 0, 2000000); $a['ratio'] = 20;
        $b = $this->candidate(2, 0, 100000); $b['ratio'] = 90;
        $this->assertSame(1, FrontSourceIpLoad::choose('new', [$a, $b], [], $this->config())['member_id']);
        $b['traffic_status'] = 'unlimited'; $b['traffic_remaining'] = 0;
        $this->assertSame(2, FrontSourceIpLoad::choose('new', [$a, $b], [], $this->config())['member_id']);
    }

    public function test_cycle_strategy_prefers_earliest_reset_and_breaks_reset_ties_by_remaining_bytes(): void
    {
        $a = $this->candidate(1, 0, 100); $a['reset_at'] = now()->timestamp + 60;
        $b = $this->candidate(2, 0, 1000);
        $this->assertSame(1, FrontSourceIpLoad::choose('new', [$a, $b], [], $this->config('cycle'))['member_id']);
        $b['reset_at'] = $a['reset_at'];
        $this->assertSame(2, FrontSourceIpLoad::choose('new', [$a, $b], [], $this->config('cycle'))['member_id']);
        $b['traffic_status'] = 'unlimited';
        $this->assertSame(1, FrontSourceIpLoad::choose('new', [$a, $b], [], $this->config('cycle'))['member_id']);
        $a['reset_at'] = null;
        $this->assertSame(2, FrontSourceIpLoad::choose('new', [$a, $b], [], $this->config('cycle'))['member_id']);
    }

    public function test_full_pool_keeps_existing_assignment_and_new_assignments_use_least_busy_member(): void
    {
        $a = $this->candidate(1, 4, 10000); $b = $this->candidate(2, 3, 100);
        $b['priority'] = 2;
        $options = FrontRouteHealth::eligible([$a, $b], $this->config());
        $this->assertSame(1, FrontSourceIpLoad::choose('existing', $options, ['member_id' => 1], $this->config())['member_id']);
        $this->assertSame(2, FrontSourceIpLoad::choose('new', $options, [], $this->config())['member_id']);
    }

    public function test_missing_reports_are_not_zero_and_fresh_nonfull_members_are_used_first(): void
    {
        $a = $this->candidate(1, 0); $a['source_ip'] = ['count' => null, 'fresh' => false];
        $b = $this->candidate(2, 1);
        $this->assertSame([$b], FrontSourceIpLoad::eligible([$a, $b], $this->config()));
        $b['source_ip']['fresh'] = false;
        $this->assertSame([$a, $b], FrontSourceIpLoad::eligible([$a, $b], $this->config()));
        $this->assertSame(1, FrontSourceIpLoad::choose('existing', [$a, $b], ['member_id' => 1], $this->config())['member_id']);
        $this->assertSame([$a, $b], FrontSourceIpLoad::eligible([$a, $b], FrontPoolScheduler::DEFAULTS));
    }

    public function test_exhausted_quota_uses_another_healthy_member_and_all_exhausted_keeps_fallback(): void
    {
        $empty = $this->candidate(1, 1, 0); $available = $this->candidate(2, 1, 100);
        $config = $this->config();
        $options = FrontRouteHealth::eligible([$empty, $available], $config);
        $this->assertSame([$available], $options);
        $this->assertSame(2, FrontSourceIpLoad::choose('existing', $options, ['member_id' => 1], $config)['member_id']);
        $available['traffic_remaining'] = 0;
        $this->assertCount(2, FrontSourceIpLoad::eligible([$empty, $available], $config));
        $empty['traffic_status'] = 'unavailable'; $empty['traffic_remaining'] = null;
        $this->assertSame([$empty], FrontSourceIpLoad::eligible([$empty, $available], $config));
    }
}
