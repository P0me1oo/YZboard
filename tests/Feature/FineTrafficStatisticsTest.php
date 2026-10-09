<?php

namespace Tests\Feature;

use App\Jobs\ProcessNodeReportBatch;
use App\Models\NodeReportBatch;
use App\Models\Server;
use App\Models\StatServer;
use App\Models\User;
use App\Services\FineTrafficStatistics;
use App\Services\NodeTrafficHour;
use App\Services\UserRouteTraffic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FineTrafficStatisticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfDay()->setTime(14, 35));
        DB::table('v2_settings')->whereIn('name', ['fine_traffic_started_at', 'user_route_statistics_started_at', 'node_hourly_started_at'])
            ->update(['value' => (string) now()->startOfDay()->subDays(29)->timestamp]);
        Sanctum::actingAs(User::create(['email' => 'fine-admin@example.test', 'password' => Str::random(32),
            'uuid' => (string) Str::uuid(), 'token' => Str::random(32), 'is_admin' => true]));
    }

    private function node(string $name = '测试节点'): Server
    {
        return Server::create(['name' => $name, 'type' => 'vless', 'host' => 'example.test', 'port' => 443,
            'server_port' => 443, 'rate' => 2, 'group_ids' => [], 'enabled' => true]);
    }

    private function getStats(string $endpoint, array $params = [])
    {
        return $this->getJson('/api/v2/' . hash('crc32b', config('app.key')) . '/statistics/' . $endpoint . '?'
            . http_build_query($params + ['period' => 'today', 'precision' => 'auto']));
    }

    private function slice(string $start = '09:10', string $end = '09:12', ?string $date = null): array
    {
        return ['period' => 'custom', 'start_date' => $date ?? now()->toDateString(), 'end_date' => $date ?? now()->toDateString(),
            'start_time' => $start, 'end_time' => $end];
    }

    private function record(int $sid, int $at, int $u, int $d): void
    {
        $day = \Carbon\CarbonImmutable::createFromTimestamp($at, config('app.timezone'))->startOfDay()->timestamp;
        app(NodeTrafficHour::class)->add($day, $u, $d, $at, $sid);
        app(UserRouteTraffic::class)->record(101, $sid, $sid, 'entry', 2, $day, $u, $d, $u * 2, $d * 2, $at);
        $row = StatServer::firstOrCreate(['server_id' => $sid, 'server_type' => 'vless', 'record_type' => 'd', 'record_at' => $day], ['u' => 0, 'd' => 0]);
        StatServer::whereKey($row->id)->incrementEach(['u' => $u, 'd' => $d]);
    }

    public function test_minute_bounds_are_exclusive_and_all_endpoints_agree(): void
    {
        $node = $this->node();
        foreach ([['09:09', 100], ['09:10', 10], ['09:11', 20], ['09:12', 200]] as [$time, $amount]) {
            $this->record($node->id, now()->setTimeFromTimeString($time)->timestamp, $amount, $amount * 2);
        }
        $this->getStats('traffic', $this->slice())->assertOk()->assertJsonPath('data.summary.total', 90)->assertJsonPath('data.meta.unit', 'minute')->assertJsonCount(2, 'data.list');
        $this->getStats('nodes', $this->slice())->assertOk()->assertJsonPath('data.list.0.total', 90);
        $this->getStats('users', $this->slice())->assertOk()->assertJsonPath('data.list.0.total', 90);
        $this->getStats('users', $this->slice() + ['metric' => 'billed'])->assertOk()->assertJsonPath('data.list.0.total', 180);
        $this->getStats('user', $this->slice() + ['user_id' => 101])->assertOk()->assertJsonPath('data.actual_summary.total', 90)->assertJsonPath('data.billed_summary.total', 180);
        $this->getStats('traffic')->assertJsonPath('data.summary.total', 990)->assertJsonPath('data.meta.unit', 'minute');
    }

    public function test_midnight_cleanup_keeps_hours_and_rejects_old_minutes(): void
    {
        $node = $this->node(); $date = now()->toDateString();
        $this->record($node->id, now()->setTime(9, 10)->timestamp, 10, 20);
        $this->travelTo(now()->addDay()->setTime(10, 0));
        $this->artisan('reset:log')->assertSuccessful();
        $this->assertSame(0, DB::table('v2_stat_route_minute')->count());
        $this->assertSame(0, DB::table('v2_stat_node_minute_detail')->count());
        $this->assertSame(1, DB::table('v2_stat_route_hour')->count());
        $this->getStats('nodes', $this->slice('09:00', '10:00', $date))->assertOk()->assertJsonPath('data.list.0.total', 30);
        $this->getStats('user', $this->slice('09:00', '10:00', $date) + ['user_id' => 101])->assertOk()->assertJsonPath('data.actual_summary.total', 30);
        $this->getStats('traffic', $this->slice('09:10', '10:00', $date))->assertUnprocessable();
        $this->getStats('traffic', ['period' => '24h'])->assertOk()->assertJsonPath('data.summary.total', 0);
    }

    public function test_today_trend_starts_at_midnight_and_custom_range_keeps_its_start(): void
    {
        $node = $this->node();
        $this->record($node->id, now()->setTime(9, 10)->timestamp, 10, 20);
        $response = $this->getStats('traffic')->assertOk()->assertJsonPath('data.meta.unit', 'minute')
            ->assertJsonPath('data.list.0.date', now()->startOfDay()->toIso8601String())
            ->assertJsonPath('data.list.0.total', 0)->assertJsonPath('data.summary.total', 30);
        $this->assertSame(30, array_sum(array_column($response->json('data.list'), 'total')));
        $this->assertSame(now()->startOfMinute()->toIso8601String(), collect($response->json('data.list'))->last()['date']);
        $this->getStats('traffic', $this->slice('09:00', '09:12'))->assertOk()
            ->assertJsonPath('data.list.0.date', now()->setTime(9, 0)->toIso8601String())
            ->assertJsonPath('data.list.0.total', 0)->assertJsonCount(12, 'data.list')->assertJsonPath('data.summary.total', 30);
        $this->travelTo(now()->addDay());
        $date = now()->subDay()->toDateString();
        $this->getStats('traffic', ['period' => 'custom', 'start_date' => $date, 'end_date' => $date])->assertOk()
            ->assertJsonPath('data.meta.unit', 'hour')->assertJsonCount(24, 'data.list')
            ->assertJsonPath('data.list.0.date', now()->subDay()->startOfDay()->toIso8601String())
            ->assertJsonPath('data.list.0.total', 0)->assertJsonPath('data.summary.total', 30);
    }

    public function test_trend_leaves_time_before_statistics_enabled_unknown(): void
    {
        $node = $this->node();
        DB::table('v2_settings')->where('name', 'fine_traffic_started_at')->update(['value' => (string) now()->setTime(9, 0, 30)->timestamp]);
        $this->record($node->id, now()->setTime(9, 10)->timestamp, 10, 20);
        $this->getStats('traffic')->assertOk()->assertJsonPath('data.list.0.date', now()->startOfDay()->toIso8601String())
            ->assertJsonPath('data.list.0.total', null)->assertJsonPath('data.list.540.total', null)
            ->assertJsonPath('data.list.541.total', 0)->assertJsonPath('data.list.550.total', 30)
            ->assertJsonPath('data.summary.total', 30);
    }

    public function test_user_minimum_filters_aggregated_metric_before_pagination(): void
    {
        $a = $this->node(); $b = $this->node(); $mb = 1048576;
        foreach ([[101, $a, $mb / 4, $mb / 4, 1], [101, $b, $mb / 4, $mb / 4, 1],
            [102, $a, 0, $mb - 1, 2], [103, $a, $mb, $mb, 2]] as [$id, $node, $u, $d, $rate]) {
            $at = now()->setTime(9, $node->id === $a->id ? 10 : 11);
            app(UserRouteTraffic::class)->record($id, $node->id, $node->id, 'entry', $rate,
                $at->copy()->startOfDay()->timestamp, (int) $u, (int) $d, (int) ($u * $rate), (int) ($d * $rate), $at->timestamp);
        }
        $path = '/api/v2/' . hash('crc32b', config('app.key')) . '/statistics/users?';
        foreach ([[], ['precision' => 'auto']] as $precision) {
            $params = $precision + ['period' => 'today', 'min_traffic_mb' => 1, 'page_size' => 1];
            $this->getJson($path . http_build_query($params))->assertOk()->assertJsonPath('data.total', 2)
                ->assertJsonPath('data.last_page', 2)->assertJsonPath('data.list.0.id', 103);
            $this->getJson($path . http_build_query($params + ['page' => 2]))->assertOk()
                ->assertJsonPath('data.list.0.id', 101)->assertJsonPath('data.list.0.total', $mb);
        }
        $this->getStats('users', ['min_traffic_mb' => 1, 'direction' => 'asc'])->assertJsonPath('data.list.0.id', 101);
        $this->getStats('users', ['min_traffic_mb' => 1, 'metric' => 'billed'])->assertJsonPath('data.total', 3);
        $this->getStats('users', ['min_traffic_mb' => 1.000001])->assertJsonPath('data.total', 1)->assertJsonPath('data.list.0.id', 103);
        $this->getStats('users', ['min_traffic_mb' => 0])->assertJsonPath('data.total', 3);
        $this->getStats('users')->assertJsonPath('data.total', 3);
        $this->getStats('users', ['min_traffic_mb' => 1, 'search' => '102'])->assertJsonPath('data.total', 0);
        $this->getStats('users', $this->slice('09:10', '09:11') + ['min_traffic_mb' => 1])
            ->assertJsonPath('data.total', 1)->assertJsonPath('data.list.0.id', 103);
        $this->getStats('users', ['min_traffic_mb' => 3])->assertOk()->assertJsonCount(0, 'data.list')->assertJsonPath('data.last_page', 1);
        foreach ([-1, 'invalid', 'NaN', 'INF', '', 8589934592] as $minimum) {
            $this->getStats('users', ['min_traffic_mb' => $minimum])->assertUnprocessable();
        }
    }

    public function test_user_detail_minimum_filters_complete_rows_before_pagination_and_preserves_totals(): void
    {
        $a = $this->node('明细 A'); $b = $this->node('明细 B'); $c = $this->node('明细 C'); $mb = 1048576;
        foreach ([[$a, 1, $mb / 4, $mb / 4, 10], [$a, 1, $mb / 4, $mb / 4, 11],
            [$b, 2, 0, $mb - 1, 10], [$c, 1, 0, $mb + 1, 10]] as [$node, $rate, $u, $d, $minute]) {
            $at = now()->setTime(9, $minute);
            app(UserRouteTraffic::class)->record(101, $node->id, $node->id, 'entry', $rate,
                $at->copy()->startOfDay()->timestamp, (int) $u, (int) $d, (int) ($u * $rate), (int) ($d * $rate), $at->timestamp);
        }
        $path = '/api/v2/' . hash('crc32b', config('app.key')) . '/statistics/user?';
        foreach ([[], ['precision' => 'auto']] as $precision) {
            $params = $precision + ['period' => 'today', 'user_id' => 101, 'min_traffic_mb' => 1, 'page_size' => 1];
            for ($repeat = 0; $repeat < 2; $repeat++) {
                $this->getJson($path . http_build_query($params))->assertOk()
                    ->assertJsonPath('data.total', 2)->assertJsonPath('data.last_page', 2)
                    ->assertJsonPath('data.list.0.server_id', $c->id)
                    ->assertJsonPath('data.actual_summary.total', 3 * $mb)
                    ->assertJsonPath('data.billed_summary.total', 4 * $mb - 1)->assertJsonCount(3, 'data.nodes');
            }
            $this->getJson($path . http_build_query($params + ['page' => 2]))->assertOk()
                ->assertJsonPath('data.list.0.server_id', $a->id)->assertJsonPath('data.list.0.total', $mb);
        }
        $params = ['user_id' => 101];
        $this->getStats('user', $params + ['min_traffic_mb' => 1, 'metric' => 'billed'])
            ->assertOk()->assertJsonPath('data.total', 3)->assertJsonPath('data.actual_summary.total', 3 * $mb);
        $this->getStats('user', $params + ['min_traffic_mb' => 1.0000005])
            ->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.list.0.server_id', $c->id);
        $this->getStats('user', $params + ['min_traffic_mb' => 1.000001])
            ->assertOk()->assertJsonCount(0, 'data.list')->assertJsonPath('data.last_page', 1)
            ->assertJsonPath('data.actual_summary.total', 3 * $mb);
        foreach ([[], ['min_traffic_mb' => 0], ['min_traffic_mb' => 0.5]] as $minimum) {
            $this->getStats('user', $params + $minimum)->assertOk()->assertJsonPath('data.total', 3);
        }
        $this->getStats('user', $params + ['server_id' => $b->id, 'min_traffic_mb' => 1])
            ->assertOk()->assertJsonCount(0, 'data.list')->assertJsonPath('data.actual_summary.total', $mb - 1);
        $this->getStats('user', $params + $this->slice('09:10', '09:11') + ['min_traffic_mb' => 1])
            ->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.list.0.server_id', $c->id);
        foreach ([-1, 'invalid', '', 8589934592] as $minimum) {
            $this->getStats('user', $params + ['min_traffic_mb' => $minimum])->assertUnprocessable();
        }
    }

    public function test_search_and_sort_apply_before_pagination_and_escape_wildcards(): void
    {
        $a = $this->node('节点_%'); $b = $this->node('节点 B'); $c = $this->node('节点 C');
        foreach ([[$a, 10, 90], [$b, 30, 10], [$c, 20, 10]] as [$node, $u, $d]) { $this->record($node->id, now()->timestamp, $u, $d); }
        foreach (['upload' => [$a->id, $b->id], 'download' => [$b->id, $a->id], 'total' => [$c->id, $a->id]] as $sort => [$asc, $desc]) {
            $this->getStats('nodes', ['sort' => $sort, 'direction' => 'asc', 'page_size' => 1])->assertOk()->assertJsonPath('data.list.0.id', $asc)->assertJsonPath('data.total', 3);
            $this->getStats('nodes', ['sort' => $sort, 'direction' => 'desc', 'page_size' => 1])->assertOk()->assertJsonPath('data.list.0.id', $desc);
        }
        $this->getStats('nodes', ['search' => '_%'])->assertJsonPath('data.total', 1)->assertJsonPath('data.list.0.id', $a->id);
        $this->getStats('nodes', ['search' => '不存在'])->assertJsonCount(0, 'data.list');
        $this->getStats('nodes', ['sort' => 'id'])->assertUnprocessable();
        $this->getStats('nodes', ['direction' => 'invalid'])->assertUnprocessable();
    }

    public function test_node_sort_uses_management_order_before_pagination_and_tracks_changes(): void
    {
        $a = $this->node('排序 A'); $b = $this->node('排序 B'); $c = $this->node('排序 C');
        $d = $this->node('排序 D'); $e = $this->node('排序 E');
        foreach ([[$a, 20], [$b, null], [$c, 0], [$d, 20], [$e, 10]] as [$node, $sort]) {
            $node->update(['sort' => $sort]);
            $this->record($node->id, now()->timestamp, $node->id * 10, 10);
        }
        $path = '/api/v2/' . hash('crc32b', config('app.key')) . '/statistics/nodes?';
        foreach ([[], ['precision' => 'auto']] as $precision) {
            foreach (['asc' => [$b->id, $c->id, $e->id, $a->id, $d->id],
                'desc' => [$d->id, $a->id, $e->id, $c->id, $b->id]] as $direction => $ids) {
                $response = $this->getJson($path . http_build_query($precision + ['period' => 'today', 'sort' => 'node', 'direction' => $direction]))->assertOk();
                $this->assertSame($ids, array_column($response->json('data.list'), 'id'));
                $this->getJson($path . http_build_query($precision + ['period' => 'today', 'sort' => 'node', 'direction' => $direction, 'page_size' => 2, 'page' => 2]))
                    ->assertOk()->assertJsonPath('data.total', 5)->assertJsonPath('data.list.0.id', $ids[2]);
            }
        }
        $e->update(['sort' => 30]);
        $this->getStats('nodes', ['sort' => 'node', 'direction' => 'asc', 'page_size' => 2, 'page' => 3])
            ->assertOk()->assertJsonPath('data.list.0.id', $e->id)->assertJsonPath('data.total', 5);
        \App\Services\ServerNameHistory::remember(collect([$e]));
        $e->delete();
        foreach (['asc', 'desc'] as $direction) {
            $this->getStats('nodes', ['sort' => 'node', 'direction' => $direction, 'page_size' => 1, 'page' => 5])
                ->assertOk()->assertJsonPath('data.list.0.id', $e->id)->assertJsonPath('data.list.0.name', '排序 E（已删除）');
        }
        $this->getStats('nodes')->assertOk()->assertJsonPath('data.list.0.id', $e->id);
    }

    public function test_user_ranking_sorts_actual_and_billed_traffic_before_pagination(): void
    {
        $node = $this->node();
        foreach ([[101, 10, 90, 1], [102, 30, 10, 4], [103, 20, 5, 2]] as [$id, $u, $d, $rate]) {
            app(UserRouteTraffic::class)->record($id, $node->id, $node->id, 'entry', $rate,
                now()->startOfDay()->timestamp, $u, $d, $u * $rate, $d * $rate, now()->timestamp);
        }
        foreach (['actual' => ['upload' => [101, 102], 'download' => [103, 101], 'total' => [103, 101]],
            'billed' => ['upload' => [101, 102], 'download' => [103, 101], 'total' => [103, 102]]] as $metric => $orders) {
            foreach ($orders as $sort => [$asc, $desc]) {
                foreach (['asc' => $asc, 'desc' => $desc] as $direction => $id) {
                    $this->getStats('users', ['metric' => $metric, 'sort' => $sort, 'direction' => $direction, 'page_size' => 1])
                        ->assertOk()->assertJsonPath('data.total', 3)->assertJsonPath('data.list.0.id', $id);
                }
            }
        }
        $this->getStats('users', ['page_size' => 1])->assertJsonPath('data.list.0.id', 101);
        $this->getStats('users', ['page_size' => 1, 'page' => 2])->assertJsonPath('data.list.0.id', 102);
        $this->getStats('users', ['sort' => 'upload', 'direction' => 'desc', 'page_size' => 1, 'page' => 2])->assertJsonPath('data.list.0.id', 103);
        $this->getStats('users', ['sort' => 'invalid'])->assertUnprocessable();
        $this->getStats('users', ['direction' => 'invalid'])->assertUnprocessable();
    }

    public function test_user_detail_sort_preserves_totals_filters_and_stable_pages(): void
    {
        $a = $this->node('排序 A'); $b = $this->node('排序 B');
        $a->update(['sort' => 20]); $b->update(['sort' => 10]);
        $this->record($a->id, now()->timestamp, 30, 10);
        $this->record($b->id, now()->timestamp, 10, 90);
        $this->record($a->id, now()->subDay()->timestamp, 20, 5);
        $params = ['user_id' => 101, 'period' => '7d'];
        foreach (['upload' => [[100, 25, 40], [40, 25, 100]], 'download' => [[25, 40, 100], [100, 40, 25]],
            'total' => [[25, 40, 100], [100, 40, 25]], 'node' => [[100, 40, 25], [40, 25, 100]]] as $sort => [$asc, $desc]) {
            foreach (['asc' => $asc, 'desc' => $desc] as $direction => $totals) {
                $response = $this->getStats('user', $params + ['sort' => $sort, 'direction' => $direction])->assertOk()
                    ->assertJsonPath('data.actual_summary.total', 165)->assertJsonPath('data.billed_summary.total', 330);
                $this->assertSame($totals, array_column($response->json('data.list'), 'total'));
                $this->getStats('user', $params + ['sort' => $sort, 'direction' => $direction, 'page_size' => 1, 'page' => 2])
                    ->assertOk()->assertJsonPath('data.list.0.total', $totals[1])->assertJsonPath('data.total', 3);
            }
        }
        $this->getStats('user', $params)->assertJsonPath('data.list.0.total', 100);
        $this->getStats('user', $params + ['server_id' => $a->id, 'sort' => 'total', 'direction' => 'asc'])->assertOk()
            ->assertJsonPath('data.total', 2)->assertJsonPath('data.list.0.total', 25)
            ->assertJsonPath('data.actual_summary.total', 65)->assertJsonPath('data.billed_summary.total', 130);
        $b->update(['sort' => 30]);
        $this->getStats('user', $params + ['sort' => 'node', 'direction' => 'asc'])->assertJsonPath('data.list.0.server_id', $a->id);
        \App\Services\ServerNameHistory::remember(collect([$b]));
        $b->delete();
        $this->getStats('user', $params + ['sort' => 'node', 'direction' => 'desc'])->assertOk()
            ->assertJsonPath('data.list.2.server_name', '排序 B（已删除）')->assertJsonPath('data.actual_summary.total', 165);
        $this->getStats('user', $params + ['sort' => 'invalid'])->assertUnprocessable();
    }

    public function test_old_daily_history_is_preserved_without_fabricating_fine_data(): void
    {
        $node = $this->node();
        DB::table('v2_settings')->where('name', 'fine_traffic_started_at')->update(['value' => (string) now()->timestamp]);
        $this->record($node->id, now()->subDay()->timestamp, 10, 20);
        $date = now()->subDay()->toDateString();
        $this->getStats('traffic', ['period' => 'custom', 'start_date' => $date, 'end_date' => $date])->assertOk()->assertJsonPath('data.summary.total', 30)->assertJsonPath('data.meta.unit', 'day');
        $this->getStats('nodes', $this->slice('09:00', '10:00', $date))->assertUnprocessable();
        $this->getStats('users', $this->slice('09:00', '10:00', $date))->assertUnprocessable();
        $this->assertSame(0, DB::table('v2_stat_route_hour')->count());
    }

    public function test_minute_samples_are_aggregated_before_direct_difference_and_billing_allocation(): void
    {
        $entry = $this->node(); $relay = $this->node(); $day = now()->startOfDay()->timestamp;
        foreach ([['09:10', 10, 15], ['09:11', 20, 15]] as [$time, $u, $r]) {
            $at = now()->setTimeFromTimeString($time)->timestamp;
            app(UserRouteTraffic::class)->record(101, $entry->id, $entry->id, 'entry', 0.5, $day, $u, 0, (int) ($u * .5), 0, $at);
            app(UserRouteTraffic::class)->record(101, $entry->id, $relay->id, 'relay', 0.5, $day, $r, 0, 0, 0, $at);
        }
        $this->getStats('user', $this->slice() + ['user_id' => 101])->assertOk()->assertJsonPath('data.actual_summary.total', 30)
            ->assertJsonPath('data.billed_summary.total', 15)->assertJsonPath('data.direct_summary.total', 0)->assertJsonCount(1, 'data.list');
    }

    public function test_batch_rollback_retry_and_delayed_processing_preserve_hour_records(): void
    {
        $entry = $this->node(); $relay = $this->node();
        $batch = NodeReportBatch::create(['server_id' => $entry->id, 'server_type' => 'vless', 'report_id' => Str::random(20), 'report_key' => Str::random(64),
            'server_snapshot' => ['id' => $entry->id, 'rate' => 2], 'traffic' => [101 => [10, 20]],
            'relay_traffic' => [['server_id' => $relay->id, 'server_type' => 'vless', 'u' => 8, 'd' => 16]],
            'relay_user_traffic' => [101 => [$relay->id => [8, 16]]], 'record_at' => now()->startOfDay()->timestamp, 'status' => 'pending', 'attempts' => 0]);
        Redis::shouldReceive('sadd')->once()->andThrow(new \RuntimeException('测试回滚'));
        try { (new ProcessNodeReportBatch($batch->id))->handle(); $this->fail('应回滚'); } catch (\RuntimeException $e) { $this->assertSame('测试回滚', $e->getMessage()); }
        foreach (['route_minute', 'route_hour', 'node_minute_detail', 'node_hour_detail'] as $table) { $this->assertSame(0, DB::table('v2_stat_' . $table)->count()); }
        $this->travelTo(now()->addDay());
        Redis::shouldReceive('sadd')->once()->andReturn(1);
        (new ProcessNodeReportBatch($batch->id))->handle(); (new ProcessNodeReportBatch($batch->id))->handle();
        $this->assertSame(0, DB::table('v2_stat_route_minute')->count());
        $this->assertSame(0, DB::table('v2_stat_node_minute_detail')->count());
        $this->assertSame(2, DB::table('v2_stat_route_hour')->count());
        $this->assertSame(54, (int) DB::table('v2_stat_node_hour_detail')->selectRaw('SUM(u + d) AS total')->value('total'));
    }

    public function test_retention_keeps_today_minutes_and_thirty_day_hour_boundary(): void
    {
        $node = $this->node();
        foreach ([0, 29, 30] as $days) { $this->record($node->id, now()->subDays($days)->timestamp, 1, 2); }
        $this->artisan('reset:log')->assertSuccessful();
        $this->assertSame(2, DB::table('v2_stat_route_hour')->count());
        $this->assertSame(1, DB::table('v2_stat_route_minute')->count());
        $this->getStats('traffic', ['period' => 'all'])->assertOk()->assertJsonPath('data.summary.total', 6);
        $this->getStats('metadata')->assertOk()->assertJsonStructure(['data' => ['presets' => ['today', '24h', '7d', '14d', '30d', 'all']]]);
    }

    public function test_last_twenty_four_hours_include_both_days_without_double_counting(): void
    {
        $node = $this->node();
        $this->record($node->id, now()->subDay()->setTime(14, 59)->timestamp, 100, 200);
        $this->record($node->id, now()->subDay()->setTime(15, 0)->timestamp, 1, 2);
        $this->record($node->id, now()->setTime(14, 20)->timestamp, 10, 20);
        $this->getStats('traffic', ['period' => '24h'])->assertOk()->assertJsonPath('data.summary.total', 33)
            ->assertJsonPath('data.meta.unit', 'hour')->assertJsonCount(24, 'data.list');
        $this->getStats('nodes', ['period' => '24h'])->assertJsonPath('data.list.0.total', 33);
        $this->getStats('users', ['period' => '24h'])->assertJsonPath('data.list.0.total', 33);
        $this->getStats('user', ['period' => '24h', 'user_id' => 101])->assertJsonPath('data.actual_summary.total', 33);
    }

    public function test_invalid_and_nonexistent_local_time_and_metadata_access(): void
    {
        foreach ([$this->slice('09:12', '09:10'), $this->slice('25:00', '26:00'), ['start_time' => '09:00'], $this->slice() + ['precision' => 'invalid']] as $params) {
            $this->getStats('traffic', $params)->assertUnprocessable();
        }
        config(['app.timezone' => 'America/New_York']);
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-03-08 12:00:00', 'America/New_York'));
        $this->getStats('traffic', $this->slice('02:10', '04:00', '2026-03-08'))->assertUnprocessable();
        $admin = auth()->user(); $admin->is_admin = false; $admin->save(); Sanctum::actingAs($admin);
        $this->assertNotSame(200, $this->getStats('metadata')->status());
    }
}
