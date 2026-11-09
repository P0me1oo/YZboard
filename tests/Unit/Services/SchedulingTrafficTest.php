<?php

namespace Tests\Unit\Services;

use App\Models\ServerMachine;
use App\Services\SchedulingTraffic;
use Tests\TestCase;

class SchedulingTrafficTest extends TestCase
{
    public function test_absolute_reset_handles_month_end_and_dst_without_panel_timezone_conversion(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 31)->startOfDay());
        $base = ['fresh' => true, 'online' => true, 'traffic' => ['limit' => 2000, 'used' => 500,
            'timezone' => 'America/Los_Angeles', 'reset_at' => '2026-11-01T01:30:00-07:00']];
        $before = SchedulingTraffic::view(new ServerMachine(), $base);
        $base['traffic']['reset_at'] = '2026-11-01T01:30:00-08:00';
        $after = SchedulingTraffic::view(new ServerMachine(), $base);
        $this->assertSame(3600, $after['reset_at'] - $before['reset_at']);
        $this->assertEquals(75, $after['remaining_percent']);
        foreach (['invalid', '2026-11-01 01:30:00', null] as $reset) {
            $base['traffic']['reset_at'] = $reset;
            $this->assertNull(SchedulingTraffic::view(new ServerMachine(), $base)['reset_at']);
        }
        $base['traffic']['reset_at'] = '2026-10-01T00:00:00Z';
        $this->assertSame('unavailable', SchedulingTraffic::view(new ServerMachine(), $base)['traffic_status']);
    }

    public function test_missing_values_are_distinct_from_unlimited_and_invalid_timezones_are_not_exposed(): void
    {
        $base = ['fresh' => true, 'online' => true, 'traffic' => ['limit' => 0, 'used' => 0, 'timezone' => 'invalid']];
        $view = SchedulingTraffic::view(new ServerMachine(), $base);
        $this->assertSame('unlimited', $view['traffic_status']);
        $this->assertNull($view['reset_timezone']);
        foreach (['limit', 'used'] as $field) {
            $invalid = $base; $invalid['traffic'][$field] = null;
            $view = SchedulingTraffic::view(new ServerMachine(), $invalid);
            $this->assertSame('unavailable', $view['traffic_status']);
            $this->assertNull($view['remaining_percent']);
        }
    }
}
