<?php

namespace Tests\Feature;

use App\Console\Commands\PruneManagementLogs;
use App\Services\LogSettings;
use App\Services\SystemLogPruner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ManagementLogCleanupContinuationTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\IsolatesLogStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->isolateLogStorage();
        $this->travelTo(now()->startOfSecond());
    }

    protected function tearDown(): void
    {
        $this->removeLogStorage();
        parent::tearDown();
    }

    private function seedLogs(int $count, int $days = 8): void
    {
        for ($index = 0; $index < $count; $index++) {
            DB::table('v2_agent_log')->insert(['event_key' => hash('sha256', 'cleanup-' . $index), 'source' => 'agent',
                'level' => 'info', 'message' => '历史日志', 'occurred_at' => now()->subDays($days)->timestamp]);
        }
    }

    private function pending(): ?string
    {
        return DB::table('v2_settings')->where('name', PruneManagementLogs::PENDING_SETTING)->value('value');
    }

    public function test_resume_without_pending_work_does_not_delete_or_create_work(): void
    {
        $this->seedLogs(1);
        $this->artisan('management-log:prune --resume')->assertSuccessful();
        $this->assertDatabaseCount('v2_agent_log', 1);
        $this->assertNull($this->pending());
    }

    public function test_small_batches_resume_after_cache_loss_and_finish_without_touching_live_records(): void
    {
        $this->seedLogs(5);
        DB::table('v2_agent_log')->insert(['event_key' => hash('sha256', 'live'), 'source' => 'agent',
            'level' => 'warn', 'message' => '边界日志', 'occurred_at' => now()->subDays(7)->timestamp]);
        $this->artisan('management-log:prune --batch=2 --batches=1')->assertSuccessful();
        $this->assertDatabaseCount('v2_agent_log', 4);
        $this->assertSame('1', $this->pending());
        Cache::flush();
        $this->artisan('management-log:prune --resume --batch=2')->assertSuccessful();
        $this->assertDatabaseCount('v2_agent_log', 1);
        $this->assertNull($this->pending());
        $this->artisan('management-log:prune --resume')->assertSuccessful();
        $this->assertDatabaseCount('v2_agent_log', 1);
    }

    public function test_rotation_cleans_other_modules_while_agent_table_has_backlog(): void
    {
        $this->seedLogs(5);
        DB::table('v2_user_event_log')->insert(['user_id' => 1, 'event' => 'login', 'result' => 'success',
            'count' => 1, 'occurred_at' => now()->subDays(31)->timestamp, 'last_at' => now()->subDays(31)->timestamp]);
        $this->artisan('management-log:prune --batch=1 --batches=1')->assertSuccessful();
        $this->artisan('management-log:prune --resume --batch=1 --batches=1')->assertSuccessful();
        $this->assertDatabaseCount('v2_agent_log', 4);
        $this->assertDatabaseCount('v2_user_event_log', 0);
        $this->assertSame('2', $this->pending());
    }

    public function test_resumption_uses_extended_retention_instead_of_old_cutoff(): void
    {
        $this->seedLogs(3);
        $this->artisan('management-log:prune --batch=1 --batches=1')->assertSuccessful();
        $settings = LogSettings::view();
        $settings['agent']['retention_days'] = 30;
        LogSettings::save($settings);
        $this->artisan('management-log:prune --resume')->assertSuccessful();
        $this->assertDatabaseCount('v2_agent_log', 2);
        $this->assertNull($this->pending());
    }

    public function test_delete_failure_rolls_back_releases_lock_and_keeps_pending_work(): void
    {
        $this->seedLogs(2);
        $logger = Log::getFacadeRoot();
        Log::spy();
        try {
            $this->failNextQueryMatching('/delete from ["`]?v2_agent_log/i');
            try {
                $this->artisan('management-log:prune --batch=1')->run();
                $this->fail('删除故障必须报告失败');
            } catch (\Illuminate\Database\QueryException) {
                $this->assertDatabaseCount('v2_agent_log', 2);
                $this->assertSame('0', $this->pending());
            }
            Log::shouldHaveReceived('warning')->once()->with('管理日志清理未完成，将继续处理', \Mockery::type('array'));
        } finally {
            Log::swap($logger);
        }
        $this->artisan('management-log:prune --resume')->assertSuccessful();
        $this->assertDatabaseCount('v2_agent_log', 0);
        $this->assertNull($this->pending());
    }

    public function test_concurrent_entry_does_not_delete_or_move_pending_cursor(): void
    {
        $this->seedLogs(1);
        DB::table('v2_settings')->insert(['name' => PruneManagementLogs::PENDING_SETTING, 'value' => '3']);
        $lock = Cache::lock(PruneManagementLogs::LOCK_KEY, 1800);
        $this->assertTrue($lock->get());
        try {
            $this->artisan('management-log:prune --resume')->assertSuccessful();
            $this->assertDatabaseCount('v2_agent_log', 1);
            $this->assertSame('3', $this->pending());
        } finally {
            $lock->release();
        }
    }

    public function test_time_budget_keeps_next_module_for_resumption(): void
    {
        DB::table('v2_settings')->insert(['name' => PruneManagementLogs::PENDING_SETTING, 'value' => '4']);
        $this->mock(SystemLogPruner::class, fn ($mock) => $mock->shouldReceive('prune')->once()->andReturnUsing(function (): int {
            usleep(1_100_000);
            return 0;
        }));
        $this->artisan('management-log:prune --resume --max-seconds=1')->assertSuccessful();
        $this->assertSame('0', $this->pending());
        $this->app->forgetInstance(SystemLogPruner::class);
        $this->artisan('management-log:prune --resume')->assertSuccessful();
        $this->assertNull($this->pending());
    }
}
