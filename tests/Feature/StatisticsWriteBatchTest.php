<?php

namespace Tests\Feature;

use App\Jobs\ProcessNodeReportBatch;
use App\Models\StatUser;
use App\Services\FineTrafficStatistics;
use App\Services\Plugin\HookManager;
use App\Services\TrafficStatisticsRecorder;
use App\Services\UserRouteTraffic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\Support\StatisticsWriteFixture as Fixture;
use Tests\TestCase;

class StatisticsWriteBatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'Asia/Shanghai']);
        $this->travelTo(\Carbon\Carbon::parse('2026-10-16 12:00:00', 'Asia/Shanghai'));
    }

    public function test_large_batch_totals_rounding_query_count_and_duplicate_processing(): void
    {
        ['batch' => $batch, 'users' => $users, 'nodes' => $nodes] = Fixture::make(150);
        Redis::shouldReceive('sadd')->once()->andReturn(150);
        DB::enableQueryLog();
        (new ProcessNodeReportBatch($batch->id))->handle();
        $queries = DB::getQueryLog(); DB::disableQueryLog(); DB::flushQueryLog();
        $this->assertLessThanOrEqual(7, count(array_filter($queries, fn ($query) => str_contains($query['query'], 'v2_settings'))));
        $this->assertLessThanOrEqual(5, count(array_filter($queries, fn ($query) => preg_match('/^insert.*v2_stat_user_server/i', $query['query']))));
        $expectedU = 0; $expectedD = 0;
        foreach ($users as $index => $user) {
            $u = (int) ((90 + $index) * 1.125) + (int) (11 * 2.75);
            $d = (int) ((182 + $index) * 1.125) + (int) (21 * 2.75);
            $this->assertSame($u, (int) $user->fresh()->u);
            $this->assertSame($d, (int) $user->fresh()->d);
            $expectedU += $u; $expectedD += $d;
        }
        $totals = Fixture::totals();
        $rawU = array_sum(array_column($batch->traffic, 0));
        $rawD = array_sum(array_column($batch->traffic, 1));
        foreach (['v2_stat_user', 'v2_user'] as $table) {
            $this->assertSame($expectedU, $totals[$table]['u']);
            $this->assertSame($expectedD, $totals[$table]['d']);
        }
        foreach (['v2_stat_user_route_source', 'v2_stat_route_hour', 'v2_stat_route_minute'] as $table) {
            $this->assertSame($rawU, $totals[$table]['u']);
            $this->assertSame($rawD, $totals[$table]['d']);
            $this->assertSame($expectedU, $totals[$table]['billed_u']);
            $this->assertSame($expectedD, $totals[$table]['billed_d']);
        }
        $this->assertSame($rawU + 42 * 150, $totals['v2_stat_server']['u']);
        $this->assertSame(15, $totals['v2_stat_proxy_traffic']['rows']);
        $this->assertSame($rawU, (int) $nodes[0]->fresh()->u);
        (new ProcessNodeReportBatch($batch->id))->handle();
        $this->assertSame($totals, Fixture::totals());
    }

    public function test_failure_after_earlier_chunks_rolls_back_and_retry_reloads_settings(): void
    {
        ['batch' => $batch] = Fixture::make(100);
        $failed = false; $count = 0;
        DB::connection()->beforeExecuting(function ($sql, $bindings, $connection) use (&$failed, &$count): void {
            if (!$failed && preg_match('/^insert.*v2_stat_route_hour/i', $sql) && ++$count === 2) {
                $failed = true;
                throw new \Illuminate\Database\QueryException($connection->getName(), $sql, $bindings, new \RuntimeException('第二块写入失败'));
            }
        });
        try { (new ProcessNodeReportBatch($batch->id))->handle(); $this->fail('应回滚'); }
        catch (\Illuminate\Database\QueryException) {
            $this->assertSame(0, (int) DB::table('v2_user')->sum('u'));
            $this->assertDatabaseCount('v2_stat_user', 0);
            $this->assertDatabaseCount('v2_stat_route_hour', 0);
            $this->assertNotSame('processed', $batch->fresh()->status);
        }
        DB::table('v2_settings')->where('name', 'fine_traffic_started_at')->update(['value' => '0']);
        Redis::shouldReceive('sadd')->once()->andReturn(100);
        (new ProcessNodeReportBatch($batch->id))->handle();
        (new ProcessNodeReportBatch($batch->id))->handle();
        $this->assertDatabaseCount('v2_stat_route_hour', 0);
        $this->assertDatabaseCount('v2_stat_user_route_source', 300);
        $this->assertSame('processed', $batch->fresh()->status);
    }

    public function test_batch_api_preserves_kind_user_node_day_and_hour_dimensions(): void
    {
        Fixture::enable(); $day = now()->startOfDay()->timestamp; $at = now()->timestamp;
        $items = [[1, 10, 'entry', $day, 1, 2, 0, 1, $at], [1, 10, 'entry', $day, 3, 4, 2, 3, $at],
            [1, 10, 'relay', $day, 5, 6, 0, 0, null], [2, 10, 'entry', $day, 7, 8, 1, 2, $at - 3600],
            [1, 11, 'entry', $day - 86400, 9, 10, 3, 4, $at], [1, 12, 'entry', $day, 0, 0, 5, 6, $at]];
        app(TrafficStatisticsRecorder::class)->addMany($items);
        $this->assertDatabaseCount('v2_stat_user_server', 4);
        $this->assertDatabaseHas('v2_stat_user_server', ['user_id' => 1, 'server_id' => 10, 'kind' => 'entry', 'u' => 4, 'billed_u' => 2]);
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('v2_stat_traffic_hour'));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('v2_stat_node_hour'));
        app(UserRouteTraffic::class)->record(1, 10, 10, 'direct', 1, $day - 86400, 10, 20, 10, 20, $at - 86400);
        app(UserRouteTraffic::class)->record(1, 10, 11, 'transit', 1, $day, 3, 4, 3, 4, $at);
        $this->assertDatabaseCount('v2_stat_route_hour', 2);
        $this->assertDatabaseCount('v2_stat_route_minute', 1);
        $this->assertDatabaseHas('v2_stat_route_minute', ['server_id' => 11, 'u' => 3]);
    }

    public function test_relay_hook_observes_all_statistics_and_retry_keeps_original_trigger(): void
    {
        ['batch' => $batch] = Fixture::make(); $calls = 0;
        HookManager::register('traffic.relay_user.processed', function ($payload) use (&$calls, $batch): void {
            $calls++;
            $this->assertSame((array) $batch->relay_user_traffic, $payload['traffic']);
            $this->assertDatabaseCount('v2_stat_user_route_source', 9);
            $this->assertDatabaseCount('v2_stat_user_server', 9);
            if ($calls === 1) throw new \RuntimeException('插件失败');
        });
        try { (new ProcessNodeReportBatch($batch->id))->handle(); $this->fail('应回滚'); }
        catch (\RuntimeException $error) { $this->assertSame('插件失败', $error->getMessage()); }
        $this->assertDatabaseCount('v2_stat_user_server', 0);
        Redis::shouldReceive('sadd')->once()->andReturn(3);
        (new ProcessNodeReportBatch($batch->id))->handle();
        (new ProcessNodeReportBatch($batch->id))->handle();
        $this->assertSame(2, $calls);
    }

    public function test_model_observer_keeps_per_line_callbacks_and_intermediate_visibility(): void
    {
        ['batch' => $batch, 'users' => $users] = Fixture::make(2); $counts = [];
        $listener = function (StatUser $stat) use (&$counts): void {
            $counts[] = [$stat->user_id, DB::table('v2_stat_user_server')->where('kind', 'entry')->count()];
        };
        app('events')->listen('eloquent.saved: ' . StatUser::class, $listener);
        Redis::shouldReceive('sadd')->once()->andReturn(2);
        (new ProcessNodeReportBatch($batch->id))->handle();
        $this->assertSame([[$users[0]->id, 0], [$users[0]->id, 0], [$users[0]->id, 0],
            [$users[1]->id, 1], [$users[1]->id, 1], [$users[1]->id, 1]], $counts);
    }

    public function test_service_extensions_keep_single_item_calls_and_setting_changes(): void
    {
        ['batch' => $batch] = Fixture::make(2);
        $fine = new class extends FineTrafficStatistics {
            public array $seen = [];
            public function record(string $type, array $keys, array $values, int $day, ?int $receivedAt): void
            {
                $this->seen[] = [$type, DB::table(UserRouteTraffic::TABLE)->count()];
                parent::record($type, $keys, $values, $day, $receivedAt);
            }
        };
        $this->app->instance(FineTrafficStatistics::class, $fine);
        app(UserRouteTraffic::class)->recordBatch($batch);
        $this->assertSame([['route', 0], ['route', 1], ['route', 2], ['route', 3], ['route', 4], ['route', 5]], $fine->seen);
        $recorder = new class extends TrafficStatisticsRecorder {
            public int $calls = 0;
            public array $totals = [];
            public function add(int $userId, int $serverId, string $kind, int $recordAt, int $u, int $d, int $billedU = 0, int $billedD = 0, ?int $receivedAt = null): void
            {
                parent::add($userId, $serverId, $kind, $recordAt, $u, $d, $billedU, $billedD, $receivedAt);
                ++$this->calls;
                $this->totals[] = (int) DB::table('v2_stat_user_server')->sum('u');
            }
        };
        $item = [1, 10, 'entry', now()->startOfDay()->timestamp, 3, 4, 1, 2, now()->timestamp];
        $recorder->addMany([$item, $item]);
        $this->assertSame(2, $recorder->calls);
        $this->assertSame([3, 6], $recorder->totals);
    }
}
