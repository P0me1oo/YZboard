<?php

namespace Tests\Feature;

use App\Services\AccessLogPruner;
use App\Services\LogSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AccessLogCleanupContinuationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
    }

    private function seedLogs(int $count, int $days = 8): void
    {
        for ($index = 0; $index < $count; $index++) {
            DB::table('v2_proxy_access_log')->insert(['server_id' => 1, 'server_name' => '清理测试',
                'batch_id' => str_repeat('a', 32), 'position' => $index, 'user_id' => 1,
                'connected_at' => now()->subDays($days)->timestamp, 'destination' => 'example.test', 'port' => 443]);
        }
    }

    private function pending(): bool
    {
        return DB::table('v2_settings')->where('name', AccessLogPruner::PENDING_SETTING)->exists();
    }

    public function test_resume_without_work_does_not_write_and_small_batches_survive_cache_loss(): void
    {
        $this->seedLogs(5);
        $this->artisan('access-log:prune --resume')->assertSuccessful();
        $this->assertDatabaseCount('v2_proxy_access_log', 5);
        $this->assertFalse($this->pending());
        $this->artisan('access-log:prune --batch=2 --batches=1')->assertSuccessful();
        $this->assertDatabaseCount('v2_proxy_access_log', 3);
        $this->assertTrue($this->pending());
        Cache::flush();
        $this->artisan('access-log:prune --resume --batch=2')->assertSuccessful();
        $this->assertDatabaseCount('v2_proxy_access_log', 0);
        $this->assertFalse($this->pending());
    }

    public function test_retention_extension_between_batches_preserves_remaining_rows(): void
    {
        $this->seedLogs(3);
        $changed = false;
        DB::listen(function ($query) use (&$changed): void {
            if (!$changed && preg_match('/^delete from ["`]?v2_proxy_access_log/', $query->sql)) {
                $changed = true;
                $settings = LogSettings::view();
                $settings['access']['retention_days'] = 30;
                LogSettings::save($settings);
            }
        });
        $this->artisan('access-log:prune --batch=1')->assertSuccessful();
        $this->assertDatabaseCount('v2_proxy_access_log', 2);
        $this->assertFalse($this->pending());
    }

    public function test_failure_keeps_pending_work_and_releases_lock_for_retry(): void
    {
        $this->seedLogs(3);
        $this->failNextQueryMatching('/^delete from ["`]?v2_proxy_access_log/');
        try {
            app(AccessLogPruner::class)->run(batchSize: 1);
            $this->fail('删除失败必须报错');
        } catch (\Illuminate\Database\QueryException) {
            $this->assertDatabaseCount('v2_proxy_access_log', 3);
            $this->assertTrue($this->pending());
        }
        $this->assertSame(3, app(AccessLogPruner::class)->run(resume: true)['deleted']);
        $this->assertFalse($this->pending());
    }

    public function test_concurrent_cleanup_does_not_change_records_or_pending_work(): void
    {
        $this->seedLogs(1);
        $lock = Cache::lock(AccessLogPruner::LOCK_KEY, 1800);
        $this->assertTrue($lock->get());
        try {
            $this->assertTrue(app(AccessLogPruner::class)->run()['busy']);
            $this->assertDatabaseCount('v2_proxy_access_log', 1);
            $this->assertFalse($this->pending());
        } finally {
            $lock->release();
        }
    }

    public function test_budget_stops_after_committed_batch_and_preserves_retention_boundary(): void
    {
        $this->seedLogs(3);
        DB::table('v2_proxy_access_log')->where('position', 2)->update(['connected_at' => now()->subDays(7)->timestamp]);
        $pruner = new class extends AccessLogPruner {
            protected function deleteBatch(int $size): int
            {
                $count = parent::deleteBatch($size);
                usleep(1_050_000);
                return $count;
            }
        };
        $this->assertSame(1, $pruner->run(batchSize: 1, maxSeconds: 1)['deleted']);
        $this->assertDatabaseCount('v2_proxy_access_log', 2);
        $this->assertTrue($this->pending());
        $this->assertSame(1, app(AccessLogPruner::class)->run(resume: true)['deleted']);
        $this->assertDatabaseCount('v2_proxy_access_log', 1);
        $this->assertFalse($this->pending());
    }
}
