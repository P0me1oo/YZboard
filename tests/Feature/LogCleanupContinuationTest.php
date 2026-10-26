<?php

namespace Tests\Feature;

use App\Console\Commands\ResetLog;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LogCleanupContinuationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'Asia/Shanghai']);
        $this->travelTo(\Carbon\Carbon::parse('2026-10-26 00:00:00', 'Asia/Shanghai'));
    }

    private function minute(int $id, int $at): void
    {
        DB::table('v2_stat_proxy_traffic')->insert([
            'scope' => 'node', 'unit' => 'minute', 'entity_id' => $id, 'entity_name' => '测试节点',
            'record_at' => $at, 'u' => 10, 'd' => 20,
        ]);
        DB::table('v2_stat_route_minute')->insert([
            'user_id' => $id, 'entry_id' => 1, 'server_id' => 1, 'kind' => 'direct', 'rate' => '1',
            'record_at' => $at, 'u' => 10, 'd' => 20, 'billed_u' => 10, 'billed_d' => 20,
        ]);
    }

    private function pending(): bool
    {
        return DB::table('v2_settings')->where('name', ResetLog::PENDING_SETTING)->exists();
    }

    public function test_batch_limit_resumes_until_empty_then_stops_without_deleting_today(): void
    {
        foreach (range(1, 3) as $id) $this->minute($id, now()->subMinute()->timestamp);
        $this->minute(100, now()->timestamp);
        $limits = ['--batch' => 1, '--batches' => 1];

        $this->artisan('reset:log', $limits)->assertSuccessful();
        $this->assertTrue($this->pending());
        $this->assertDatabaseCount('v2_stat_route_minute', 3);

        foreach ([2, 1] as $remaining) {
            $this->travel(5)->minutes();
            $this->artisan('reset:log', ['--resume' => true] + $limits)->assertSuccessful();
            $this->assertDatabaseCount('v2_stat_route_minute', $remaining);
            $this->assertDatabaseCount('v2_stat_proxy_traffic', $remaining);
            $this->assertTrue($this->pending());
        }

        // 最后一批恰好达到限额，下一轮确认没有过期记录才完成。
        $this->artisan('reset:log', ['--resume' => true] + $limits)->assertSuccessful();
        $this->assertFalse($this->pending());
        $this->assertDatabaseHas('v2_stat_route_minute', ['user_id' => 100, 'u' => 10, 'd' => 20]);
        $this->artisan('reset:log', ['--resume' => true])->assertSuccessful();
        $this->assertDatabaseCount('v2_stat_proxy_traffic', 1);
    }

    public function test_resume_without_pending_work_does_not_scan_or_delete_history(): void
    {
        $this->minute(1, now()->subMinute()->timestamp);
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $this->artisan('reset:log', ['--resume' => true])->assertSuccessful();
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }
        foreach ($queries as $query) {
            $this->assertStringNotContainsString('v2_stat_', $query['query']);
        }
        $this->assertDatabaseCount('v2_stat_route_minute', 1);
        $this->assertFalse($this->pending());
    }

    public function test_time_limit_keeps_pending_work_for_the_next_run(): void
    {
        foreach (range(1, 3) as $id) $this->minute($id, now()->subMinute()->timestamp);
        $delayed = false;
        DB::connection()->beforeExecuting(function ($sql) use (&$delayed): void {
            if (!$delayed && preg_match('/^delete .*v2_stat_proxy_traffic/i', $sql)) {
                $delayed = true;
                // 模拟一批执行较慢，使用真实单调时钟验证预算，而非改变保留期时钟。
                usleep(1_100_000);
            }
        });

        $this->artisan('reset:log', ['--batch' => 1, '--batches' => 100, '--max-seconds' => 1])->assertSuccessful();
        $this->assertTrue($delayed);
        $this->assertTrue($this->pending());
        $this->assertDatabaseCount('v2_stat_route_minute', 2);
        $this->assertDatabaseCount('v2_stat_proxy_traffic', 2);
        $this->artisan('reset:log', ['--resume' => true])->assertSuccessful();
        $this->assertFalse($this->pending());
        $this->assertDatabaseCount('v2_stat_route_minute', 0);
    }

    public function test_failed_batch_preserves_committed_progress_and_resumes_after_cache_loss(): void
    {
        $this->minute(1, now()->subMinute()->timestamp);
        $this->failNextQueryMatching('/^delete from ["`]v2_stat_route_minute["`]/i');
        try {
            Artisan::call('reset:log');
            $this->fail('清理删除失败应向调度器报告错误');
        } catch (QueryException) {
            $this->assertTrue($this->pending());
            $this->assertDatabaseCount('v2_stat_proxy_traffic', 0);
            $this->assertDatabaseCount('v2_stat_route_minute', 1);
        }

        Cache::flush();
        $this->travel(5)->minutes();
        $this->artisan('reset:log', ['--resume' => true])->assertSuccessful();
        $this->assertFalse($this->pending());
        $this->assertDatabaseCount('v2_stat_route_minute', 0);
    }

    public function test_failure_to_save_pending_marker_prevents_deletion(): void
    {
        $this->minute(1, now()->subMinute()->timestamp);
        $this->failNextQueryMatching('/^insert into ["`]v2_settings["`]/i');
        try {
            Artisan::call('reset:log');
            $this->fail('不能在未保存待办时开始清理');
        } catch (QueryException) {
            $this->assertFalse($this->pending());
            $this->assertDatabaseCount('v2_stat_proxy_traffic', 1);
            $this->assertDatabaseCount('v2_stat_route_minute', 1);
        }
        $this->artisan('reset:log')->assertSuccessful();
        $this->assertFalse($this->pending());
    }

    public function test_manual_and_resume_commands_share_the_lock_and_retry_after_release(): void
    {
        $this->minute(1, now()->subMinute()->timestamp);
        DB::table('v2_settings')->insert(['name' => ResetLog::PENDING_SETTING, 'value' => '1']);
        $lock = Cache::lock(ResetLog::LOCK_KEY, 1800);
        $this->assertTrue($lock->get());
        try {
            foreach ([[], ['--resume' => true]] as $options) {
                $this->artisan('reset:log', $options)->assertSuccessful();
                $this->assertDatabaseCount('v2_stat_route_minute', 1);
                $this->assertTrue($this->pending());
            }
        } finally {
            $lock->release();
        }
        $this->artisan('reset:log', ['--resume' => true])->assertSuccessful();
        $this->assertFalse($this->pending());
        $this->assertDatabaseCount('v2_stat_route_minute', 0);
    }

    public function test_failure_to_clear_completed_marker_is_safe_to_retry(): void
    {
        $this->minute(1, now()->subMinute()->timestamp);
        $this->failNextQueryMatching('/^delete from ["`]v2_settings["`]/i');
        try {
            Artisan::call('reset:log');
            $this->fail('完成标记写入失败应保留待办');
        } catch (QueryException) {
            $this->assertTrue($this->pending());
            $this->assertDatabaseCount('v2_stat_proxy_traffic', 0);
            $this->assertDatabaseCount('v2_stat_route_minute', 0);
        }
        $this->artisan('reset:log', ['--resume' => true])->assertSuccessful();
        $this->assertFalse($this->pending());
    }

    public function test_pending_cleanup_resumes_when_a_crashed_workers_lock_expires(): void
    {
        $this->minute(1, now()->subMinute()->timestamp);
        DB::table('v2_settings')->insert(['name' => ResetLog::PENDING_SETTING, 'value' => '1']);
        $this->assertTrue(Cache::lock(ResetLog::LOCK_KEY, 1800)->get());
        $this->travel(30)->minutes();
        $this->travel(1)->seconds();
        $this->artisan('reset:log', ['--resume' => true])->assertSuccessful();
        $this->assertFalse($this->pending());
        $this->assertDatabaseCount('v2_stat_route_minute', 0);
    }

    public function test_pending_run_recalculates_midnight_boundary_after_restart(): void
    {
        $this->travelTo(now()->setTime(23, 59));
        $this->minute(1, now()->subDay()->startOfDay()->timestamp);
        $this->minute(2, now()->startOfDay()->timestamp);
        $this->artisan('reset:log', ['--batch' => 1, '--batches' => 1])->assertSuccessful();
        $this->assertTrue($this->pending());

        Cache::flush();
        $this->travel(2)->minutes();
        $this->minute(3, now()->startOfDay()->timestamp);
        $this->artisan('reset:log', ['--resume' => true])->assertSuccessful();
        $this->assertFalse($this->pending());
        $this->assertDatabaseCount('v2_stat_route_minute', 1);
        $this->assertDatabaseHas('v2_stat_route_minute', ['user_id' => 3]);
    }

    public function test_daily_start_and_five_minute_resume_are_scheduled_in_background(): void
    {
        $events = collect($this->app->make(Kernel::class)->resolveConsoleSchedule()->events())
            ->filter(fn ($event) => str_contains($event->command ?? '', 'reset:log'))->values();
        $this->assertCount(2, $events);
        foreach ($events as $event) {
            $resume = str_contains($event->command, '--resume');
            $this->assertSame($resume ? '*/5 * * * *' : '0 0 * * *', $event->expression);
            $this->assertTrue($event->runInBackground);
            $this->assertTrue($event->onOneServer);
            $this->assertTrue($event->withoutOverlapping);
        }
    }
}
