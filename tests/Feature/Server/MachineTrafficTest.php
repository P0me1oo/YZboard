<?php

namespace Tests\Feature\Server;

use App\Http\Controllers\V2\Admin\Server\MachineController;
use App\Jobs\ProcessNodeReportBatch;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\User;
use App\Services\MachineTrafficService as Traffic;
use App\Services\NodeReportService;
use App\Services\ServerRelayService;
use App\Services\ServerService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class MachineTrafficTest extends TestCase
{
    use RefreshDatabase;

    private array $pushes = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'Asia/Shanghai']);
        $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'Asia/Shanghai'));
        Redis::shouldReceive('publish')->byDefault()->andReturnUsing(function ($channel, $message) {
            $this->pushes[] = json_decode($message, true);
            return 1;
        });
        Route::post('/_tests/machine-traffic/save', [MachineController::class, 'save']);
        Route::get('/_tests/machine-traffic/fetch', [MachineController::class, 'fetch']);
    }

    private function machine(): ServerMachine
    {
        return ServerMachine::create(['name' => '计量测试', 'token' => Str::random(32), 'is_active' => true]);
    }

    private function configure(ServerMachine $m, array $params): void
    {
        $this->postJson('/_tests/machine-traffic/save', ['id' => $m->id, 'name' => $m->name] + $params)
            ->assertOk()->assertJsonPath('data', true);
        $m->refresh();
    }

    private function sample(ServerMachine $m, int $rx = 0, int $tx = 0, array $extra = []): array
    {
        $p = Traffic::view($m->fresh());
        return array_replace(['epoch' => $p['epoch'], 'period_start' => $p['period_start'],
            'sequence' => $p['sequence'] + 1, 'rx' => $rx, 'tx' => $tx, 'initialized' => true, 'interfaces' => ['eth0']], $extra);
    }

    private function send(ServerMachine $m, array $sample): array
    {
        return $this->postJson('/api/v2/server/machine/traffic', [
            'machine_id' => $m->id, 'token' => $m->token, 'sample' => $sample,
        ])->assertOk()->json('traffic');
    }

    private function node(ServerMachine $m, array $extra = []): Server
    {
        return Server::create(array_replace(['name' => '计量节点', 'machine_id' => $m->id,
            'type' => 'vless', 'kernel_type' => 'xray', 'host' => '127.0.0.1', 'port' => 24443,
            'server_port' => 24443, 'protocol_settings' => ['tls' => 0, 'network' => 'tcp'],
            'enabled' => true, 'show' => true, 'group_ids' => ['1'], 'rate' => 1], $extra));
    }

    public function test_new_machine_defaults_to_both_and_existing_modes_are_preserved(): void
    {
        foreach ([null, 'outbound', 'both', 'proxy'] as $mode) {
            $params = ['name' => '新建计费测试'];
            if ($mode !== null) {
                $params['traffic_mode'] = $mode;
            }
            $id = $this->postJson('/_tests/machine-traffic/save', $params)->assertOk()->json('data.id');
            $machine = ServerMachine::findOrFail($id);
            $this->assertSame($mode ?? 'both', Traffic::view($machine)['mode']);
            $this->assertSame(0, Traffic::view($machine)['limit']);
            $this->postJson('/_tests/machine-traffic/save', ['id' => $id, 'name' => '修改名称'])
                ->assertOk()->assertJsonPath('data', true);
            $machine->refresh();
            $this->assertSame('修改名称', $machine->name);
            $this->assertSame($mode ?? 'both', Traffic::view($machine)['mode']);
        }

        $legacy = $this->machine();
        $this->postJson('/_tests/machine-traffic/save', ['id' => $legacy->id, 'name' => '旧服务器改名'])
            ->assertOk()->assertJsonPath('data', true);
        $legacy->refresh();
        $this->assertSame('旧服务器改名', $legacy->name);
        $this->assertSame('outbound', Traffic::view($legacy)['mode']);
    }

    public function test_old_machine_is_unlimited_and_first_enable_starts_at_zero_without_calibration(): void
    {
        $m = $this->machine();
        $node = $this->node($m);
        $this->assertFalse(Traffic::blocked($m));
        $this->send($m, $this->sample($m, 40, 60));
        $this->configure($m, ['traffic_limit_gb' => 1]);
        $p = Traffic::view($m);
        $this->assertSame(0, $p['used']);
        $this->assertSame(Traffic::GB, $p['limit']);
        $this->assertTrue($p['blocked']);
        $this->pushes = [];
        $this->assertFalse($this->send($m, $this->sample($m))['blocked']);
        $resumes = array_filter($this->pushes, fn ($push) => ($push['event'] ?? null) === 'sync.nodes'
            && in_array($node->id, array_column($push['data']['nodes'] ?? [], 'id'), true));
        $this->assertNotEmpty($resumes, '首次计量确认后必须通知 Node 恢复，不能依赖较晚的周期校对');
        $this->send($m, $this->sample($m, 80, 120));
        $this->configure($m, ['traffic_limit_gb' => 0]);
        $this->configure($m, ['traffic_limit_gb' => 1]);
        $this->assertSame(120, Traffic::view($m)['used']);
        $this->configure($m, ['traffic_limit_gb' => 0.0000000001]);
        $this->assertSame(1, Traffic::view($m)['limit']);
    }

    public function test_duplicate_out_of_order_old_epoch_and_old_period_reports_cannot_change_usage(): void
    {
        $m = $this->machine(); $this->configure($m, ['traffic_limit_gb' => 1, 'traffic_mode' => 'both']);
        $report = $this->sample($m, 40, 60);
        $this->assertSame(100, $this->send($m, $report)['used']);
        $this->assertSame(100, $this->send($m, $report)['used']);
        $later = $this->sample($m, 80, 120);
        $this->assertSame(200, $this->send($m, $later)['used']);
        foreach ([$report, [...$later, 'epoch' => $later['epoch'] - 1, 'sequence' => 99],
            [...$later, 'period_start' => $later['period_start'] - 86400, 'sequence' => 99]] as $stale) {
            $this->assertSame(200, $this->send($m, [...$stale, 'rx' => 9000, 'error' => 'state_lost'])['used']);
        }
        $this->assertSame(200, $this->send($m, $this->sample($m, 1, 2))['used']);
    }

    public function test_exceeded_machine_preserves_manual_switches_statistics_and_management(): void
    {
        $m = $this->machine(); $on = $this->node($m, ['u' => 100, 'd' => 200, 'transfer_enable' => 1]);
        $off = $this->node($m, ['name' => '管理员关闭', 'enabled' => false, 'show' => false]);
        $this->configure($m, ['traffic_limit_gb' => 1]);
        $this->assertTrue($this->send($m, $this->sample($m, 50 * Traffic::GB, Traffic::GB))['blocked']);
        $this->assertCount(0, ServerService::getMachineNodes($m->fresh()));
        $this->assertTrue($on->fresh()->enabled); $this->assertFalse($off->fresh()->enabled);
        $this->assertSame(100, $on->fresh()->u); $this->assertSame(200, $on->fresh()->d);
        $this->postJson('/api/v2/server/machine/nodes', ['machine_id' => $m->id, 'token' => $m->token])->assertOk();
        $this->configure($m, ['traffic_limit_gb' => 2]);
        $this->assertSame([$on->id], ServerService::getMachineNodes($m->fresh())->pluck('id')->all());
        $user = new User(['group_id' => 1, 'uuid' => (string) Str::uuid()]);
        $this->assertSame([$on->id], array_column(ServerService::getAvailableServers($user), 'id'));
        $this->assertSame(1, $on->fresh()->transfer_enable);
    }

    public function test_landing_protection_keeps_explicit_blocked_route_and_recovers_without_direct_fallback(): void
    {
        $entryMachine = $this->machine(); $landingMachine = $this->machine();
        $entry = $this->node($entryMachine);
        $child = $this->node($landingMachine, ['relay_entry_id' => $entry->id, 'vless_route' => 2002]);
        $this->configure($landingMachine, ['traffic_limit_gb' => 1]);
        $this->send($landingMachine, $this->sample($landingMachine, 0, Traffic::GB));
        $config = ServerService::buildNodeConfig($entry);
        $this->assertSame('entry', $config['relay']['mode']);
        $this->assertSame([], $config['relay']['children']);
        $this->assertSame([ServerRelayService::ensureRouteId($child->fresh())], $config['relay']['blocked_route_ids']);
        $this->configure($landingMachine, ['traffic_limit_gb' => 2]);
        $this->assertSame([$child->id], ServerRelayService::childrenOf($entry)->pluck('id')->all());
        $this->assertSame([], ServerRelayService::blockedWireGuardRoutes($entry));
    }

    public function test_monthly_boundary_short_month_leap_year_and_timezone(): void
    {
        foreach ([['2026-02-28 00:00:00', '2026-02-28', '2026-03-31'],
            ['2028-02-29 00:00:00', '2028-02-29', '2028-03-31'],
            ['2026-02-27 23:59:59', '2026-01-31', '2026-02-28']] as [$now, $start, $end]) {
            [$a, $b] = Traffic::period(31, CarbonImmutable::parse($now, 'Asia/Shanghai'));
            $this->assertSame(CarbonImmutable::parse($start, 'Asia/Shanghai')->timestamp, $a);
            $this->assertSame(CarbonImmutable::parse($end, 'Asia/Shanghai')->timestamp, $b);
        }
        $m = $this->machine(); $this->configure($m, ['traffic_limit_gb' => 1, 'traffic_reset_day' => 31]);
        $old = $this->sample($m, 0, Traffic::GB); $this->send($m, $old);
        $this->travelTo(CarbonImmutable::parse('2026-10-31 00:00:00', 'Asia/Shanghai'));
        $p = $this->send($m, $this->sample($m, 10, 20));
        $this->assertSame(20, $p['used']); $this->assertFalse($p['blocked']);
        $this->assertSame(20, $this->send($m, [...$old, 'sequence' => 999])['used']);
    }

    public function test_invalid_input_authentication_and_meter_failure_do_not_silently_enable_proxies(): void
    {
        $m = $this->machine();
        foreach ([['traffic_limit_gb' => -1], ['traffic_reset_day' => 0], ['traffic_reset_day' => 32],
            ['traffic_mode' => 'upload'], ['traffic_interfaces' => ['../eth0']]] as $params) {
            $this->postJson('/_tests/machine-traffic/save', ['id' => $m->id, 'name' => $m->name] + $params)->assertUnprocessable();
        }
        $this->configure($m, ['traffic_limit_gb' => 1]);
        $this->postJson('/api/v2/server/machine/traffic', ['machine_id' => $m->id, 'token' => Str::random(32)])->assertForbidden();
        $this->postJson('/api/v2/server/machine/traffic', ['machine_id' => $m->id, 'token' => $m->token, 'sample' => []])->assertUnprocessable();
        $this->assertTrue($this->send($m, $this->sample($m, 0, 0, ['error' => 'interfaces_unavailable']))['blocked']);
        $this->assertFalse($this->send($m, $this->sample($m))['blocked']);
        $this->travel(31)->seconds();
        $this->assertTrue(Traffic::blocked($m->fresh()));
        $this->assertFalse($this->send($m, $this->sample($m))['blocked']);
    }

    public function test_failed_write_rolls_back_usage_and_same_report_can_be_retried(): void
    {
        $m = $this->machine(); $this->configure($m, ['traffic_limit_gb' => 1]);
        $report = $this->sample($m, 30, 50);
        $this->failNextQueryMatching('/update.*v2_server_machine/i');
        try {
            Traffic::exchange($m->id, $report);
            $this->fail('计量写入失败应回滚');
        } catch (\Illuminate\Database\QueryException) {
            $this->assertSame(0, Traffic::view($m->fresh())['used']);
        }
        $this->assertSame(50, Traffic::exchange($m->id, $report)['used']);
        $this->assertSame(50, Traffic::exchange($m->id, $report)['used']);
    }

    private function proxyHeartbeat(ServerMachine $machine, array $extra = []): array
    {
        $p = Traffic::view($machine->fresh());
        return $this->send($machine, $this->sample($machine, 999999, 999999, $extra + [
            'mode' => 'proxy', 'revision' => $p['revision'],
        ]));
    }

    public function test_proxy_counts_all_managed_nodes_raw_and_deduplicates_same_machine_relay_before_billing(): void
    {
        Bus::fake([ProcessNodeReportBatch::class]);
        $a = $this->machine(); $b = $this->machine();
        $entry = $this->node($a, ['rate' => 3]);
        $same = $this->node($a, ['relay_entry_id' => $entry->id]);
        $remote = $this->node($b, ['relay_entry_id' => $entry->id]);
        $other = $this->node($a, ['rate' => 0]);
        foreach ([$a, $b] as $machine) {
            $this->configure($machine, ['traffic_limit_gb' => 1, 'traffic_mode' => 'proxy', 'traffic_interfaces' => ['missing0']]);
            $this->assertFalse($this->proxyHeartbeat($machine)['blocked']);
        }
        $a->update(['load_status' => ['network' => ['sent' => Traffic::GB, 'received' => Traffic::GB]]]);
        $reports = app(NodeReportService::class);
        $relay = [$same->id => [20, 30], $remote->id => [10, 40]];
        $batch = $reports->accept($entry, 'proxy-dedup', [1 => [100, 400]], $relay, [1 => $relay]);
        $this->assertSame($batch->id, $reports->accept($entry, 'proxy-dedup', [1 => [100, 400]], $relay, [1 => $relay])->id);
        $reports->accept($other, 'proxy-other', [1 => [20, 30]], []);
        $this->assertSame(550, Traffic::view($a->fresh())['used']);
        $this->assertSame(50, Traffic::view($b->fresh())['used']);
        $this->assertSame(0, $a->fresh()->proxy_u, '保护不能依赖扣费队列处理');
        $p = $this->proxyHeartbeat($a);
        $this->assertSame(550, $p['used']);
        $this->assertSame([], $p['measured_interfaces']);
        $this->assertSame(0, $p['rx']);
        $this->assertSame(0, $p['tx']);
    }

    public function test_proxy_mode_switch_preserves_both_sources_and_rejects_old_source_reports(): void
    {
        Bus::fake([ProcessNodeReportBatch::class]);
        $m = $this->machine(); $node = $this->node($m);
        $this->configure($m, ['traffic_limit_gb' => 1]);
        $this->send($m, $this->sample($m, 40, 60));
        app(NodeReportService::class)->accept($node, 'count-in-background', [1 => [20, 30]], []);
        $old = $this->sample($m, 1000, 2000);
        $this->configure($m, ['traffic_mode' => 'proxy']);
        $this->assertSame(50, Traffic::view($m)['used']);
        $this->assertTrue($this->send($m, $old)['blocked']);
        $heartbeat = $this->proxyHeartbeat($m);
        $this->assertFalse($heartbeat['blocked']);
        $this->assertSame(50, $heartbeat['used']);
        $oldProxy = $this->sample($m, 9000, 9000, ['mode' => 'proxy', 'revision' => $heartbeat['revision']]);
        $this->configure($m, ['traffic_mode' => 'both']);
        $this->assertSame(100, Traffic::view($m)['used']);
        $this->assertTrue($this->send($m, $oldProxy)['blocked']);
        $this->assertFalse($this->send($m, $this->sample($m, 40, 60))['blocked']);
        $this->configure($m, ['traffic_limit_gb' => 0]);
        $this->configure($m, ['traffic_limit_gb' => 1, 'traffic_mode' => 'proxy']);
        $this->assertSame(50, $this->proxyHeartbeat($m)['used']);
    }

    public function test_proxy_limit_recovery_and_monthly_reset_do_not_replay_old_reports(): void
    {
        Bus::fake([ProcessNodeReportBatch::class]);
        $m = $this->machine(); $on = $this->node($m);
        $off = $this->node($m, ['enabled' => false]);
        $this->configure($m, ['traffic_limit_gb' => 1, 'traffic_mode' => 'proxy', 'traffic_reset_day' => 31]);
        $this->proxyHeartbeat($m);
        $reports = app(NodeReportService::class);
        $reports->accept($on, 'proxy-over-limit', [1 => [Traffic::GB, 0]], []);
        $this->assertTrue(Traffic::view($m->fresh())['blocked']);
        $this->assertCount(0, ServerService::getMachineNodes($m->fresh()));
        $this->assertTrue($on->fresh()->enabled); $this->assertFalse($off->fresh()->enabled);
        $this->configure($m, ['traffic_limit_gb' => 2, 'traffic_reset_day' => 30]);
        $this->assertSame(Traffic::GB, Traffic::view($m)['used']);
        $this->assertFalse(Traffic::blocked($m));
        $this->assertSame([$on->id], ServerService::getMachineNodes($m)->pluck('id')->all());
        $this->travelTo(CarbonImmutable::parse('2026-10-30 00:00:00', 'Asia/Shanghai'));
        $reports->accept($on, 'proxy-over-limit', [1 => [Traffic::GB, 0]], []);
        $this->assertSame(0, Traffic::view($m->fresh())['used']);
        $reports->accept($on, 'proxy-new-period', [1 => [10, 20]], []);
        $this->assertSame(30, $this->proxyHeartbeat($m)['used']);
    }

    public function test_proxy_receipt_failure_rolls_back_batch_and_counters_then_retries_once(): void
    {
        Bus::fake([ProcessNodeReportBatch::class]);
        $m = $this->machine(); $node = $this->node($m);
        $reports = app(NodeReportService::class);
        $reports->accept($node, 'before-protection', [1 => [100, 200]], []);
        $this->configure($m, ['traffic_limit_gb' => 1, 'traffic_mode' => 'proxy']);
        $this->assertSame(0, Traffic::view($m)['used']);
        $this->failNextQueryMatching('/update.*v2_server_machine/i');
        try {
            $reports->accept($node, 'proxy-retry', [1 => [10, 20]], []);
            $this->fail('保护写入失败时不得确认报告');
        } catch (\Illuminate\Database\QueryException) {
            $this->assertSame(0, Traffic::view($m->fresh())['used']);
            $this->assertDatabaseMissing('v2_node_report_batch', ['report_id' => 'proxy-retry']);
        }
        for ($i = 0; $i < 2; $i++) {
            $reports->accept($node, 'proxy-retry', [1 => [10, 20]], []);
            $this->assertSame(30, Traffic::view($m->fresh())['used']);
        }
    }

    public function test_proxy_protection_uses_raw_counters_before_billing_plugin_changes(): void
    {
        Bus::fake([ProcessNodeReportBatch::class]);
        $m = $this->machine(); $node = $this->node($m);
        $this->configure($m, ['traffic_limit_gb' => 1, 'traffic_mode' => 'proxy']);
        \App\Services\Plugin\HookManager::registerFilter('traffic.process.before', function ($payload) {
            [$server, $protocol, $traffic] = $payload;
            foreach ($traffic as &$value) $value = [$value[0] * 10, $value[1] * 10];
            return [$server, $protocol, $traffic];
        });
        $batch = app(NodeReportService::class)->accept($node, 'plugin-adjusted', [1 => [10, 20]], []);
        $this->assertSame([100, 200], $batch->traffic[1]);
        $this->assertSame(30, Traffic::view($m->fresh())['used']);
    }
}
