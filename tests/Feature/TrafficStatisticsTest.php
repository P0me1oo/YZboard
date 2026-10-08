<?php

namespace Tests\Feature;

use App\Jobs\ProcessNodeReportBatch;
use App\Jobs\StatUserJob;
use App\Models\NodeReportBatch;
use App\Models\Server;
use App\Models\StatServer;
use App\Models\StatUser;
use App\Models\User;
use App\Services\TrafficStatisticsRecorder;
use App\Services\UserRouteTraffic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TrafficStatisticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
        DB::table('v2_settings')->where('name', 'traffic_statistics_started_at')
            ->update(['value' => (string) now()->startOfDay()->subDays(29)->timestamp]);
        DB::table('v2_settings')->whereIn('name', ['user_route_statistics_started_at', 'node_hourly_started_at'])
            ->update(['value' => (string) now()->startOfDay()->subDays(29)->timestamp]);
        Sanctum::actingAs($this->user(true));
    }

    private function user(bool $admin = false): User
    {
        return User::create(['email' => Str::random(12) . '@example.test', 'password' => Str::random(32),
            'uuid' => (string) Str::uuid(), 'token' => Str::random(32), 'is_admin' => $admin]);
    }

    private function node(): Server
    {
        return Server::create(['name' => '测试节点', 'type' => 'vless', 'host' => 'example.test',
            'port' => 443, 'server_port' => 443, 'rate' => 2, 'group_ids' => [], 'enabled' => true]);
    }

    private function path(string $endpoint, array $params = []): string
    {
        return '/api/v2/' . hash('crc32b', config('app.key')) . '/statistics/' . $endpoint . '?' . http_build_query($params);
    }

    private function record(int $uid, int $sid, int $u = 100, int $d = 200, int $daysAgo = 0, string $kind = 'entry'): void
    {
        app(TrafficStatisticsRecorder::class)->add($uid, $sid, $kind, now()->startOfDay()->subDays($daysAgo)->timestamp, $u, $d, $u * 2, $d * 2);
        app(UserRouteTraffic::class)->record($uid, $sid, $sid, $kind, 2,
            now()->startOfDay()->subDays($daysAgo)->timestamp, $u, $d, $u * 2, $d * 2, now()->timestamp);
        $stat = StatServer::firstOrCreate(['server_id' => $sid, 'server_type' => 'vless', 'record_type' => 'd',
            'record_at' => now()->startOfDay()->subDays($daysAgo)->timestamp], ['u' => 0, 'd' => 0]);
        StatServer::whereKey($stat->id)->incrementEach(['u' => $u, 'd' => $d]);
    }

    private function batch(User $user, Server $node, Server $relay): NodeReportBatch
    {
        return NodeReportBatch::create(['server_id' => $node->id, 'server_type' => $node->type,
            'report_id' => Str::random(20), 'report_key' => Str::random(64),
            'server_snapshot' => ['id' => $node->id, 'rate' => $node->rate],
            'traffic' => [$user->id => [100, 200]],
            'relay_traffic' => [['server_id' => $relay->id, 'server_type' => $relay->type, 'u' => 90, 'd' => 180]],
            'relay_user_traffic' => [$user->id => [$relay->id => [90, 180]]],
            'record_at' => now()->startOfDay()->timestamp, 'status' => 'pending', 'attempts' => 0]);
    }

    public function test_batch_retry_counts_once_and_relay_does_not_inflate_user_total(): void
    {
        $user = $this->user(); $node = $this->node(); $relay = $this->node();
        $batch = $this->batch($user, $node, $relay);
        Redis::shouldReceive('sadd')->once()->andReturn(1);
        (new ProcessNodeReportBatch($batch->id))->handle();
        (new ProcessNodeReportBatch($batch->id))->handle();
        $this->assertSame(2, DB::table('v2_stat_user_server')->count());
        $this->assertSame(600, (int) $user->fresh()->u + (int) $user->fresh()->d);
        $this->getJson($this->path('traffic', ['period' => 'today']))->assertOk()->assertJsonPath('data.summary.total', 570);
        $this->getJson($this->path('user', ['user_id' => $user->id, 'metric' => 'billed']))->assertOk()
            ->assertJsonPath('data.billed_summary.total', 600)->assertJsonPath('data.actual_summary.total', 300)
            ->assertJsonPath('data.direct_summary.total', 30)->assertJsonPath('data.relay_summary.total', 270);
        $relayRow = collect($this->getJson($this->path('user', ['user_id' => $user->id]))->json('data.list'))->firstWhere('kind', 'relay');
        $this->assertSame(540, $relayRow['billed_total']);
    }

    public function test_transaction_failure_rolls_back_new_statistics_and_recovery_counts_once(): void
    {
        $user = $this->user(); $node = $this->node(); $relay = $this->node();
        $batch = $this->batch($user, $node, $relay);
        Redis::shouldReceive('sadd')->once()->andThrow(new \RuntimeException('测试队列状态不可用'));
        try {
            (new ProcessNodeReportBatch($batch->id))->handle();
            $this->fail('应当回滚');
        } catch (\RuntimeException $error) {
            $this->assertSame('测试队列状态不可用', $error->getMessage());
        }
        $this->assertSame(0, DB::table('v2_stat_user_server')->count());
        $this->assertSame(0, StatUser::count());
        $this->assertSame(0, (int) $user->fresh()->u);
        Redis::shouldReceive('sadd')->once()->andReturn(1);
        (new ProcessNodeReportBatch($batch->id))->handle();
        $this->assertSame(2, DB::table('v2_stat_user_server')->count());
    }

    public function test_zero_rate_preserves_actual_traffic_and_legacy_collection_is_supported(): void
    {
        $user = $this->user(); $node = $this->node();
        (new StatUserJob(['id' => $node->id, 'rate' => 0], [$user->id => [101, 203]], 'vless'))->handle();
        $this->getJson($this->path('users'))->assertOk()->assertJsonPath('data.list.0.total', 304);
        $this->getJson($this->path('users', ['metric' => 'billed']))->assertOk()->assertJsonPath('data.list.0.total', 0);
    }

    public function test_default_range_includes_exactly_thirty_days_and_fills_missing_days(): void
    {
        $this->record(101, 201, 1, 2, 29);
        $this->record(101, 201, 10, 20, 30);
        $this->record(101, 201, 100, 200, 0);
        $response = $this->getJson($this->path('traffic'))->assertOk()->assertJsonCount(30, 'data.list')
            ->assertJsonPath('data.summary.total', 303)->assertJsonPath('data.list.1.total', 0);
        $this->assertSame(now()->subDays(29)->toDateString(), $response->json('data.meta.start_date'));
        $this->getJson($this->path('traffic', ['period' => '7d']))->assertJsonCount(7, 'data.list')->assertJsonPath('data.summary.total', 300);
    }

    public function test_custom_end_is_exclusive_and_week_and_month_preserve_totals(): void
    {
        $node = $this->node();
        $this->record(101, $node->id, 10, 20, 1);
        $this->record(101, $node->id, 100, 200);
        $yesterday = now()->subDay()->toDateString();
        $this->getJson($this->path('traffic', ['period' => 'custom', 'start_date' => $yesterday, 'end_date' => $yesterday]))
            ->assertOk()->assertJsonCount(1, 'data.list')->assertJsonPath('data.summary.total', 30);
        foreach (['week', 'month'] as $unit) {
            $response = $this->getJson($this->path('traffic', ['unit' => $unit]))->assertOk();
            $this->assertSame(330, array_sum(array_column($response->json('data.list'), 'total')));
            $response = $this->getJson($this->path('user', ['user_id' => 101, 'unit' => $unit]))->assertOk();
            $this->assertSame(330, array_sum(array_column($response->json('data.list'), 'total')));
        }
    }

    public function test_unrecorded_history_is_not_rendered_as_zero(): void
    {
        DB::table('v2_settings')->where('name', 'traffic_statistics_started_at')->update(['value' => (string) now()->timestamp]);
        $this->getJson($this->path('traffic'))->assertOk()->assertJsonCount(0, 'data.list');
        $yesterday = now()->subDay()->toDateString();
        $this->getJson($this->path('traffic', ['period' => 'custom', 'start_date' => $yesterday, 'end_date' => $yesterday]))
            ->assertOk()->assertJsonCount(0, 'data.list');
    }

    public function test_rank_pagination_search_and_deleted_names(): void
    {
        $user = $this->user();
        $user->update(['email' => 'literal_%@example.test']);
        $this->record($user->id, 201, 1, 2);
        $this->record(99999, 202, 100, 200);
        $this->getJson($this->path('users', ['page_size' => 1]))->assertOk()
            ->assertJsonPath('data.total', 2)->assertJsonPath('data.list.0.name', '用户 #99999');
        $this->getJson($this->path('users', ['page_size' => 1, 'page' => 2]))->assertJsonPath('data.list.0.id', $user->id);
        $this->getJson($this->path('searchUsers', ['search' => '_%']))->assertOk()->assertJsonCount(1, 'data');
        $this->getJson($this->path('users', ['search' => '_%']))->assertJsonCount(1, 'data.list');
        $this->getJson($this->path('user', ['user_id' => 99999, 'server_id' => 202]))->assertOk()
            ->assertJsonCount(0, 'data.list')->assertJsonCount(0, 'data.nodes')
            ->assertJsonPath('data.actual_summary.total', 300);
        $this->getJson($this->path('user', ['user_id' => 99999, 'server_id' => 201]))->assertJsonCount(0, 'data.list');
    }

    public function test_delayed_batch_day_and_rate_changes_preserve_summary_and_details(): void
    {
        DB::table('v2_settings')->where('name', 'traffic_statistics_started_at')->update(['value' => (string) now()->timestamp]);
        $node = $this->node();
        $this->record(101, $node->id, 10, 20, 1);
        app(TrafficStatisticsRecorder::class)->add(101, $node->id, 'entry', now()->startOfDay()->subDay()->timestamp, 100, 200, 300, 600);
        app(UserRouteTraffic::class)->record(101, $node->id, $node->id, 'entry', 3,
            now()->startOfDay()->subDay()->timestamp, 100, 200, 300, 600, now()->timestamp);
        StatServer::where('server_id', $node->id)->incrementEach(['u' => 100, 'd' => 200]);
        $response = $this->getJson($this->path('traffic'))->assertOk()->assertJsonPath('data.summary.total', 330);
        $this->assertSame(330, array_sum(array_column($response->json('data.list'), 'total')));
        $this->getJson($this->path('user', ['user_id' => 101, 'metric' => 'billed']))
            ->assertOk()->assertJsonPath('data.billed_summary.total', 960)->assertJsonCount(2, 'data.list')
            ->assertJsonPath('data.list.0.rate', 3)->assertJsonPath('data.list.0.total', 300)->assertJsonPath('data.list.0.billed_total', 900)
            ->assertJsonPath('data.list.1.rate', 2)->assertJsonPath('data.list.1.total', 30)->assertJsonPath('data.list.1.billed_total', 60);
        $this->assertSame(1, DB::table('v2_stat_user_server')->count());
    }

    public function test_node_ranking_excludes_monthly_records_and_legacy_rank_excludes_next_midnight(): void
    {
        // 旧控制器构造器连接 Redis，但本次排行查询仅访问数据库。
        $this->mock(\App\Services\StatisticalService::class);
        $node = $this->node();
        foreach ([['d', 0, 300], ['d', 1, 30], ['m', 2, 9000]] as [$kind, $days, $amount]) {
            StatServer::create(['server_id' => $node->id, 'server_type' => 'vless', 'record_type' => $kind,
                'record_at' => now()->startOfDay()->subDays($days)->timestamp, 'u' => $amount, 'd' => 0]);
        }
        $this->getJson($this->path('nodes'))->assertOk()->assertJsonPath('data.list.0.total', 330);
        $path = '/api/v2/' . hash('crc32b', config('app.key')) . '/stat/getTrafficRank?';
        $this->getJson($path . http_build_query(['type' => 'node', 'start_time' => now()->startOfDay()->subDay()->timestamp,
            'end_time' => now()->startOfDay()->timestamp]))->assertOk()->assertJsonPath('data.0.value', 30);
    }

    public function test_legacy_node_and_user_ranks_return_numeric_current_and_previous_values(): void
    {
        $this->mock(\App\Services\StatisticalService::class);
        $node = $this->node();
        $user = $this->user();
        foreach ([[0, 20], [1, 10]] as [$days, $amount]) {
            $data = ['record_type' => 'd', 'record_at' => now()->startOfDay()->subDays($days)->timestamp,
                'u' => $amount, 'd' => 0];
            StatServer::create($data + ['server_id' => $node->id, 'server_type' => 'vless']);
            StatUser::create($data + ['user_id' => $user->id, 'server_rate' => 1]);
        }
        $path = '/api/v2/' . hash('crc32b', config('app.key')) . '/stat/getTrafficRank?';
        foreach (['node', 'user'] as $type) {
            $this->getJson($path . http_build_query(['type' => $type,
                'start_time' => now()->startOfDay()->timestamp, 'end_time' => now()->startOfDay()->addDay()->timestamp]))
                ->assertOk()->assertJsonPath('data.0.value', 20)->assertJsonPath('data.0.previousValue', 10)
                ->assertJsonPath('data.0.change', 100);
        }
    }

    public function test_retention_cleans_all_daily_statistics_but_keeps_boundary_and_deduplication(): void
    {
        $this->record(101, 201, 1, 2, 30);
        $this->record(101, 201, 1, 2, 29);
        foreach ([29, 30] as $days) {
            StatUser::create(['user_id' => 101, 'server_rate' => 1, 'record_type' => 'd',
                'record_at' => now()->startOfDay()->subDays($days)->timestamp, 'u' => 1, 'd' => 2]);
        }
        $batch = $this->batch($this->user(), $this->node(), $this->node());
        $this->artisan('reset:log')->assertSuccessful();
        $this->assertSame(1, DB::table('v2_stat_user_server')->count());
        $this->assertSame(1, StatUser::count()); $this->assertSame(1, StatServer::count());
        $this->assertNotNull($batch->fresh());
    }

    public function test_invalid_filters_and_non_admin_access_are_rejected(): void
    {
        foreach ([['period' => 'custom'], ['unit' => 'hour'], ['page_size' => 101], ['metric' => 'other'],
            ['period' => 'custom', 'start_date' => now()->subDays(30)->toDateString(), 'end_date' => now()->toDateString()],
            ['period' => 'custom', 'start_date' => now()->toDateString(), 'end_date' => now()->subDay()->toDateString()]] as $params) {
            $this->getJson($this->path('traffic', $params))->assertUnprocessable();
        }
        $this->getJson($this->path('user'))->assertUnprocessable();
        Sanctum::actingAs($this->user());
        foreach (['traffic', 'nodes', 'users', 'user', 'searchUsers'] as $endpoint) {
            $this->assertNotSame(200, $this->getJson($this->path($endpoint))->status());
        }
    }

    public function test_hourly_trend_uses_received_time_and_stops_at_current_hour(): void
    {
        $this->travelTo(now()->startOfDay()->setTime(14, 35));
        DB::table('v2_settings')->where('name', 'node_hourly_started_at')->update(['value' => (string) now()->startOfDay()->timestamp]);
        $at = now()->setTime(9, 20)->timestamp;
        app(TrafficStatisticsRecorder::class)->add(101, 201, 'entry', now()->startOfDay()->timestamp, 10, 20, 20, 40, $at);
        app(TrafficStatisticsRecorder::class)->add(101, 202, 'relay', now()->startOfDay()->timestamp, 10, 20, 0, 0, $at);
        StatServer::create(['server_id' => 201, 'server_type' => 'vless', 'record_type' => 'd', 'record_at' => now()->startOfDay()->timestamp, 'u' => 10, 'd' => 20]);
        app(\App\Services\NodeTrafficHour::class)->add(now()->startOfDay()->timestamp, 10, 20, $at);
        $this->getJson($this->path('traffic', ['period' => 'today']))->assertOk()->assertJsonPath('data.meta.unit', 'hour')
            ->assertJsonCount(15, 'data.list')->assertJsonPath('data.list.9.total', 30)->assertJsonPath('data.list.10.total', 0)
            ->assertJsonPath('data.summary.total', 30);
        $this->record(101, 201, 1, 2);
        $this->getJson($this->path('traffic', ['period' => 'today']))->assertOk()->assertJsonPath('data.meta.unit', 'day')
            ->assertJsonCount(1, 'data.list')->assertJsonPath('data.list.0.total', 33);
    }

    public function test_hourly_batch_failure_retry_and_next_day_processing(): void
    {
        $this->travelTo(now()->startOfDay()->setTime(23, 40));
        DB::table('v2_settings')->where('name', 'traffic_hourly_started_at')->update(['value' => (string) now()->startOfDay()->timestamp]);
        $batch = $this->batch($this->user(), $this->node(), $this->node());
        $date = now()->toDateString();
        $this->travelTo(now()->addDay()->setTime(2, 15));
        Redis::shouldReceive('sadd')->once()->andThrow(new \RuntimeException('测试回滚'));
        try { (new ProcessNodeReportBatch($batch->id))->handle(); $this->fail('应当回滚'); }
        catch (\RuntimeException $error) { $this->assertSame('测试回滚', $error->getMessage()); }
        $this->assertSame(0, DB::table('v2_stat_node_hour')->count());
        Redis::shouldReceive('sadd')->once()->andReturn(1);
        (new ProcessNodeReportBatch($batch->id))->handle();
        (new ProcessNodeReportBatch($batch->id))->handle();
        $this->assertSame(1, DB::table('v2_stat_node_hour')->count());
        $this->getJson($this->path('traffic', ['period' => 'custom', 'start_date' => $date, 'end_date' => $date]))
            ->assertOk()->assertJsonPath('data.meta.unit', 'hour')->assertJsonCount(24, 'data.list')
            ->assertJsonPath('data.list.23.total', 570)->assertJsonPath('data.summary.total', 570);
    }

    public function test_legacy_queue_retains_received_day_and_hour_after_serialization(): void
    {
        $this->travelTo(now()->startOfDay()->setTime(23, 50));
        DB::table('v2_settings')->where('name', 'traffic_hourly_started_at')->update(['value' => (string) now()->startOfDay()->timestamp]);
        $user = $this->user(); $node = $this->node(); $date = now()->toDateString();
        $job = unserialize(serialize(new StatUserJob(['id' => $node->id, 'rate' => 2], [$user->id => [10, 20]], 'vless')));
        $nodeJob = unserialize(serialize(new \App\Jobs\StatServerJob(['id' => $node->id], [$user->id => [10, 20]], 'vless')));
        $this->travelTo(now()->addDay()->setTime(1, 20));
        $job->handle();
        $nodeJob->handle();
        $this->getJson($this->path('traffic', ['period' => 'custom', 'start_date' => $date, 'end_date' => $date]))
            ->assertOk()->assertJsonPath('data.meta.unit', 'hour')->assertJsonPath('data.list.23.total', 30);
        $this->assertSame(60, (int) StatUser::first()->u + (int) StatUser::first()->d);
    }

    public function test_hourly_retention_keeps_boundary_and_old_days_remain_daily(): void
    {
        foreach ([29, 30] as $days) {
            DB::table('v2_stat_node_hour')->insert(['record_at' => now()->startOfDay()->subDays($days)->timestamp, 'u' => 1, 'd' => 2]);
        }
        $this->artisan('reset:log')->assertSuccessful();
        $this->assertSame(1, DB::table('v2_stat_node_hour')->count());
        $date = now()->subDay()->toDateString();
        $this->record(101, 201, 10, 20, 1);
        $this->getJson($this->path('traffic', ['period' => 'custom', 'start_date' => $date, 'end_date' => $date]))
            ->assertOk()->assertJsonPath('data.meta.unit', 'day')->assertJsonPath('data.list.0.total', 30);
    }
}
