<?php

namespace Tests\Unit;

use App\Services\DatabaseMaintenance;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DatabaseMaintenanceRetentionTest extends TestCase
{
    public function test_seven_day_cleanup_matches_scheduled_retention_across_daylight_saving_changes(): void
    {
        $timezone = date_default_timezone_get();
        try {
            date_default_timezone_set('America/New_York');
            Schema::shouldReceive('hasTable')->with('v2_proxy_access_log')->twice()->andReturn(false);
            Schema::shouldReceive('hasTable')->with('v2_telegram_bot_updates')->twice()->andReturn(false);
            foreach (['2026-03-09 12:00:00', '2026-11-02 12:00:00'] as $date) {
                $this->travelTo(Carbon::parse($date, 'America/New_York'));
                $items = app(DatabaseMaintenance::class)->preview(['access', 'telegram'], 1)['items'];
                foreach ($items as $item) {
                    // 原定时任务保留 7×24 小时，夏令时切换也不能提前删除。
                    $this->assertSame(now()->timestamp - 7 * 86400, $item['cutoff']);
                }
            }
        } finally {
            $this->travelBack();
            date_default_timezone_set($timezone);
        }
    }
}
