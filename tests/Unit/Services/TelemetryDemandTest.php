<?php

namespace Tests\Unit\Services;

use App\Services\TelemetryDemand;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class TelemetryDemandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_idle_sources_keep_low_frequency_details_and_no_speeds(): void
    {
        $this->assertSame(['detail_interval' => 60, 'user_speeds' => false, 'lease_seconds' => 30],
            app(TelemetryDemand::class)->forSource('node:1'));
    }

    public function test_views_only_enable_requested_details_and_user_page_enables_speeds(): void
    {
        $demand = app(TelemetryDemand::class);
        $demand->renew(['node:1', 'machine:2', 'users']);
        $this->assertSame(1, $demand->forSource('node:1')['detail_interval']);
        $this->assertSame(60, $demand->forSource('node:2')['detail_interval']);
        $this->assertTrue($demand->forSource('node:2')['user_speeds']);
        $this->assertSame(1, $demand->forSource('machine:2')['detail_interval']);
        $this->assertFalse($demand->forSource('machine:2')['user_speeds']);
        $this->assertSame(60, $demand->forSource('machine:1')['detail_interval']);
    }

    public function test_two_viewers_renew_shared_lease_and_abandoned_views_expire(): void
    {
        $first = new TelemetryDemand();
        $second = new TelemetryDemand();
        $first->renew(['node:1']);
        $this->travel(10)->seconds();
        $second->renew(['node:1']);
        $this->travel(6)->seconds();
        $this->assertSame(1, $first->forSource('node:1')['detail_interval']);
        $this->travel(10)->seconds();
        $this->assertSame(60, $first->forSource('node:1')['detail_interval']);
        $first->renew(['node:1']);
        $this->assertSame(1, $first->forSource('node:1')['detail_interval']);
    }

    public function test_no_subscribed_fields_do_not_create_demand(): void
    {
        $demand = new TelemetryDemand();
        $demand->renew([]);
        $this->assertNull(Cache::get('realtime:telemetry:demand:v1'));
    }
}
