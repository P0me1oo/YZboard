<?php

namespace Tests\Feature\Server;

use App\Models\BalancerDnsPreset;
use App\Models\LandingPool;
use App\Models\SchedulingEvent;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Services\DnsPoolService;
use App\Services\FrontPoolScheduler;
use App\Services\FrontPortProbe;
use App\Services\LandingPoolService;
use App\Services\LoadBalancerService;
use App\Services\PowerDnsService;
use App\Services\SchedulingProbeService;
use App\Services\ServerRelayService;
use App\Services\ServerService;
use App\Utils\CacheKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SchedulingTest extends TestCase
{
    use RefreshDatabase;

    private function front(string $name, string $ip): Server
    {
        $machine = ServerMachine::create(['name' => $name, 'token' => Str::random(32), 'is_active' => true, 'agent_runtime' => ['version' => 'v2.30.0']]);
        $node = Server::create(['name' => $name, 'machine_id' => $machine->id, 'host' => $ip, 'type' => 'vless', 'kernel_type' => 'xray',
            'port' => '443', 'server_port' => 443, 'enabled' => true, 'show' => true, 'rate' => 1, 'group_ids' => [1],
            'protocol_settings' => ['network' => 'tcp', 'tls' => 2, 'reality_settings' => ['private_key' => str_repeat('a', 43),
                'public_key' => str_repeat('b', 43), 'short_id' => 'ab12', 'server_name' => 'handshake.example.test', 'server_port' => 443]]]);
        $this->heartbeat($node);
        return $node;
    }

    private function heartbeat(Server $node): void
    {
        Cache::put(CacheKey::get('SERVER_' . strtoupper($node->type) . '_LAST_CHECK_AT', $node->id), now()->timestamp, 3600);
    }

    private function pool(): array
    {
        $a = $this->front('前置 A', '192.0.2.1'); $b = $this->front('前置 B', '192.0.2.2');
        $preset = BalancerDnsPreset::create(['name' => '测试 DNS', 'domain' => 'example.test', 'provider' => 'powerdns',
            'zone_id' => str_repeat('a', 32), 'api_token' => 'test-only-token', 'api_url' => 'https://dns.example.test', 'server_id' => 'localhost']);
        $pool = app(LoadBalancerService::class)->save(['mode' => 'dns', 'name' => '测试前置池', 'domain' => 'pool.example.test',
            'dns_preset_id' => $preset->id, 'enabled' => true, 'members' => [['node_id' => $a->id, 'priority' => 1], ['node_id' => $b->id, 'priority' => 1]]]);
        return [$pool, $a, $b];
    }

    private function landing(Server $entry, string $ip, string $name = '落地'): Server
    {
        $machine = ServerMachine::create(['name' => $name, 'token' => Str::random(32), 'is_active' => true]);
        $node = Server::create(['name' => $name, 'machine_id' => $machine->id, 'host' => $ip, 'type' => 'shadowsocks', 'kernel_type' => 'xray',
            'port' => '20001', 'server_port' => 20001, 'enabled' => true, 'show' => true, 'rate' => 1, 'group_ids' => [1],
            'relay_entry_id' => $entry->id, 'protocol_settings' => ['cipher' => 'aes-128-gcm']]);
        $this->heartbeat($node);
        return $node;
    }

    public function test_cycle_prefers_the_nearest_reset_and_uses_unlimited_members_only_as_fallback(): void
    {
        [$pool, $a, $b] = $this->pool();
        $pool->update(['scheduling_config' => ['strategy' => 'cycle']]);
        $a->machine->update(['traffic_reset_day' => 1, 'traffic_limit' => 1000, 'traffic_state' => ['initialized' => true, 'reported_at' => now()->timestamp, 'tx' => 100]]);
        $b->machine->update(['traffic_reset_day' => 15, 'traffic_limit' => 1000, 'traffic_state' => ['initialized' => true, 'reported_at' => now()->timestamp, 'tx' => 100]]);
        $aEnd = \App\Services\MachineTrafficService::view($a->machine->fresh())['period_end'];
        $bEnd = \App\Services\MachineTrafficService::view($b->machine->fresh())['period_end'];
        $early = $aEnd < $bEnd ? $a : $b;
        $late = $aEnd < $bEnd ? $b : $a;
        $select = fn () => app(FrontPoolScheduler::class)->select($pool->fresh(['members.runtimeNode.machine', 'members.sourceNode.machine']))[0];
        $this->assertSame([$early->host], $select());
        $early->machine->update(['traffic_state' => ['initialized' => true, 'reported_at' => now()->timestamp, 'tx' => 1000]]);
        $this->assertSame([$late->host], $select());
        $early->machine->update(['traffic_limit' => 0]);
        $this->assertSame([$late->host], $select());
        $late->update(['enabled' => false]);
        $this->assertSame([$early->host], $select());
    }

    public function test_balanced_latency_keeps_close_members_and_excludes_slow_or_failed_paths(): void
    {
        [$pool, $a, $b] = $this->pool();
        $target = $this->landing($a, '192.0.2.3'); $target->update(['relay_entry_id' => null, 'relay_pool_id' => $pool->id]);
        $pool->update(['scheduling_config' => ['strategy' => 'latency_balanced', 'latency_node_id' => $target->id]]);
        $this->sample($a, $target, true, 50); $this->sample($b, $target, true, 55);
        $select = fn () => app(FrontPoolScheduler::class)->select($pool->fresh(['members.runtimeNode.machine', 'members.sourceNode.machine']))[0];
        $this->assertCount(2, $select());
        $this->travel(15)->seconds(); $this->sample($b, $target, true, 200);
        $this->assertCount(2, $select());
        $this->assertCount(2, $select());
        $this->travel(15)->seconds(); $this->sample($b, $target, true, 200);
        $this->assertSame([$a->host], $select());
        $this->travel(15)->seconds(); $this->sample($b, $target, false);
        $this->assertSame([$a->host], $select());
    }

    private function sample(Server $entry, Server $target, bool $healthy, int $rtt = 20, ?int $checked = null): void
    {
        $row = SchedulingProbeService::target($target);
        SchedulingProbeService::accept($entry, [['node_id' => $target->id, 'token' => $row['token'], 'healthy' => $healthy,
            'rtt_ms' => $rtt, 'checked_at' => $checked ?? now()->timestamp]]);
    }

    private array $liteNodes = [];
    private array $liteStates = [];
    private bool $liteFailed = false;

    private function lite(Server ...$nodes): void
    {
        \App\Models\LiteConnection::create(['id' => 1, 'url' => 'https://lite.example.test', 'api_key' => Str::random(32), 'enabled' => true]);
        foreach ($nodes as $index => $node) {
            $uuid = (string) Str::uuid();
            \Illuminate\Support\Facades\DB::table('v2_lite_machine')->insert(['machine_id' => $node->machine_id, 'uuid' => $uuid]);
            $this->liteNodes[$node->id] = ['uuid' => $uuid, 'traffic_limit' => 500, 'effective_traffic_limit' => 1000,
                'traffic_limit_type' => 'sum', 'effective_traffic_type' => 'max', 'traffic_reset_day' => 31,
                'traffic_reset_time' => '15:30:00', 'traffic_reset_timezone' => 'America/Los_Angeles',
                'traffic_reset_at' => now()->addDays($index + 1)->toIso8601String()];
            $this->liteStates[$uuid] = ['online' => true, 'time' => now()->toIso8601String(), 'net_total_up' => 100, 'net_total_down' => 200];
        }
        $this->mock(\App\Services\LiteClient::class)->shouldReceive('read')->andReturnUsing(function ($connection, $method) {
            if ($this->liteFailed) throw new \App\Exceptions\ApiException('Lite 读取失败', 502);
            return match ($method) {
                'admin:listClients' => array_values($this->liteNodes),
                'common:getNodes' => collect($this->liteNodes)->keyBy('uuid')->all(),
                'common:getNodesLatestStatus' => $this->liteStates,
            };
        });
    }

    private function freshLite(Server ...$nodes): void
    {
        Cache::flush();
        foreach ($nodes as $node) $this->heartbeat($node);
    }

    public function test_lite_quota_and_timezone_are_used_by_all_four_strategies_without_mutating_protection(): void
    {
        [$pool, $a, $b] = $this->pool();
        $target = $this->landing($a, '192.0.2.3');
        $target->update(['relay_entry_id' => null, 'relay_pool_id' => $pool->id]);
        $this->lite($a, $b);
        $this->liteStates[$this->liteNodes[$a->id]['uuid']]['net_total_down'] = 950;
        $before = $a->machine->fresh()->getAttributes();
        foreach (['priority', 'latency', 'latency_balanced', 'cycle'] as $strategy) {
            $pool->update(['scheduling_config' => ['strategy' => $strategy, 'latency_node_id' => $target->id, 'quota_enabled' => true, 'quota_percent' => 10]]);
            $this->sample($a, $target, true, 10); $this->sample($b, $target, true, 50);
            [$addresses] = app(FrontPoolScheduler::class)->select($pool->fresh(['members.runtimeNode.machine', 'members.sourceNode.machine']));
            $this->assertSame([$b->host], $addresses, $strategy);
            $state = $pool->members()->where('source_node_id', $a->id)->first()->scheduling_state;
            $this->assertSame(5, $state['remaining_percent']);
            $this->assertSame(1000, $state['traffic_limit']);
            $this->assertSame(950, $state['traffic_used']);
            $this->assertSame('lite', $state['traffic_source']);
            $this->assertSame('America/Los_Angeles', $state['reset_timezone']);
            $this->assertSame(strtotime($this->liteNodes[$a->id]['traffic_reset_at']), $state['reset_at']);
        }
        $this->assertSame($before, $a->machine->fresh()->getAttributes());
        $pool->update(['enabled' => false]);
        [$addresses] = app(FrontPoolScheduler::class)->select($pool->fresh(['members.runtimeNode.machine', 'members.sourceNode.machine']));
        $this->assertSame([], $addresses);
        $this->assertSame('lite', $pool->members()->where('source_node_id', $a->id)->first()->scheduling_state['traffic_source']);
    }

    public function test_lite_cycle_uses_absolute_reset_time_and_exhaustion_falls_back_across_priorities(): void
    {
        [$pool, $a, $b] = $this->pool();
        $this->lite($a, $b);
        $pool->update(['scheduling_config' => ['strategy' => 'cycle']]);
        $this->liteNodes[$a->id]['traffic_reset_at'] = now()->addDays(2)->setTimezone('Asia/Tokyo')->toIso8601String();
        $this->liteNodes[$b->id]['traffic_reset_at'] = now()->addDay()->setTimezone('America/Los_Angeles')->toIso8601String();
        $select = fn () => app(FrontPoolScheduler::class)->select($pool->fresh(['members.runtimeNode.machine', 'members.sourceNode.machine']))[0];
        $this->assertSame([$b->host], $select());
        $pool->members()->where('source_node_id', $a->id)->update(['priority' => 2]);
        $this->liteStates[$this->liteNodes[$b->id]['uuid']]['net_total_down'] = 1100;
        $this->freshLite($a, $b);
        $this->assertSame([$a->host], $select());
        $this->liteNodes[$a->id]['effective_traffic_limit'] = 0;
        $this->freshLite($a, $b);
        $this->assertSame([$a->host], $select());
        $a->update(['enabled' => false]);
        $this->assertSame([$b->host], $select(), '没有其他健康入口时保留可用入口');
    }

    public function test_lite_quota_hysteresis_recovers_after_five_points_and_all_low_retains_the_best(): void
    {
        [$pool, $a, $b] = $this->pool(); $this->lite($a, $b);
        $pool->update(['scheduling_config' => ['quota_enabled' => true, 'quota_percent' => 10]]);
        $aUuid = $this->liteNodes[$a->id]['uuid']; $bUuid = $this->liteNodes[$b->id]['uuid'];
        $select = fn () => app(FrontPoolScheduler::class)->select($pool->fresh(['members.runtimeNode.machine', 'members.sourceNode.machine']))[0];
        $this->liteStates[$aUuid]['net_total_down'] = 950;
        $this->assertSame([$b->host], $select());
        $this->liteStates[$aUuid]['net_total_down'] = 880; $this->freshLite($a, $b);
        $this->assertSame([$b->host], $select());
        $this->liteStates[$aUuid]['net_total_down'] = 850; $this->freshLite($a, $b);
        $this->assertSame([$a->host, $b->host], $select());
        $this->liteStates[$aUuid]['net_total_down'] = 950; $this->liteStates[$bUuid]['net_total_down'] = 970;
        $this->freshLite($a, $b);
        $this->assertSame([$a->host], $select());
    }

    public function test_lite_failure_stale_and_missing_data_do_not_become_unlimited_or_break_port_failover(): void
    {
        [$pool, $a, $b] = $this->pool(); $this->lite($a, $b);
        $pool->update(['scheduling_config' => ['strategy' => 'cycle', 'quota_enabled' => true, 'health_enabled' => true]]);
        $this->mock(FrontPortProbe::class)->shouldReceive('check')->andReturn([
            $a->host => ['ok' => false], $b->host => ['ok' => true],
        ]);
        $select = fn () => app(FrontPoolScheduler::class)->select($pool->fresh(['members.runtimeNode.machine', 'members.sourceNode.machine']))[0];
        $this->liteFailed = true;
        $select(); $this->assertSame([$b->host], $select());
        $state = $pool->members()->where('source_node_id', $b->id)->first()->scheduling_state;
        $this->assertSame('unavailable', $state['traffic_status']);
        $this->assertNull($state['remaining_percent']); $this->assertNull($state['reset_at']);
        $this->liteFailed = false;
        $uuid = $this->liteNodes[$b->id]['uuid'];
        foreach ([['time' => now()->subMinutes(5)->toIso8601String()], ['online' => false], ['net_total_down' => null]] as $invalid) {
            $original = $this->liteStates[$uuid]; $this->liteStates[$uuid] = [...$original, ...$invalid];
            $this->freshLite($a, $b); $this->assertSame([$b->host], $select());
            $this->assertSame('unavailable', $pool->members()->where('source_node_id', $b->id)->first()->scheduling_state['traffic_status']);
            $this->liteStates[$uuid] = $original;
        }
        unset($this->liteNodes[$b->id]); $this->freshLite($a, $b);
        $this->assertSame([$b->host], $select());
        $b->update(['enabled' => false]);
        $this->assertSame([], $select(), 'Lite 故障不能恢复失败端口或停用节点');
    }

    public function test_lite_does_not_override_local_protection_and_disabling_lite_restores_local_quota(): void
    {
        [$pool, $a, $b] = $this->pool(); $this->lite($a);
        $pool->update(['scheduling_config' => ['quota_enabled' => true, 'quota_percent' => 10]]);
        $a->machine->update(['traffic_limit' => 1000, 'traffic_mode' => 'outbound', 'traffic_state' =>
            ['initialized' => true, 'error' => null, 'reported_at' => now()->timestamp, 'tx' => 950]]);
        $select = fn () => app(FrontPoolScheduler::class)->select($pool->fresh(['members.runtimeNode.machine', 'members.sourceNode.machine']))[0];
        $this->assertSame([$a->host, $b->host], $select());
        \App\Models\LiteConnection::whereKey(1)->update(['enabled' => false]);
        $this->assertSame([$b->host], $select());
        $this->assertSame('local', $pool->members()->where('source_node_id', $a->id)->first()->scheduling_state['traffic_source']);
        \App\Models\LiteConnection::whereKey(1)->update(['enabled' => true]);
        $a->machine->update(['traffic_state' => ['initialized' => true, 'error' => null, 'reported_at' => now()->timestamp, 'tx' => 1000]]);
        $this->assertSame([$b->host], $select());
    }

    public function test_lite_cycle_boundary_refreshes_quota_and_usage_without_waiting_for_cache_expiry(): void
    {
        [$pool, $a, $b] = $this->pool(); $this->lite($a, $b);
        $pool->update(['scheduling_config' => ['strategy' => 'cycle']]);
        $this->liteNodes[$a->id]['traffic_reset_at'] = now()->addSecond()->toIso8601String();
        $select = fn () => app(FrontPoolScheduler::class)->select($pool->fresh(['members.runtimeNode.machine', 'members.sourceNode.machine']))[0];
        $this->assertSame([$a->host], $select());
        $this->travel(2)->seconds();
        $this->liteNodes[$a->id]['traffic_reset_at'] = now()->addMonth()->toIso8601String();
        $this->liteNodes[$a->id]['effective_traffic_limit'] = 500;
        $this->liteStates[$this->liteNodes[$a->id]['uuid']]['net_total_down'] = 10;
        $this->assertSame([$b->host], $select());
        $state = $pool->members()->where('source_node_id', $a->id)->first()->scheduling_state;
        $this->assertSame(500, $state['traffic_limit']);
        $this->assertSame(100, $state['traffic_used']);
    }

    public function test_port_failures_and_recovery_are_confirmed_per_address(): void
    {
        [$pool] = $this->pool(); $pool->update(['scheduling_config' => ['health_enabled' => true]]);
        $this->mock(FrontPortProbe::class)->shouldReceive('check')->times(4)->andReturn(
            ['192.0.2.1' => ['ok' => false], '192.0.2.2' => ['ok' => true]],
            ['192.0.2.1' => ['ok' => false], '192.0.2.2' => ['ok' => true]],
            ['192.0.2.1' => ['ok' => true], '192.0.2.2' => ['ok' => true]],
            ['192.0.2.1' => ['ok' => true], '192.0.2.2' => ['ok' => true]]);
        $select = fn () => app(FrontPoolScheduler::class)->select($pool->fresh(['members.runtimeNode.machine', 'members.sourceNode.machine']))[0];
        $this->assertCount(2, $select()); $this->assertSame(['192.0.2.2'], $select());
        $this->assertSame(['192.0.2.2'], $select()); $this->assertCount(2, $select());
    }

    public function test_port_recovery_keeps_confirmation_across_offline_and_disabled_states(): void
    {
        [$pool, $a] = $this->pool(); $pool->update(['scheduling_config' => ['health_enabled' => true]]);
        $ok = false;
        $this->mock(FrontPortProbe::class)->shouldReceive('check')->andReturnUsing(function () use (&$ok) {
            return ['192.0.2.1' => ['ok' => $ok], '192.0.2.2' => ['ok' => true]];
        });
        $select = fn () => app(FrontPoolScheduler::class)->select($pool->fresh(['members.runtimeNode.machine', 'members.sourceNode.machine']))[0];
        foreach (['node', 'pool', 'address', 'protocol'] as $interruption) {
            $ok = false; $select(); $this->assertSame(['192.0.2.2'], $select());
            $original = $a->fresh()->protocol_settings;
            if ($interruption === 'node') $a->update(['enabled' => false]);
            if ($interruption === 'pool') $pool->update(['enabled' => false]);
            if ($interruption === 'address') $a->update(['host' => 'invalid/address']);
            if ($interruption === 'protocol') $a->update(['protocol_settings' => [...$original, 'flow' => 'xtls-rprx-vision']]);
            $select();
            $a->update(['enabled' => true, 'host' => '192.0.2.1', 'protocol_settings' => $original]);
            $pool->update(['enabled' => true]);
            $this->assertSame($interruption === 'pool' ? [] : ['192.0.2.2'], $select(), $interruption . '：仍失败的端口不能重新入选');
            $ok = true;
            $this->assertSame(['192.0.2.2'], $select(), $interruption . '：一次成功不足以恢复');
            $this->assertSame(['192.0.2.1', '192.0.2.2'], $select());
        }
    }

    public function test_latency_switch_requires_two_new_samples_and_a_material_improvement(): void
    {
        [$pool, $a, $b] = $this->pool(); $target = $this->landing($a, '192.0.2.3'); $target->update(['relay_entry_id' => null, 'relay_pool_id' => $pool->id]);
        $pool->update(['scheduling_config' => ['strategy' => 'latency', 'latency_node_id' => $target->id]]);
        $select = function () use ($pool) { [$addresses, $state] = app(FrontPoolScheduler::class)->select($pool->fresh(['members.runtimeNode.machine', 'members.sourceNode.machine'])); $pool->update(['scheduling_state' => $state]); return $addresses; };
        $this->sample($a, $target, true, 30); $this->sample($b, $target, true, 50);
        $this->assertSame(['192.0.2.1'], $select());
        $this->travel(15)->seconds(); $this->sample($b, $target, true, 10);
        $this->assertSame(['192.0.2.1'], $select()); $this->assertSame(['192.0.2.1'], $select());
        $this->travel(15)->seconds(); $this->sample($b, $target, true, 10);
        $this->assertSame(['192.0.2.2'], $select());
    }

    public function test_quota_drain_uses_a_healthy_backup_and_does_not_create_an_artificial_outage(): void
    {
        [$pool, $a, $b] = $this->pool(); $pool->update(['scheduling_config' => ['quota_enabled' => true, 'quota_percent' => 10]]);
        foreach ([$a, $b] as $index => $node) $node->machine->update(['traffic_mode' => 'outbound', 'traffic_limit' => 1000, 'traffic_state' =>
            ['initialized' => true, 'error' => null, 'reported_at' => now()->timestamp, 'tx' => $index === 0 ? 950 : 100]]);
        $select = fn () => app(FrontPoolScheduler::class)->select($pool->fresh(['members.runtimeNode.machine', 'members.sourceNode.machine']))[0];
        $this->assertSame(['192.0.2.2'], $select());
        $b->machine->update(['traffic_state' => ['initialized' => true, 'error' => null, 'reported_at' => now()->timestamp, 'tx' => 970]]);
        $this->assertSame(['192.0.2.1'], $select());
    }

    public function test_dns_failures_do_not_record_an_unapplied_switch_and_retries_are_idempotent(): void
    {
        [$pool] = $this->pool();
        $dns = $this->mock(PowerDnsService::class);
        $dns->shouldReceive('reconcile')->once()->andThrow(new \RuntimeException('test failure'));
        $dns->shouldReceive('reconcile')->twice()->andReturnNull();
        try { app(DnsPoolService::class)->sync($pool); $this->fail('expected error'); } catch (\RuntimeException) {}
        $this->assertSame(0, SchedulingEvent::count());
        app(DnsPoolService::class)->sync($pool->fresh()); app(DnsPoolService::class)->sync($pool->fresh());
        $this->assertSame(1, SchedulingEvent::count());
    }

    public function test_landing_failover_keeps_logical_identity_and_uses_the_backup_credential(): void
    {
        $entry = $this->front('前置', '192.0.2.1'); $a = $this->landing($entry, '192.0.2.2', '香港 A'); $b = $this->landing($entry, '192.0.2.3', '香港 B');
        $pool = app(LandingPoolService::class)->save(['name' => '香港主备', 'node_id' => $a->id, 'enabled' => true, 'members' => [$a->id, $b->id]]);
        $this->sample($entry, $a, false); $this->sample($entry, $b, true);
        app(LandingPoolService::class)->sync($pool);
        $this->assertSame($b->id, LandingPoolService::targetFor($a, $entry)?->id);
        $config = ServerService::buildNodeConfig($entry);
        $outbound = collect($config['relay']['children'])->firstWhere('node_id', $a->id);
        $this->assertSame('192.0.2.3', $outbound['address']);
        $this->assertSame(ServerRelayService::outboundTag($a->id), $outbound['tag']);
        $this->assertSame(ServerRelayService::ensureRouteId($a), $outbound['route_id']);
        $this->assertSame(ServerRelayService::transitCredential($b)['password'], $outbound['password']);
        $this->assertSame('192.0.2.2', $a->fresh()->host);
        $this->travel(15)->seconds(); $this->sample($entry, $a, true); app(LandingPoolService::class)->sync($pool);
        $this->assertSame($a->id, LandingPoolService::targetFor($a, $entry)?->id);
    }

    public function test_all_landings_unavailable_block_the_route_instead_of_direct_exit(): void
    {
        $entry = $this->front('前置', '192.0.2.1'); $a = $this->landing($entry, '192.0.2.2'); $b = $this->landing($entry, '192.0.2.3');
        $pool = app(LandingPoolService::class)->save(['name' => '主备', 'node_id' => $a->id, 'enabled' => true, 'members' => [$a->id, $b->id]]);
        $this->sample($entry, $a, false); $this->sample($entry, $b, false); app(LandingPoolService::class)->sync($pool);
        $config = ServerService::buildNodeConfig($entry);
        $this->assertContains(ServerRelayService::ensureRouteId($a), $config['relay']['blocked_route_ids']);
        $this->assertNull(LandingPoolService::targetFor($a, $entry));
    }

    public function test_report_rejects_foreign_targets_old_tokens_and_older_samples(): void
    {
        [$pool, $a, $b] = $this->pool(); $target = $this->landing($a, '192.0.2.3'); $target->update(['relay_entry_id' => null, 'relay_pool_id' => $pool->id]);
        $pool->update(['scheduling_config' => ['strategy' => 'latency', 'latency_node_id' => $target->id]]);
        $this->sample($a, $target, true, 40);
        $this->sample($a, $target, false, 0, now()->timestamp - 1);
        $this->assertTrue(SchedulingProbeService::result($a, $target)['healthy']);
        $target->update(['port' => 20002]);
        $this->assertNull(SchedulingProbeService::result($a, $target));
        $foreign = $this->front('无关入口', '192.0.2.9'); $this->sample($foreign, $target, true);
        $this->assertNull(SchedulingProbeService::result($foreign, $target));
    }

    public function test_landing_members_must_share_the_same_frontend_binding(): void
    {
        $a = $this->front('前置 A', '192.0.2.1'); $b = $this->front('前置 B', '192.0.2.2');
        $primary = $this->landing($a, '192.0.2.3'); $foreign = $this->landing($b, '192.0.2.4');
        $this->expectException(ValidationException::class);
        app(LandingPoolService::class)->save(['name' => '非法跨入口', 'node_id' => $primary->id, 'enabled' => true, 'members' => [$primary->id, $foreign->id]]);
    }

    public function test_stale_measurements_close_the_managed_route_and_manual_disable_does_not_use_a_backup(): void
    {
        $entry = $this->front('前置', '192.0.2.1'); $a = $this->landing($entry, '192.0.2.2'); $b = $this->landing($entry, '192.0.2.3');
        $pool = app(LandingPoolService::class)->save(['name' => '主备', 'node_id' => $a->id, 'enabled' => true, 'members' => [$a->id, $b->id]]);
        $this->sample($entry, $a, true); $this->sample($entry, $b, true); app(LandingPoolService::class)->sync($pool);
        $this->assertSame($a->id, LandingPoolService::targetFor($a, $entry)?->id);
        $this->travel(120)->seconds(); app(LandingPoolService::class)->sync($pool);
        $this->assertNull(LandingPoolService::targetFor($a, $entry));
        $this->sample($entry, $a, false); $this->sample($entry, $b, true); app(LandingPoolService::class)->sync($pool);
        $this->assertSame($b->id, LandingPoolService::targetFor($a, $entry)?->id);
        $a->update(['enabled' => false]); app(LandingPoolService::class)->sync($pool);
        $this->assertNull(LandingPoolService::targetFor($a, $entry));
        $this->assertSame([], $pool->fresh()->state['active']);
    }

    public function test_primary_machine_quota_does_not_remove_a_line_with_a_usable_backup(): void
    {
        $entry = $this->front('前置', '192.0.2.1'); $a = $this->landing($entry, '192.0.2.2'); $b = $this->landing($entry, '192.0.2.3');
        $pool = app(LandingPoolService::class)->save(['name' => '主备', 'node_id' => $a->id, 'enabled' => true, 'members' => [$a->id, $b->id]]);
        $a->machine->update(['traffic_limit' => 1000, 'traffic_state' => ['initialized' => true, 'error' => null, 'reported_at' => now()->timestamp, 'tx' => 1000]]);
        $this->sample($entry, $b, true); app(LandingPoolService::class)->sync($pool);
        $user = \App\Models\User::create(['email' => 'scheduling-test@example.test', 'uuid' => (string) Str::uuid(), 'token' => Str::random(32),
            'password' => password_hash('scheduling-test-only', PASSWORD_BCRYPT),
            'group_id' => 1, 'banned' => false, 'transfer_enable' => 1000, 'expired_at' => now()->timestamp + 3600]);
        $line = collect(ServerService::getAvailableServers($user))->firstWhere('id', $a->id);
        $this->assertNotNull($line);
        $this->assertSame($a->name, $line['name']);
        $this->assertSame($entry->host, $line['host']);
    }

    public function test_old_agents_cannot_enable_landing_failover(): void
    {
        $entry = $this->front('旧前置', '192.0.2.1'); $entry->machine->update(['agent_runtime' => ['version' => 'v2.29.0']]);
        $a = $this->landing($entry, '192.0.2.2'); $b = $this->landing($entry, '192.0.2.3');
        $this->expectException(ValidationException::class);
        app(LandingPoolService::class)->save(['name' => '主备', 'node_id' => $a->id, 'enabled' => true, 'members' => [$a->id, $b->id]]);
    }

    public function test_live_front_port_probe_distinguishes_a_listener_from_a_refused_port(): void
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $this->assertIsResource($listener);
        $port = (int) substr(strrchr(stream_socket_get_name($listener, false), ':'), 1);
        $this->assertTrue(app(FrontPortProbe::class)->check(['127.0.0.1'], $port)['127.0.0.1']['ok']);
        fclose($listener);
        $this->assertFalse(app(FrontPortProbe::class)->check(['127.0.0.1'], $port)['127.0.0.1']['ok']);
    }
}
