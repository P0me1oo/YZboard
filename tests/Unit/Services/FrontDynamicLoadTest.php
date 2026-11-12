<?php

namespace Tests\Unit\Services;

use App\Models\BalancerMember;
use App\Models\Server;
use App\Services\FrontDynamicLoad;
use App\Services\FrontendAssignment;
use App\Services\FrontPoolScheduler;
use App\Services\FrontRouteHealth;
use App\Utils\CacheKey;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class FrontDynamicLoadTest extends TestCase
{
    private function member(?float $bandwidth): BalancerMember
    {
        $member = new BalancerMember(['bandwidth_mbps' => $bandwidth]);
        $node = new Server(['type' => 'vless']); $node->id = 1;
        return $member->setRelation('runtimeNode', $node);
    }

    private function sample(BalancerMember $member, array $metrics): array
    {
        Cache::put(CacheKey::get('SERVER_VLESS_METRICS', 1), ['updated_at' => now()->timestamp, ...$metrics], 600);
        $state = FrontDynamicLoad::sample($member, FrontPoolScheduler::DEFAULTS);
        $member->scheduling_state = ['load' => $state];
        return $state;
    }

    public function test_mixed_metrics_have_comparable_pressure_without_double_counting_bandwidth(): void
    {
        $bandwidth = $this->sample($this->member(100), ['inbound_speed' => 5000000, 'outbound_speed' => 5000000]);
        $connections = $this->sample($this->member(null), ['active_connections' => 500]);
        $this->assertEquals(40, $bandwidth['value']);
        $this->assertEquals(0.5, $bandwidth['pressure']);
        $this->assertEquals($bandwidth['pressure'], $connections['pressure']);
    }

    public function test_only_distinct_fresh_samples_count_and_missing_data_resets_confirmation(): void
    {
        $member = $this->member(null);
        $sample = $this->sample($member, ['active_connections' => 1500]);
        $this->assertFalse($sample['overloaded']);
        for ($i = 0; $i < 4; $i++) $this->assertSame(1, $this->sample($member, ['active_connections' => 1500])['samples']);
        $this->travel(30)->seconds(); $this->sample($member, ['active_connections' => 1500]);
        $this->travel(30)->seconds(); $this->assertTrue($this->sample($member, ['active_connections' => 1500])['overloaded']);
        $this->travel(121)->seconds();
        $stale = FrontDynamicLoad::sample($member, FrontPoolScheduler::DEFAULTS);
        $this->assertFalse($stale['fresh']); $this->assertFalse($stale['overloaded']);
        $member->scheduling_state = ['load' => $stale];
        $this->assertFalse($this->sample($member, ['active_connections' => 1500])['overloaded']);
        $this->assertFalse($this->sample($this->member(100), ['active_connections' => 0])['fresh']);
    }

    public function test_smoothing_ignores_a_single_spike_and_configuration_changes_reset_units(): void
    {
        $member = $this->member(null);
        $this->sample($member, ['active_connections' => 100]);
        $this->travel(30)->seconds();
        $this->assertEquals(670, $this->sample($member, ['active_connections' => 2000])['value']);
        $member->bandwidth_mbps = 100;
        $this->travel(30)->seconds();
        $this->assertEquals(8, $this->sample($member, ['inbound_speed' => 1000000, 'outbound_speed' => 0])['value']);
    }

    public function test_existing_users_are_sticky_until_overload_cooldown_and_batch_budget_allow_migration(): void
    {
        $config = [...FrontPoolScheduler::DEFAULTS, 'dynamic_enabled' => true];
        $candidates = [
            ['member_id' => 1, 'weight' => 1, 'addresses' => ['192.0.2.1'], 'load' => ['fresh' => true, 'pressure' => 1.2, 'overloaded' => true]],
            ['member_id' => 2, 'weight' => 1, 'addresses' => ['192.0.2.2'], 'load' => ['fresh' => true, 'pressure' => 0.2]],
        ];
        $budget = 1;
        $old = ['member_id' => 1, 'moved_at' => now()->timestamp];
        $this->assertSame(1, FrontDynamicLoad::choose('a', $candidates, $old, $config, $budget)['member_id']);
        $this->travel(301)->seconds();
        $candidates[1]['load']['fresh'] = false;
        $this->assertSame(1, FrontDynamicLoad::choose('a', $candidates, $old, $config, $budget)['member_id']);
        $candidates[1]['load']['fresh'] = true;
        $this->assertSame(2, FrontDynamicLoad::choose('a', $candidates, $old, $config, $budget)['member_id']);
        $this->assertSame(0, $budget);
        $this->assertSame(1, FrontDynamicLoad::choose('b', $candidates, $old, $config, $budget)['member_id']);
        // 故障绕过冷却和迁移预算。
        $this->assertSame(2, FrontDynamicLoad::choose('b', [$candidates[1]], $old, $config, $budget)['member_id']);
        $config['dynamic_enabled'] = false;
        $this->assertSame(FrontendAssignment::choose('a', $candidates), FrontDynamicLoad::choose('a', $candidates, $old, $config, $budget)['member_id']);
    }

    public function test_new_users_prefer_lower_pressure_and_missing_metrics_use_static_weights(): void
    {
        $config = [...FrontPoolScheduler::DEFAULTS, 'dynamic_enabled' => true];
        $candidates = [
            ['member_id' => 1, 'weight' => 1, 'addresses' => ['192.0.2.1'], 'load' => ['fresh' => true, 'pressure' => 0.1]],
            ['member_id' => 2, 'weight' => 1, 'addresses' => ['192.0.2.2'], 'load' => ['fresh' => true, 'pressure' => 0.9]],
        ];
        $budget = 0; $counts = [1 => 0, 2 => 0];
        for ($i = 0; $i < 2000; $i++) $counts[FrontDynamicLoad::choose((string) $i, $candidates, [], $config, $budget)['member_id']]++;
        $this->assertGreaterThan(1700, $counts[1]);
        $candidates[1]['load']['fresh'] = false;
        for ($i = 0; $i < 50; $i++) $this->assertSame(FrontendAssignment::choose((string) $i, $candidates), FrontDynamicLoad::choose((string) $i, $candidates, [], $config, $budget)['member_id']);
    }

    public function test_link_failures_recovery_staleness_and_changed_targets_require_confirmation(): void
    {
        $sample = fn ($healthy) => ['healthy' => $healthy, 'rtt_ms' => 10, 'checked_at' => now()->timestamp];
        $state = FrontRouteHealth::update([], $sample(false), 'a');
        $this->assertTrue($state['healthy']);
        $this->assertTrue(FrontRouteHealth::update($state, $sample(false), 'a')['healthy']);
        $this->travel(15)->seconds();
        $state = FrontRouteHealth::update($state, $sample(false), 'a');
        $this->assertFalse($state['healthy']);
        $this->travel(15)->seconds(); $state = FrontRouteHealth::update($state, $sample(true), 'a');
        $this->assertFalse($state['healthy']);
        $this->travel(15)->seconds(); $state = FrontRouteHealth::update($state, $sample(true), 'a');
        $this->assertTrue($state['healthy']);
        $this->travel(100)->seconds(); $state = FrontRouteHealth::update($state, null, 'a');
        $this->assertTrue($state['healthy']);
        $this->travel(81)->seconds(); $state = FrontRouteHealth::update($state, null, 'a');
        $this->assertFalse($state['healthy']);
        $this->assertFalse(FrontRouteHealth::update($state, $sample(true), 'changed')['healthy']);
        $this->assertFalse(FrontRouteHealth::update(['healthy' => false, 'reason' => 'offline'], $sample(true), 'a')['healthy']);
    }

    public function test_remaining_quota_is_filtered_after_the_landing_health_scope(): void
    {
        $backup = ['member_id' => 2, 'priority' => 2, 'ratio' => 5, 'quota_low' => true];
        $primary = ['member_id' => 1, 'priority' => 1, 'ratio' => 80, 'quota_low' => false];
        $config = [...FrontPoolScheduler::DEFAULTS, 'quota_enabled' => true];
        $this->assertSame([$primary], FrontRouteHealth::eligible([$primary, $backup], $config));
        $this->assertSame([$backup], FrontRouteHealth::eligible([$backup], $config));
    }
}
