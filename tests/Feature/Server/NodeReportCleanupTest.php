<?php

namespace Tests\Feature\Server;

use App\Models\NodeReportBatch;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class NodeReportCleanupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
    }

    private function report(array $attributes = []): NodeReportBatch
    {
        $reportId = Str::random(20);
        return NodeReportBatch::create($attributes + [
            'server_id' => 1, 'server_type' => 'vless',
            'report_id' => $reportId, 'report_key' => hash('sha256', $reportId),
            'server_snapshot' => ['id' => 1, 'rate' => 1, 'type' => 'vless'],
            'traffic' => [1 => [100, 200]],
            'relay_traffic' => [], 'relay_user_traffic' => [],
            'record_at' => now()->startOfDay()->timestamp,
            'status' => NodeReportBatch::STATUS_PROCESSED,
            'processed_at' => now()->subDays(8),
        ]);
    }

    public function test_only_due_successful_reports_are_cleared_and_identity_is_preserved(): void
    {
        $due = $this->report(['payload_cleanup_at' => now()->timestamp, 'processed_at' => now()->subDays(7)]);
        $future = $this->report(['payload_cleanup_at' => now()->addSecond()->timestamp]);
        $protected = [$future];
        foreach (['pending', 'processing', 'failed'] as $status) {
            $protected[] = $this->report(['status' => $status, 'payload_cleanup_at' => now()->subDay()->timestamp]);
        }
        $identity = $due->only(['id', 'server_id', 'report_id', 'report_key', 'status', 'processed_at', 'updated_at']);

        $this->assertSame(0, Artisan::call('node:prune-reports'));
        $due->refresh();
        $this->assertEquals($identity, $due->only(array_keys($identity)));
        foreach (['server_snapshot', 'traffic', 'relay_traffic', 'relay_user_traffic', 'payload_cleanup_at'] as $field) {
            $this->assertNull($due->$field);
        }
        foreach ($protected as $report) {
            $this->assertNotNull($report->fresh()->traffic);
        }
        $this->assertSame(5, NodeReportBatch::count());
        $this->assertSame(0, Artisan::call('node:prune-reports'));
        $this->assertStringContainsString('清空 0 条', Artisan::output());
    }

    public function test_legacy_registration_resumes_in_batches_and_uses_completion_time(): void
    {
        $cleared = $this->report(['server_snapshot' => null, 'traffic' => null, 'relay_traffic' => null, 'relay_user_traffic' => null]);
        $old = $this->report();
        $recent = $this->report(['processed_at' => now()->subDay(), 'created_at' => now()->subDays(20)]);
        $missingCompletion = $this->report(['processed_at' => null]);

        Artisan::call('node:prune-reports', ['--batch' => 1, '--batches' => 1]);
        $this->assertNull($cleared->fresh()->payload_cleanup_at);
        $this->assertSame(0, $old->fresh()->payload_cleanup_at);
        $this->assertNotNull($old->fresh()->traffic);

        Artisan::call('node:prune-reports', ['--batch' => 1]);
        $this->assertNull($old->fresh()->traffic);
        $this->assertSame(now()->addDays(6)->timestamp, $recent->fresh()->payload_cleanup_at);
        $this->assertNotNull($recent->fresh()->traffic);
        $this->assertSame(0, $missingCompletion->fresh()->payload_cleanup_at);
        $this->assertNotNull($missingCompletion->fresh()->traffic);
    }

    public function test_reports_completed_by_an_older_worker_are_registered_later(): void
    {
        $report = $this->report(['status' => 'pending', 'processed_at' => null]);
        Artisan::call('node:prune-reports');
        $this->assertSame(0, $report->fresh()->payload_cleanup_at);

        // 模拟回退期间的旧程序：只更新结算状态，不认识新增清理时间。
        DB::table('v2_node_report_batch')->where('id', $report->id)
            ->update(['status' => 'processed', 'processed_at' => now()]);
        Artisan::call('node:prune-reports');
        $this->assertSame(now()->addDays(7)->timestamp, $report->fresh()->payload_cleanup_at);
        $this->assertNotNull($report->fresh()->traffic);
        $this->travel(7)->days();
        Artisan::call('node:prune-reports');
        $this->assertNull($report->fresh()->traffic);
        $this->assertSame('processed', $report->fresh()->status);
    }

    public function test_cleanup_failure_keeps_payload_and_marker_for_retry(): void
    {
        $report = $this->report(['payload_cleanup_at' => now()->subDay()->timestamp]);
        $before = $report->fresh()->getAttributes();
        $this->failNextQueryMatching('/update ["`]v2_node_report_batch["`] set ["`]server_snapshot["`]/i');
        try {
            Artisan::call('node:prune-reports');
            $this->fail('清理失败应当向调用方报错');
        } catch (QueryException) {
            $this->assertSame($before, $report->fresh()->getAttributes());
        }

        Artisan::call('node:prune-reports');
        $this->assertNull($report->fresh()->traffic);
        $this->assertSame($before['report_key'], $report->fresh()->report_key);
    }

    public function test_registration_failure_does_not_repeat_previously_completed_cleanup(): void
    {
        $due = $this->report(['payload_cleanup_at' => now()->subDay()->timestamp]);
        $legacy = $this->report();
        $this->failNextQueryMatching('/update ["`]v2_node_report_batch["`] set ["`]payload_cleanup_at["`]/i');
        try {
            Artisan::call('node:prune-reports');
            $this->fail('登记失败应当向调用方报错');
        } catch (QueryException) {
            $this->assertNull($due->fresh()->traffic);
            $this->assertSame(0, $legacy->fresh()->payload_cleanup_at);
            $this->assertNotNull($legacy->fresh()->traffic);
        }

        Artisan::call('node:prune-reports');
        $this->assertNull($legacy->fresh()->traffic);
        $this->assertStringContainsString('清空 1 条', Artisan::output());
        $this->assertSame(2, NodeReportBatch::count());
    }

    public function test_ready_cleanup_is_bounded_and_ordered_by_due_time(): void
    {
        $later = $this->report(['payload_cleanup_at' => now()->subHour()->timestamp]);
        $earlier = $this->report(['payload_cleanup_at' => now()->subDay()->timestamp]);
        Artisan::call('node:prune-reports', ['--batch' => 1, '--batches' => 1]);
        $this->assertNull($earlier->fresh()->traffic);
        $this->assertNotNull($later->fresh()->traffic);
        Artisan::call('node:prune-reports', ['--batch' => 1, '--batches' => 1]);
        $this->assertNull($later->fresh()->traffic);
    }

    public function test_minute_retry_task_no_longer_cleans_processed_payloads(): void
    {
        Bus::fake();
        $report = $this->report();
        Artisan::call('node:retry-reports');
        $this->assertNotNull($report->fresh()->traffic);
        $this->assertSame(0, $report->fresh()->payload_cleanup_at);
        Bus::assertNothingDispatched();
    }
}
