<?php

namespace Tests\Feature;

use App\Jobs\ProcessNodeReportBatch;
use App\Models\NodeReportBatch;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\User;
use App\Services\NodeReportService;
use App\Services\ProxyTrafficRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProxyTrafficStatisticsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfDay()->addHours(12));
        DB::table('v2_settings')->where('name', 'proxy_traffic_started_at')->update(['value' => (string) now()->startOfDay()->timestamp]);
        Bus::fake([ProcessNodeReportBatch::class]);
        $this->user = User::create(['email' => 'proxy-traffic@example.test', 'password' => Str::random(32),
            'uuid' => (string) Str::uuid(), 'token' => Str::random(32), 'is_admin' => true]);
        Sanctum::actingAs($this->user);
    }

    private function machine(string $name): ServerMachine
    {
        return ServerMachine::create(['name' => $name, 'is_active' => true, 'token' => Str::random(32)]);
    }

    private function node(string $name, ?ServerMachine $machine = null, ?Server $entry = null): Server
    {
        return Server::create(['name' => $name, 'type' => 'vless', 'host' => 'example.test', 'port' => 443,
            'server_port' => 443, 'rate' => 3, 'group_ids' => [], 'enabled' => true,
            'machine_id' => $machine?->id, 'relay_entry_id' => $entry?->id]);
    }

    private function report(Server $entry, array $total, array $relays, ?string $id = null): NodeReportBatch
    {
        return app(NodeReportService::class)->accept($entry, $id ?? Str::random(20),
            $total === [0, 0] ? [] : [$this->user->id => $total], $relays,
            $relays === [] ? [] : [$this->user->id => $relays]);
    }

    private function process(NodeReportBatch $batch): void
    {
        Redis::shouldReceive('sadd')->andReturn(1);
        (new ProcessNodeReportBatch($batch->id))->handle();
    }

    private function path(string $endpoint, array $params = []): string
    {
        return '/api/v2/' . hash('crc32b', config('app.key')) . '/statistics/' . $endpoint . '?' . http_build_query($params + ['period' => 'today', 'scope' => 'node', 'precision' => 'auto']);
    }

    private function totals(string $scope = 'node', array $params = []): array
    {
        return collect($this->getJson($this->path('nodes', ['scope' => $scope] + $params))->assertOk()->json('data.list'))
            ->pluck('total', 'id')->all();
    }

    public function test_different_machines_match_example_and_ignore_rates_and_network_metrics(): void
    {
        $a = $this->machine('服务器 A'); $b = $this->machine('服务器 B');
        $entry = $this->node('A', $a); $landing = $this->node('B', $b, $entry);
        $landing->update(['rate' => 9, 'relay_rate_override' => true]);
        $gb = 1024 ** 3;
        $batch = $this->report($entry, [$gb, 4 * $gb], [$landing->id => [$gb, 3 * $gb]], 'example');
        $a->update(['load_status' => ['network' => ['sent' => 100 * $gb, 'received' => 200 * $gb]]]);
        $this->process($batch);
        $this->process($batch);
        $this->assertEquals([$entry->id => $gb, $landing->id => 4 * $gb], $this->totals());
        $this->assertEquals([$a->id => 5 * $gb, $b->id => 4 * $gb], $this->totals('machine'));
        $this->getJson($this->path('traffic'))->assertOk()->assertJsonPath('data.summary.total', 5 * $gb);
        $this->getJson($this->path('traffic', ['scope' => 'machine']))->assertOk()->assertJsonPath('data.summary.total', 9 * $gb);
        $this->assertSame($gb, $entry->fresh()->usage_u + $entry->fresh()->usage_d);
        $this->assertSame(5 * $gb, $a->fresh()->proxy_u + $a->fresh()->proxy_d);
        // 原承载量、上限与用户计费仍使用原来的处理路径。
        $this->assertSame(5 * $gb, (int) $entry->fresh()->u + (int) $entry->fresh()->d);
        $this->assertSame(39 * $gb, (int) $this->user->fresh()->u + (int) $this->user->fresh()->d);
        $this->getJson($this->path('traffic', ['scope' => 'legacy']))->assertUnprocessable();
    }

    public function test_same_machine_relay_counts_once_and_multiple_nodes_are_added(): void
    {
        $machine = $this->machine('同机');
        $entry = $this->node('入口', $machine); $landing = $this->node('落地', $machine, $entry);
        $other = $this->node('另一直出', $machine);
        $this->process($this->report($entry, [100, 400], [$landing->id => [100, 300]]));
        $this->process($this->report($other, [20, 30], []));
        $this->assertSame([$machine->id => 550], $this->totals('machine'));
        $this->assertEquals([$entry->id => 100, $landing->id => 400, $other->id => 50], $this->totals());
        $this->assertSame(550, $machine->fresh()->proxy_u + $machine->fresh()->proxy_d);
    }

    public function test_first_receipt_fixes_machine_assignment_through_retry_rebinding_and_deletion(): void
    {
        $a = $this->machine('原 A'); $b = $this->machine('原 B'); $c = $this->machine('新 C');
        $entry = $this->node('入口', $a); $landing = $this->node('落地', $b, $entry);
        $batch = $this->report($entry, [10, 40], [$landing->id => [10, 30]], 'stable-assignment');
        $entry->update(['machine_id' => $c->id]); $landing->update(['machine_id' => $c->id]);
        $replay = $this->report($entry->fresh(), [10, 40], [$landing->id => [10, 30]], 'stable-assignment');
        $this->assertSame($batch->id, $replay->id);
        $b->delete(); $landing->delete();
        $this->process($batch);
        $this->assertEquals([$a->id => 50, $b->id => 40], $this->totals('machine'));
        $this->assertEquals([$entry->id => 10, $landing->id => 40], $this->totals());
        $this->getJson($this->path('nodes', ['scope' => 'machine', 'search' => '原 B']))->assertOk()
            ->assertJsonPath('data.list.0.name', '原 B（已删除）');
        $this->assertSame(0, $c->fresh()->proxy_u);
    }

    public function test_failure_rolls_back_all_new_totals_and_recovery_is_idempotent(): void
    {
        $machine = $this->machine('测试'); $entry = $this->node('入口', $machine);
        $batch = $this->report($entry, [100, 200], []);
        Redis::shouldReceive('sadd')->once()->andThrow(new \RuntimeException('模拟结算失败'));
        try { (new ProcessNodeReportBatch($batch->id))->handle(); $this->fail('应失败'); }
        catch (\RuntimeException $error) { $this->assertSame('模拟结算失败', $error->getMessage()); }
        $this->assertDatabaseCount(ProxyTrafficRecorder::TABLE, 0);
        $this->assertSame(0, $entry->fresh()->usage_u);
        $this->assertSame(0, $machine->fresh()->proxy_u);
        $this->process($batch); $this->process($batch);
        $this->assertSame([$entry->id => 300], $this->totals());
        $this->assertSame([$machine->id => 300], $this->totals('machine'));
    }

    public function test_sampling_skew_is_reconciled_across_batches_and_trend_buckets(): void
    {
        $machine = $this->machine('测试'); $entry = $this->node('入口', $machine); $landing = $this->node('落地', $machine, $entry);
        $this->process($this->report($entry, [20, 40], []));
        $this->travel(2)->minutes();
        $this->process($this->report($entry, [0, 0], [$landing->id => [30, 60]]));
        $this->travel(2)->minutes();
        $this->process($this->report($entry, [10, 20], []));
        $this->assertSame([$landing->id => 90], $this->totals());
        $this->assertSame([$machine->id => 90], $this->totals('machine'));
        $response = $this->getJson($this->path('traffic'))->assertOk()->assertJsonPath('data.summary.total', 90);
        $this->assertSame(90, array_sum(array_column($response->json('data.list'), 'total')));
        $this->assertSame(0, $entry->fresh()->usage_u + $entry->fresh()->usage_d);
    }

    public function test_unknown_machine_is_not_guessed_and_foreign_relays_are_ignored(): void
    {
        $entry = $this->node('未绑定入口'); $foreign = $this->node('无关节点', $this->machine('无关机器'));
        $this->process($this->report($entry, [10, 20], [$foreign->id => [100, 200]]));
        $this->assertSame([$entry->id => 30], $this->totals());
        $this->assertSame([], $this->totals('machine'));
    }

    public function test_old_batches_stay_in_legacy_and_new_scopes_do_not_fabricate_history(): void
    {
        $entry = $this->node('入口', $this->machine('测试'));
        $batch = $this->report($entry, [10, 20], []);
        $snapshot = $batch->server_snapshot; unset($snapshot['proxy_traffic']);
        $batch->update(['server_snapshot' => $snapshot]);
        $this->process($batch);
        $this->getJson($this->path('traffic'))->assertOk()->assertJsonCount(0, 'data.list')->assertJsonPath('data.summary.total', 0);
        $this->getJson($this->path('traffic', ['scope' => 'legacy']))->assertUnprocessable();
        $this->assertSame(30, (int) \App\Models\StatServer::sum(DB::raw('u + d')));
        $this->assertSame(0, $entry->fresh()->usage_u);
    }

    public function test_reset_preserves_new_statistics_and_copy_starts_at_zero(): void
    {
        $entry = $this->node('入口', $this->machine('测试'));
        $this->process($this->report($entry, [10, 20], []));
        $prefix = '/api/v2/' . hash('crc32b', config('app.key')) . '/server/manage/';
        $this->postJson($prefix . 'resetTraffic', ['id' => $entry->id])->assertOk();
        $this->assertSame(0, (int) $entry->fresh()->u);
        $this->assertSame(30, $entry->fresh()->usage_u + $entry->fresh()->usage_d);
        $this->postJson($prefix . 'copy', ['id' => $entry->id])->assertOk();
        $copy = Server::where('id', '!=', $entry->id)->firstOrFail();
        $this->assertSame(0, $copy->usage_u + $copy->usage_d);
        $this->assertSame([$entry->id => 30], $this->totals());
    }

    public function test_retention_removes_expired_buckets_without_changing_totals(): void
    {
        $entry = $this->node('入口', $this->machine('测试'));
        $this->process($this->report($entry, [10, 20], []));
        $this->travel(1)->days();
        $this->artisan('reset:log')->assertExitCode(0);
        $this->assertDatabaseMissing(ProxyTrafficRecorder::TABLE, ['unit' => 'minute']);
        $this->assertSame(2, DB::table(ProxyTrafficRecorder::TABLE)->where('scope', 'node')->count());
        $this->getJson($this->path('traffic', ['period' => '7d']))->assertOk()->assertJsonPath('data.summary.total', 30);
        $this->travel(30)->days();
        $this->artisan('reset:log')->assertExitCode(0);
        $this->assertDatabaseCount(ProxyTrafficRecorder::TABLE, 2);
        $this->travel(335)->days();
        $this->artisan('reset:log')->assertExitCode(0);
        $this->assertDatabaseCount(ProxyTrafficRecorder::TABLE, 0);
        $this->assertSame(30, $entry->fresh()->usage_u + $entry->fresh()->usage_d);
    }

    public function test_legacy_direct_reports_and_realtime_admin_views_include_new_counters(): void
    {
        $machine = $this->machine('旧协议'); $entry = $this->node('直出', $machine);
        (new \App\Jobs\StatServerJob($entry->toArray(), [$this->user->id => [7, 13]], 'vless'))->handle();
        $this->assertSame([$entry->id => 20], $this->totals());
        $this->assertSame([$machine->id => 20], $this->totals('machine'));
        $service = app(\App\Services\RealtimeSnapshotService::class);
        $view = $service->snapshot($service->validate(['nodes' => [$entry->id], 'machines' => [$machine->id]]));
        $this->assertSame(7, $view['nodes'][$entry->id]['usage_u']);
        $this->assertSame(13, $view['machines'][$machine->id]['proxy_d']);
        $this->assertArrayNotHasKey('token', $view['machines'][$machine->id]);
        $path = '/api/v2/' . hash('crc32b', config('app.key')) . '/server/machine/fetch';
        $this->getJson($path)->assertOk()->assertJsonPath('data.0.proxy_u', 7)->assertJsonPath('data.0.proxy_d', 13);
    }

    public function test_realtime_receipt_replay_uses_the_same_batch(): void
    {
        $machine = $this->machine('实时'); $entry = $this->node('入口', $machine);
        $payload = ['report_id' => 'realtime-proxy-test', 'traffic' => [$this->user->id => [10, 20]]];
        app(NodeReportService::class)->acceptRealtime($entry, $payload);
        app(NodeReportService::class)->acceptRealtime($entry, $payload);
        $this->assertDatabaseCount('v2_node_report_batch', 1);
        $this->process(NodeReportBatch::firstOrFail());
        $this->assertSame([$machine->id => 30], $this->totals('machine'));
    }

    public function test_custom_range_sort_paging_and_metadata_are_consistent(): void
    {
        $machine = $this->machine('literal_%'); $entry = $this->node('入口', $machine);
        $this->process($this->report($entry, [10, 20], []));
        $this->travel(2)->minutes();
        $this->process($this->report($entry, [100, 200], []));
        $params = ['period' => 'custom', 'start_date' => now()->toDateString(), 'end_date' => now()->toDateString(),
            'start_time' => '12:00', 'end_time' => '12:01', 'scope' => 'machine'];
        $this->getJson($this->path('traffic', $params))->assertOk()->assertJsonPath('data.summary.total', 30);
        $this->assertSame([$machine->id => 30], $this->totals('machine', $params));
        $this->getJson($this->path('nodes', ['scope' => 'machine', 'search' => '_%', 'sort' => 'upload', 'direction' => 'asc', 'page_size' => 1]))
            ->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.list.0.total', 330);
        $this->getJson($this->path('nodes', ['page' => 2, 'page_size' => 1]))->assertOk()->assertJsonCount(0, 'data.list');
        DB::table('v2_settings')->where('name', 'proxy_traffic_started_at')->update(['value' => (string) now()->timestamp]);
        $this->getJson($this->path('traffic', $params))->assertUnprocessable();
        $this->getJson($this->path('traffic'))->assertOk()->assertJsonPath('data.meta.partial', true);
    }
}
