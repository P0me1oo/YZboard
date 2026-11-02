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

    private function sample(Server $entry, Server $target, bool $healthy, int $rtt = 20, ?int $checked = null): void
    {
        $row = SchedulingProbeService::target($target);
        SchedulingProbeService::accept($entry, [['node_id' => $target->id, 'token' => $row['token'], 'healthy' => $healthy,
            'rtt_ms' => $rtt, 'checked_at' => $checked ?? now()->timestamp]]);
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
