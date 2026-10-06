<?php

namespace Tests\Feature;

use App\Jobs\ProcessNodeReportBatch;
use App\Models\NodeReportBatch;
use App\Models\Server;
use App\Models\StatServer;
use App\Models\User;
use App\Services\UserRouteTraffic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserRouteTrafficTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
        DB::table('v2_settings')->whereIn('name', ['user_route_statistics_started_at', 'node_hourly_started_at'])
            ->update(['value' => (string) now()->startOfDay()->timestamp]);
        Sanctum::actingAs(User::create(['email' => 'route-admin@example.test', 'password' => Str::random(32),
            'uuid' => (string) Str::uuid(), 'token' => Str::random(32), 'is_admin' => true]));
    }

    private function node(string $name): Server
    {
        return Server::create(['name' => $name, 'type' => 'vless', 'host' => 'example.test', 'port' => 443,
            'server_port' => 443, 'rate' => 2, 'group_ids' => [], 'enabled' => true]);
    }

    private function getStats(string $endpoint = 'user', array $parameters = [])
    {
        return $this->getJson('/api/v2/' . hash('crc32b', config('app.key')) . '/statistics/' . $endpoint . '?'
            . http_build_query($parameters + ['user_id' => 101, 'period' => 'today']));
    }

    private function record(int $entry, int $sid, string $kind, float $rate, int $u, int $d, ?int $day = null, ?int $received = null): void
    {
        app(UserRouteTraffic::class)->record(101, $entry, $sid, $kind, $rate, $day ?? now()->startOfDay()->timestamp,
            $u, $d, $kind === 'entry' ? (int) ($u * $rate) : 0, $kind === 'entry' ? (int) ($d * $rate) : 0,
            $received ?? now()->timestamp);
    }

    public function test_transit_only_has_no_duplicate_entry_and_inherits_recorded_rate(): void
    {
        $entry = $this->node('前置'); $relay = $this->node('落地');
        $this->record($entry->id, $entry->id, 'entry', 2, 100, 400);
        $this->record($entry->id, $relay->id, 'relay', 2, 100, 400);
        $entry->update(['rate' => 7]); $relay->update(['rate' => 9]);
        $this->getStats()->assertOk()->assertJsonCount(1, 'data.list')->assertJsonCount(1, 'data.nodes')
            ->assertJsonPath('data.list.0.server_id', $relay->id)->assertJsonPath('data.list.0.rate', 2)
            ->assertJsonPath('data.list.0.billed_total', 1000)->assertJsonPath('data.actual_summary.total', 500)
            ->assertJsonPath('data.billed_summary.total', 1000)->assertJsonPath('data.direct_summary.total', 0);
        $this->getStats('users')->assertJsonPath('data.list.0.total', 500);
        $this->getStats('users', ['metric' => 'billed'])->assertJsonPath('data.list.0.total', 1000);
        $this->getStats('user', ['server_id' => $entry->id])->assertJsonCount(0, 'data.list');
    }

    public function test_direct_and_multiple_landings_share_the_real_deduction_before_filtering(): void
    {
        $entry = $this->node('前置'); $first = $this->node('落地一'); $second = $this->node('落地二');
        $this->record($entry->id, $entry->id, 'entry', 2, 200, 800);
        $this->record($entry->id, $first->id, 'relay', 2, 50, 200);
        $this->record($entry->id, $second->id, 'relay', 2, 100, 400);
        $data = $this->getStats()->assertOk()->assertJsonPath('data.actual_summary.total', 1000)
            ->assertJsonPath('data.billed_summary.total', 2000)->assertJsonPath('data.direct_summary.total', 250)->json('data');
        $this->assertSame(1000, array_sum(array_column($data['list'], 'total')));
        $this->assertSame(2000, array_sum(array_column($data['list'], 'billed_total')));
        $this->getStats('user', ['server_id' => $entry->id])->assertJsonPath('data.actual_summary.total', 250)
            ->assertJsonPath('data.billed_summary.total', 500);
        $this->getStats('user', ['server_id' => $second->id])->assertJsonPath('data.actual_summary.total', 500)
            ->assertJsonPath('data.billed_summary.total', 1000);
    }

    public function test_sampling_differences_reconcile_before_clamping_without_inventing_direct_traffic(): void
    {
        $entry = $this->node('前置'); $relay = $this->node('落地');
        $this->record($entry->id, $entry->id, 'entry', 1, 90, 90);
        $this->record($entry->id, $relay->id, 'relay', 1, 100, 100);
        $this->getStats()->assertJsonPath('data.direct_summary.total', 0)->assertJsonPath('data.actual_summary.total', 200)
            ->assertJsonPath('data.billed_summary.total', 180);
        $this->record($entry->id, $entry->id, 'entry', 1, 20, 20);
        $this->record($entry->id, $relay->id, 'relay', 1, 10, 10);
        $this->getStats()->assertJsonCount(1, 'data.list')->assertJsonPath('data.actual_summary.total', 220)
            ->assertJsonPath('data.billed_summary.total', 220);
        $this->getStats('users')->assertJsonPath('data.list.0.total', 220);
    }

    public function test_rate_changes_are_separate_rows_in_daily_weekly_and_monthly_details(): void
    {
        $entry = $this->node('前置'); $relay = $this->node('落地');
        foreach ([0, 0.5, 1, 2] as $rate) {
            $this->record($entry->id, $entry->id, 'entry', $rate, 100, 200);
            $this->record($entry->id, $relay->id, 'relay', $rate, 100, 200);
        }
        foreach (['day', 'week', 'month'] as $unit) {
            $data = $this->getStats('user', ['unit' => $unit])->assertOk()->assertJsonCount(4, 'data.list')
                ->assertJsonPath('data.actual_summary.total', 1200)->assertJsonPath('data.billed_summary.total', 1050)->json('data');
            $this->assertSame([0, 0.5, 1, 2], array_column($data['list'], 'rate'));
            $this->assertSame([0, 150, 300, 600], array_column($data['list'], 'billed_total'));
        }
    }

    public function test_integer_rounding_conserves_deductions_across_many_routes(): void
    {
        $entry = $this->node('前置');
        $this->record($entry->id, $entry->id, 'entry', 0.5, 3, 5);
        foreach ([1, 2, 3] as $index) {
            $relay = $this->node('落地' . $index);
            $this->record($entry->id, $relay->id, 'relay', 0.5, 1, $index === 3 ? 3 : 1);
        }
        $list = $this->getStats()->assertJsonPath('data.billed_summary.total', 3)->json('data.list');
        $this->assertSame(1, array_sum(array_column($list, 'billed_upload')));
        $this->assertSame(2, array_sum(array_column($list, 'billed_download')));
        $this->assertSame($list, $this->getStats()->json('data.list'));
    }

    public function test_entry_binding_changes_do_not_move_old_flow_or_change_its_rate(): void
    {
        $old = $this->node('旧前置'); $new = $this->node('新前置'); $relay = $this->node('落地');
        $this->record($old->id, $old->id, 'entry', 2, 100, 0);
        $this->record($old->id, $relay->id, 'relay', 2, 100, 0);
        $this->record($new->id, $new->id, 'entry', 1, 100, 0);
        $this->record($new->id, $relay->id, 'relay', 1, 100, 0);
        $this->getStats()->assertJsonCount(2, 'data.list')->assertJsonPath('data.actual_summary.total', 200)
            ->assertJsonPath('data.billed_summary.total', 300)->assertJsonPath('data.direct_summary.total', 0);
    }

    public function test_upgrade_keeps_old_statistics_and_balance_without_backfilling(): void
    {
        $node = $this->node('前置');
        DB::table('v2_stat_user_server')->insert(['user_id' => 101, 'server_id' => $node->id, 'kind' => 'entry',
            'record_at' => now()->startOfDay()->timestamp, 'u' => 10, 'd' => 20, 'billed_u' => 20, 'billed_d' => 40]);
        $this->record($node->id, $node->id, 'entry', 2, 100, 200, received: now()->startOfDay()->subSecond()->timestamp);
        $this->getStats()->assertJsonCount(0, 'data.list');
        $this->assertDatabaseCount('v2_stat_user_server', 1);
        $this->assertDatabaseCount(UserRouteTraffic::TABLE, 0);
    }

    public function test_batch_failure_retry_and_overview_preserve_node_totals_and_billing(): void
    {
        $entry = $this->node('前置'); $relay = $this->node('落地');
        $user = User::forceCreate(['id' => 101, 'email' => 'route-user@example.test', 'password' => Str::random(32),
            'uuid' => (string) Str::uuid(), 'token' => Str::random(32), 'u' => 100, 'd' => 200]);
        $batch = NodeReportBatch::create(['server_id' => $entry->id, 'server_type' => 'vless',
            'report_id' => Str::random(20), 'report_key' => Str::random(64), 'server_snapshot' => ['id' => $entry->id, 'rate' => 2],
            'traffic' => [101 => [100, 400]], 'relay_traffic' => [['server_id' => $relay->id, 'server_type' => 'vless', 'u' => 80, 'd' => 320]],
            'relay_user_traffic' => [101 => [$relay->id => [80, 320]]], 'record_at' => now()->startOfDay()->timestamp,
            'status' => 'pending', 'attempts' => 0]);
        Redis::shouldReceive('sadd')->once()->andThrow(new \RuntimeException('测试回滚'));
        try { (new ProcessNodeReportBatch($batch->id))->handle(); $this->fail('应当回滚'); }
        catch (\RuntimeException $error) { $this->assertSame('测试回滚', $error->getMessage()); }
        $this->assertDatabaseCount(UserRouteTraffic::TABLE, 0);
        $this->assertDatabaseCount('v2_stat_node_hour', 0);
        $this->assertSame(300, (int) $user->fresh()->u + (int) $user->fresh()->d);
        $this->travel(1)->days();
        Redis::shouldReceive('sadd')->once()->andReturn(1);
        (new ProcessNodeReportBatch($batch->id))->handle(); (new ProcessNodeReportBatch($batch->id))->handle();
        $this->assertSame(1300, (int) $user->fresh()->u + (int) $user->fresh()->d);
        $this->assertSame(500, (int) $entry->fresh()->u + (int) $entry->fresh()->d);
        $this->assertSame(400, (int) $relay->fresh()->u + (int) $relay->fresh()->d);
        $this->assertSame(900, (int) StatServer::sum(DB::raw('u + d')));
        $this->assertSame(900, (int) DB::table('v2_stat_node_hour')->sum(DB::raw('u + d')));
        $this->getStats('traffic', ['period' => '7d'])->assertJsonPath('data.summary.total', 900);
        $this->getStats('user', ['period' => '7d'])->assertJsonPath('data.actual_summary.total', 500)
            ->assertJsonPath('data.billed_summary.total', 1000);
    }
}
