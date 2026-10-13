<?php

namespace Tests\Feature\Server;

use App\Jobs\ProcessNodeReportBatch;
use App\Models\NodeReportBatch;
use App\Models\Server;
use App\Models\StatUser;
use App\Models\User;
use App\Services\NodeReportService;
use App\Services\RelayTrafficBilling;
use App\Services\ServerService;
use App\Services\UserRouteTraffic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RelayRateTest extends TestCase
{
    use RefreshDatabase;

    private function node(array $values = []): Server
    {
        return Server::create($values + ['name' => '倍率测试节点', 'type' => 'vless', 'host' => '10.20.30.40',
            'port' => 443, 'server_port' => 443, 'kernel_type' => 'xray', 'rate' => 2,
            'group_ids' => ['1'], 'show' => true, 'enabled' => true, 'protocol_settings' => ['network' => 'tcp', 'tls' => 0]]);
    }

    private function user(bool $admin = false): User
    {
        return User::create(['email' => Str::random(12) . '@example.test', 'password' => Str::random(32),
            'uuid' => (string) Str::uuid(), 'token' => Str::random(32), 'is_admin' => $admin, 'group_id' => 1,
            'transfer_enable' => 10000000, 'expired_at' => time() + 86400, 'u' => 0, 'd' => 0]);
    }

    private function adminPath(): string
    {
        return '/api/v2/' . hash('crc32b', config('app.key'));
    }

    public function test_default_and_independent_rates_reach_admin_subscription_and_time_ranges(): void
    {
        Sanctum::actingAs($this->user(true));
        $user = $this->user();
        foreach (['xray', 'singbox'] as $kernel) {
            foreach (['vless', 'hysteria'] as $protocol) {
                $entry = $this->node(['kernel_type' => $kernel, 'type' => $protocol,
                    'protocol_settings' => ['network' => 'tcp', 'tls' => 0, 'version' => 2],
                    'rate_time_enable' => true, 'rate_time_ranges' => [['start' => '00:00', 'end' => '23:59', 'rate' => 3]]]);
                $child = $this->node(['relay_entry_id' => $entry->id, 'rate' => 0.25]);
                $this->assertFalse($child->fresh()->relay_rate_override);
                foreach ([false => 3.0, true => 0.25] as $override => $rate) {
                    $child->update(['relay_rate_override' => (bool) $override]);
                    $this->assertSame($rate, $child->fresh()->getEffectiveRate());
                    $nodes = collect($this->getJson($this->adminPath() . '/server/manage/getNodes')->assertOk()->json('data'))->keyBy('id');
                    $this->assertSame($rate, (float) $nodes[$child->id]['effective_rate']);
                    $this->assertSame(0.25, (float) $nodes[$child->id]['rate']);
                    $subscription = collect(ServerService::getAvailableServers($user))->keyBy('id');
                    $this->assertSame($rate, (float) $subscription[$child->id]['rate']);
                }
                $child->update(['rate_time_enable' => true, 'rate_time_ranges' => [['start' => '00:00', 'end' => '23:59', 'rate' => 0]]]);
                $this->assertSame(0.0, $child->fresh()->getEffectiveRate());
                $child->update(['relay_rate_override' => false]);
                $this->assertSame(3.0, $child->fresh()->getEffectiveRate());
            }
        }
    }

    public function test_save_persists_override_and_rejects_invalid_rate(): void
    {
        Sanctum::actingAs($this->user(true));
        $entry = $this->node();
        $child = $this->node(['relay_entry_id' => $entry->id]);
        $payload = $child->toArray();
        foreach ([0, 0.125, 0.12345678, 4] as $rate) {
            $this->postJson($this->adminPath() . '/server/manage/save', array_replace($payload,
                ['id' => $child->id, 'relay_rate_override' => true, 'rate' => $rate]))->assertOk()->assertJsonPath('data', true);
            $this->assertTrue($child->fresh()->relay_rate_override);
            $this->assertSame((float) $rate, $child->fresh()->getEffectiveRate());
        }
        foreach ([-1, 'invalid', ''] as $rate) {
            $this->postJson($this->adminPath() . '/server/manage/save', array_replace($payload,
                ['id' => $child->id, 'relay_rate_override' => true, 'rate' => $rate]))->assertUnprocessable();
            $this->assertSame(4.0, $child->fresh()->getEffectiveRate());
        }
        $this->postJson($this->adminPath() . '/server/manage/save', array_replace($payload,
            ['id' => $child->id, 'relay_rate_override' => false, 'rate' => 4]))->assertOk();
        $this->assertSame(2.0, $child->fresh()->getEffectiveRate());
    }

    public function test_mixed_billing_snapshot_survives_failure_rebinding_rate_changes_and_replays(): void
    {
        Bus::fake();
        $this->travelTo(now()->startOfDay()->setTime(14, 35));
        DB::table('v2_settings')->whereIn('name', ['user_route_statistics_started_at', 'fine_traffic_started_at'])
            ->update(['value' => (string) now()->startOfDay()->timestamp]);
        $entry = $this->node();
        $custom = $this->node(['relay_entry_id' => $entry->id, 'relay_rate_override' => true, 'rate' => 4]);
        $inherited = $this->node(['relay_entry_id' => $entry->id, 'rate' => 9]);
        $free = $this->node(['relay_entry_id' => $entry->id, 'relay_rate_override' => true, 'rate' => 0]);
        $user = $this->user();
        $traffic = [$user->id => [100, 200]];
        $relay = [$user->id => [$custom->id => [20, 40], $inherited->id => [30, 60], $free->id => [10, 20]]];
        $batch = app(NodeReportService::class)->accept($entry, 'rate-test-report', $traffic,
            [$custom->id => [20, 40], $inherited->id => [30, 60], $free->id => [10, 20]], $relay);
        $this->assertSame([($custom->id) => 4, ($free->id) => 0], $batch->server_snapshot['relay_rates']);
        $entry->update(['rate' => 9]);
        $custom->update(['rate' => 20, 'relay_entry_id' => null]);
        $free->update(['rate' => 5]);
        $replayed = app(NodeReportService::class)->accept($entry->fresh(), 'rate-test-report', $traffic, [], $relay);
        $this->assertSame($batch->id, $replayed->id);
        Bus::assertDispatchedTimes(ProcessNodeReportBatch::class, 1);
        Redis::shouldReceive('sadd')->once()->andThrow(new \RuntimeException('倍率测试回滚'));
        try {
            (new ProcessNodeReportBatch($batch->id))->handle();
            $this->fail('应当回滚');
        } catch (\RuntimeException $error) {
            $this->assertSame('倍率测试回滚', $error->getMessage());
        }
        $this->assertSame(0, (int) $user->fresh()->u);
        $this->assertDatabaseCount(UserRouteTraffic::TABLE, 0);
        Redis::shouldReceive('sadd')->once()->andReturn(1);
        (new ProcessNodeReportBatch($batch->id))->handle();
        (new ProcessNodeReportBatch($batch->id))->handle();
        $this->assertSame(220, (int) $user->fresh()->u);
        $this->assertSame(440, (int) $user->fresh()->d);
        $this->assertSame(660, (int) StatUser::sum(DB::raw('u + d')));
        $this->assertSame(300, (int) $entry->fresh()->u + (int) $entry->fresh()->d);
        $this->assertSame(60, (int) $custom->fresh()->u + (int) $custom->fresh()->d);
        Sanctum::actingAs($this->user(true));
        foreach (['day', 'week', 'month'] as $unit) {
            $data = $this->getJson($this->adminPath() . '/statistics/user?' . http_build_query([
                'user_id' => $user->id, 'period' => 'today', 'unit' => $unit,
            ]))->assertOk()->assertJsonPath('data.actual_summary.total', 300)->assertJsonPath('data.billed_summary.total', 660)->json('data.list');
            $lines = collect($data)->keyBy('server_id');
            $this->assertSame(4, $lines[$custom->id]['rate']);
            $this->assertSame(240, $lines[$custom->id]['billed_total']);
            $this->assertSame(0, $lines[$free->id]['billed_total']);
            $this->assertSame(120, $lines[$entry->id]['total']);
        }
        $this->getJson($this->adminPath() . '/statistics/users?period=today&metric=billed&min_traffic=0')
            ->assertOk()->assertJsonPath('data.list.0.total', 660);
        // 同一天的新旧记录一起查询，不能漏算或重复计算同节点、同倍率的历史归属。
        app(UserRouteTraffic::class)->record($user->id, $entry->id, $entry->id, 'entry', 2,
            now()->startOfDay()->timestamp, 10, 20, 20, 40, now()->timestamp);
        app(UserRouteTraffic::class)->record($user->id, $entry->id, $inherited->id, 'relay', 2,
            now()->startOfDay()->timestamp, 10, 20, 0, 0, now()->timestamp);
        $day = now()->toDateString();
        foreach (['day', 'minute', 'hour'] as $precision) {
            if ($precision === 'hour') {
                $this->travel(1)->days();
            }
            $range = $precision === 'day' ? ['period' => 'today'] : ['period' => 'custom', 'precision' => 'auto',
                'start_date' => $day, 'end_date' => $day, 'start_time' => '14:00', 'end_time' => '15:00'];
            $path = $this->adminPath() . '/statistics/';
            $data = $this->getJson($path . 'user?' . http_build_query($range + ['user_id' => $user->id]))
                ->assertOk()->assertJsonPath('data.actual_summary.total', 330)->assertJsonPath('data.billed_summary.total', 720)->json('data.list');
            $rows = collect($data)->where('server_id', $inherited->id)->values();
            $this->assertCount(1, $rows);
            $this->assertSame(120, $rows[0]['total']);
            $this->assertSame(240, $rows[0]['billed_total']);
            $this->getJson($path . 'users?' . http_build_query($range))->assertOk()->assertJsonPath('data.list.0.total', 330);
            $this->getJson($path . 'users?' . http_build_query($range + ['metric' => 'billed']))->assertOk()->assertJsonPath('data.list.0.total', 720);
        }
    }

    public function test_fractional_rates_remain_distinct_in_billing_and_history(): void
    {
        Bus::fake();
        $entry = $this->node(['rate' => 1]);
        $first = $this->node(['relay_entry_id' => $entry->id, 'relay_rate_override' => true, 'rate' => 0.125]);
        $second = $this->node(['relay_entry_id' => $entry->id, 'relay_rate_override' => true, 'rate' => 0.126]);
        $user = $this->user();
        $this->assertSame(0.125, $first->fresh()->getEffectiveRate());
        $this->assertSame(0.126, $second->fresh()->getEffectiveRate());
        $batch = app(NodeReportService::class)->accept($entry, 'fractional-rate-test', [$user->id => [2500, 5000]], [],
            [$user->id => [$first->id => [1000, 2000], $second->id => [1000, 2000]]]);
        Redis::shouldReceive('sadd')->once()->andReturn(1);
        (new ProcessNodeReportBatch($batch->id))->handle();
        (new ProcessNodeReportBatch($batch->id))->handle();

        $this->assertSame(751, (int) $user->fresh()->u);
        $this->assertSame(1502, (int) $user->fresh()->d);
        $this->assertSame(3, StatUser::where('user_id', $user->id)->count());
        foreach ([0.125, 0.126, 1.0] as $index => $rate) {
            $row = StatUser::where('user_id', $user->id)->where('server_rate', $rate)->sole();
            $this->assertSame($rate, (float) $row->server_rate);
            $this->assertSame([125, 126, 500][$index], (int) $row->u);
            $this->assertSame([250, 252, 1000][$index], (int) $row->d);
        }
        $rates = DB::table(UserRouteTraffic::TABLE)->where('user_id', $user->id)
            ->where('kind', 'transit')->pluck('rate', 'server_id');
        $this->assertSame(0.125, (float) $rates[$first->id]);
        $this->assertSame(0.126, (float) $rates[$second->id]);
        $this->assertSame(2253, (int) StatUser::where('user_id', $user->id)->sum(DB::raw('u + d')));
    }

    public function test_sampling_overflow_and_rounding_never_add_or_duplicate_billable_bytes(): void
    {
        $batch = new NodeReportBatch(['server_id' => 1, 'server_snapshot' => ['id' => 1, 'rate' => 0.5, 'relay_rates' => [2 => 0.5]],
            'traffic' => [10 => [3, 5]], 'relay_user_traffic' => [10 => [2 => [4, 4], 3 => [4, 8]]]]);
        $rows = app(RelayTrafficBilling::class)->lines($batch)[10];
        $this->assertSame(3, array_sum(array_column($rows, 'u')));
        $this->assertSame(5, array_sum(array_column($rows, 'd')));
        $this->assertSame(1, array_sum(array_column($rows, 'billed_u')));
        $this->assertSame(2, array_sum(array_column($rows, 'billed_d')));
        $this->assertSame($rows, app(RelayTrafficBilling::class)->lines($batch)[10]);
        $batch->traffic = [];
        $this->assertSame([], app(RelayTrafficBilling::class)->lines($batch)[10]);
    }

    public function test_legacy_reports_and_foreign_landings_keep_the_original_billing_path(): void
    {
        Bus::fake();
        $entry = $this->node(); $other = $this->node();
        $child = $this->node(['relay_entry_id' => $entry->id, 'rate' => 8]);
        $foreign = $this->node(['relay_entry_id' => $other->id, 'relay_rate_override' => true, 'rate' => 20]);
        $user = $this->user();
        $batch = app(NodeReportService::class)->accept($entry, 'legacy-rate-test', [$user->id => [10, 20]], [],
            [$user->id => [$child->id => [5, 10], $foreign->id => [5, 10]]]);
        $this->assertArrayNotHasKey('relay_rates', $batch->server_snapshot);
        $this->assertArrayNotHasKey($foreign->id, $batch->relay_user_traffic[$user->id]);
        Redis::shouldReceive('sadd')->once()->andReturn(1);
        (new ProcessNodeReportBatch($batch->id))->handle();
        $this->assertSame(60, (int) $user->fresh()->u + (int) $user->fresh()->d);
    }
}
