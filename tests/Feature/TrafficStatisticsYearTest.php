<?php

namespace Tests\Feature;

use App\Models\NodeReportBatch;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\User;
use App\Services\ProxyTrafficRecorder;
use App\Services\TrafficStatisticsRetention;
use App\Services\UserRouteTraffic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TrafficStatisticsYearTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'Asia/Shanghai']);
        $this->travelTo(\Carbon\Carbon::parse('2026-10-16 12:00:00', 'Asia/Shanghai'));
        DB::table('v2_settings')->whereIn('name', ['proxy_traffic_started_at', 'traffic_statistics_started_at', 'user_route_statistics_started_at', 'fine_traffic_started_at'])
            ->update(['value' => (string) now()->subDays(400)->timestamp]);
        Sanctum::actingAs(User::create(['email' => 'year-statistics@example.test', 'password' => Str::random(32),
            'uuid' => (string) Str::uuid(), 'token' => Str::random(32), 'is_admin' => true]));
    }

    private function path(string $endpoint = 'traffic', array $params = []): string
    {
        return '/api/v2/' . hash('crc32b', config('app.key')) . '/statistics/' . $endpoint . '?'
            . http_build_query($params + ['period' => '365d', 'precision' => 'auto']);
    }

    private function record(int $days, string $unit = 'day', string $scope = 'node', int $amount = 30): void
    {
        DB::table(ProxyTrafficRecorder::TABLE)->insert([
            'scope' => $scope, 'unit' => $unit, 'entity_id' => 101, 'entity_name' => '历史对象',
            'record_at' => now()->startOfDay()->subDays($days)->timestamp, 'u' => 10, 'd' => $amount - 10,
        ]);
    }

    public function test_year_window_metadata_heatmap_and_complete_day_restriction(): void
    {
        foreach (['node', 'machine'] as $scope) {
            $this->record(364, 'day', $scope); $this->record(30, 'day', $scope); $this->record(365, 'day', $scope, 3000);
            $response = $this->getJson($this->path('traffic', ['scope' => $scope]))->assertOk()
                ->assertJsonCount(365, 'data.list')->assertJsonPath('data.summary.total', 60)
                ->assertJsonPath('data.meta.visualization', 'heatmap')->assertJsonPath('data.meta.unit', 'day');
            $this->assertSame(60, array_sum(array_column($response->json('data.list'), 'total')));
            $this->getJson($this->path('nodes', ['scope' => $scope]))->assertOk()->assertJsonPath('data.list.0.total', 60);
        }
        $this->getJson($this->path('metadata'))->assertOk()->assertJsonPath('data.retention_days', 365)
            ->assertJsonPath('data.min_date', now()->subDays(364)->toDateString())
            ->assertJsonPath('data.fine_min_date', now()->subDays(29)->toDateString());
        $date = now()->subDays(30)->toDateString();
        $this->getJson($this->path('traffic', ['period' => 'custom', 'start_date' => $date, 'end_date' => $date]))
            ->assertOk()->assertJsonPath('data.meta.visualization', 'heatmap')->assertJsonPath('data.summary.total', 30);
        $this->getJson($this->path('traffic', ['period' => 'custom', 'start_date' => $date, 'end_date' => $date, 'start_time' => '01:00', 'end_time' => '02:00']))->assertUnprocessable();
        $this->getJson($this->path('traffic', ['scope' => 'legacy']))->assertUnprocessable();
        $this->getJson($this->path('traffic', ['period' => 'custom', 'start_date' => now()->subDays(365)->toDateString(), 'end_date' => $date]))->assertUnprocessable();
    }

    public function test_cleanup_retains_daily_year_hourly_month_and_today_minutes_idempotently(): void
    {
        foreach ([0, 29, 30, 364, 365] as $days) {
            foreach (['day', 'hour', 'minute'] as $unit) $this->record($days, $unit);
            app(UserRouteTraffic::class)->record(101, 101, 101, 'direct', 2, now()->startOfDay()->subDays($days)->timestamp, 10, 20, 20, 40, now()->subDays($days)->timestamp);
        }
        foreach ([1, 2] as $_) $this->artisan('reset:log')->assertSuccessful();
        $this->assertSame(4, DB::table(ProxyTrafficRecorder::TABLE)->where('unit', 'day')->count());
        $this->assertSame(2, DB::table(ProxyTrafficRecorder::TABLE)->where('unit', 'hour')->count());
        $this->assertSame(1, DB::table(ProxyTrafficRecorder::TABLE)->where('unit', 'minute')->count());
        $this->assertDatabaseCount(UserRouteTraffic::TABLE, 4);
        $this->assertDatabaseCount('v2_stat_route_hour', 2);
        $this->assertDatabaseCount('v2_stat_route_minute', 1);
        $this->getJson($this->path())->assertJsonPath('data.summary.total', 120);
    }

    public function test_late_batch_records_old_daily_total_without_recreating_expired_detail(): void
    {
        $machine = ServerMachine::create(['name' => '测试服务器', 'is_active' => true, 'token' => Str::random(32)]);
        $node = Server::create(['name' => '测试节点', 'type' => 'vless', 'host' => 'example.test', 'port' => 443,
            'server_port' => 443, 'rate' => 2, 'group_ids' => [], 'enabled' => true, 'machine_id' => $machine->id]);
        $at = now()->subDays(40);
        $recorder = app(ProxyTrafficRecorder::class);
        $batch = new NodeReportBatch(['server_snapshot' => ['proxy_traffic' => $recorder->snapshot($node, [], [])],
            'traffic' => [101 => [10, 20]], 'record_at' => $at->copy()->startOfDay()->timestamp, 'created_at' => $at]);
        $recorder->recordBatch($batch);
        $this->assertDatabaseCount(ProxyTrafficRecorder::TABLE, 2);
        $this->assertSame(['day'], DB::table(ProxyTrafficRecorder::TABLE)->distinct()->pluck('unit')->all());
        $this->assertSame(30, $node->fresh()->usage_u + $node->fresh()->usage_d);
        $this->artisan('reset:log')->assertSuccessful();
        $this->assertSame(30, $node->fresh()->usage_u + $node->fresh()->usage_d);
        $this->assertSame(30, $machine->fresh()->proxy_u + $machine->fresh()->proxy_d);
    }

    public function test_user_year_details_and_ranking_use_daily_data_before_paging_and_filtering(): void
    {
        $node = Server::create(['name' => '测试节点', 'type' => 'vless', 'host' => 'example.test', 'port' => 443,
            'server_port' => 443, 'rate' => 2, 'group_ids' => [], 'enabled' => true]);
        foreach ([40, 364] as $days) {
            $at = now()->subDays($days);
            app(UserRouteTraffic::class)->record(101, $node->id, $node->id, 'direct', 2, $at->copy()->startOfDay()->timestamp, 10, 20, 20, 40, $at->timestamp);
        }
        $this->getJson($this->path('users', ['page_size' => 1]))->assertOk()->assertJsonPath('data.list.0.total', 60)
            ->assertJsonCount(2, 'data.daily')->assertJsonPath('data.daily.0.total', 30);
        $this->getJson($this->path('users', ['min_traffic_mb' => 1]))->assertOk()->assertJsonCount(0, 'data.daily');
        $this->getJson($this->path('user', ['user_id' => 101, 'page_size' => 1, 'metric' => 'billed']))->assertOk()
            ->assertJsonPath('data.actual_summary.total', 60)->assertJsonPath('data.billed_summary.total', 120)
            ->assertJsonCount(2, 'data.daily')->assertJsonPath('data.daily.0.total', 60)->assertJsonCount(1, 'data.list');
    }

    public function test_user_heatmap_aggregates_all_matching_users_before_ranking_pagination(): void
    {
        foreach ([[101, 40, 10, 20], [102, 41, 30, 60]] as [$uid, $days, $u, $d]) {
            $at = now()->subDays($days);
            app(UserRouteTraffic::class)->record($uid, 101, 101, 'direct', 2, $at->copy()->startOfDay()->timestamp, $u, $d, $u * 2, $d * 2, $at->timestamp);
        }
        foreach ([1, 2] as $page) {
            $response = $this->getJson($this->path('users', ['page_size' => 1, 'page' => $page]))->assertOk()->assertJsonCount(2, 'data.daily');
            $this->assertSame(120, array_sum(array_column($response->json('data.daily'), 'total')));
        }
        $this->getJson($this->path('users', ['min_traffic_mb' => 0.00005]))->assertOk()
            ->assertJsonCount(1, 'data.daily')->assertJsonPath('data.daily.0.total', 90);
    }

    public function test_upgrade_removes_only_dedicated_legacy_tables_and_rollback_creates_empty_tables(): void
    {
        $migration = require database_path('migrations/2026_10_16_000001_retire_legacy_traffic_statistics.php');
        $migration->down();
        DB::table('v2_stat_node_hour')->insert(['record_at' => now()->timestamp, 'u' => 10, 'd' => 20]);
        $this->record(40);
        $migration->up();
        foreach (['v2_stat_node_hour', 'v2_stat_node_hour_detail', 'v2_stat_node_minute_detail', 'v2_stat_traffic_hour'] as $table) $this->assertFalse(Schema::hasTable($table));
        foreach (['v2_stat_user', 'v2_stat_server', 'v2_stat_user_server', UserRouteTraffic::TABLE] as $table) $this->assertTrue(Schema::hasTable($table));
        $this->assertDatabaseCount(ProxyTrafficRecorder::TABLE, 1);
        $migration->down(); $this->assertDatabaseCount('v2_stat_node_hour', 0); $migration->up();
    }
}
