<?php

namespace Tests\Feature\Server;

use App\Http\Controllers\V2\Admin\Server\ManageController;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Services\ServerService;
use App\Services\RelayFirewallService;
use App\Services\MachineAgentService;
use App\Models\BalancerPool;
use App\Models\BalancerMember;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tests\TestCase;

class RelayFirewallTest extends TestCase
{
    use RefreshDatabase;

    private function topology(): array
    {
        $machine = ServerMachine::create(['name' => '前置测试机', 'token' => Str::random(32), 'is_active' => true]);
        $landingMachine = ServerMachine::create(['name' => '落地测试机', 'token' => Str::random(32), 'is_active' => true]);
        $entry = Server::create(['name' => '前置', 'type' => 'vless', 'host' => 'entry.example.com',
            'port' => '24443', 'server_port' => 24443, 'machine_id' => $machine->id, 'enabled' => true, 'rate' => 1,
            'protocol_settings' => ['network' => 'tcp', 'tls' => 2, 'flow' => 'xtls-rprx-vision',
                'reality_settings' => ['server_name' => 'example.com', 'public_key' => 'test-only-public-key',
                    'private_key' => 'test-only-private-key', 'short_id' => 'abcd']]]);
        $landing = Server::create(['name' => '落地', 'type' => 'shadowsocks', 'host' => '192.0.2.8',
            'port' => '28388', 'server_port' => 28388, 'machine_id' => $landingMachine->id,
            'relay_entry_id' => $entry->id, 'enabled' => true, 'rate' => 1, 'protocol_settings' => ['cipher' => 'aes-128-gcm']]);
        return [$entry, $landing, $machine];
    }

    private function report(Server $entry, array $sources, bool $complete = true, ?array $targets = null): void
    {
        $targets ??= RelayFirewallService::targets($entry->fresh());
        ServerService::updateMetrics($entry, ['relay_egress' => ['version' => 1, 'checked_at' => now()->timestamp,
            'children' => array_map(fn ($target) => $target + ['sources' => $sources, 'complete' => $complete], $targets)]]);
    }

    // 只模拟认证回报，不建立网络连接；PHP 8.2 将文档 IPv6 网段视为保留地址。
    private function address(ServerMachine $machine, string ...$ips): void
    {
        foreach ($ips as $ip) MachineAgentService::recordAddress($machine, $ip);
    }

    public function test_single_entry_requires_verified_exact_sources_and_updates(): void
    {
        [$entry, $landing, $machine] = $this->topology();
        $this->assertSame('pending', RelayFirewallService::policy($landing)['status']);
        $this->address($machine, '198.51.100.1', '2001:4860:4860::1');
        $this->report($entry, ['198.51.100.1', '2001:4860:4860::1']);
        $policy = ServerService::buildNodeConfig($landing)['relay']['firewall'];
        $this->assertSame('ready', $policy['status']);
        $this->assertSame(['198.51.100.1', '2001:4860:4860::1'], $policy['sources']);
        $this->assertSame(now()->timestamp + 120, $policy['valid_until']);
        $this->address($machine, '198.51.100.2');
        $this->assertSame('pending', RelayFirewallService::policy($landing)['status']);
        $this->travel(1)->seconds();
        $this->report($entry, ['198.51.100.2', '2001:4860:4860::1']);
        $this->assertSame(['198.51.100.2', '2001:4860:4860::1'], RelayFirewallService::policy($landing)['sources']);
    }

    public function test_all_pool_members_required_and_removal_recomputes_union(): void
    {
        [$first, $landing, $machine] = $this->topology();
        [$second, , $otherMachine] = $this->topology();
        $pool = BalancerPool::create(['name' => '双栈前置池', 'mode' => 'dns', 'enabled' => true,
            'domain' => 'pool.example.test', 'gateway_port' => 24443, 'template_config' => [],
            'gateway_machine_id' => 0, 'gateway_address' => '', 'backend_port' => 24443,
            'protocol' => 'vless', 'template_node_id' => $first->id]);
        foreach ([$first, $second] as $entry) BalancerMember::create(['pool_id' => $pool->id,
            'source_node_id' => $entry->id, 'runtime_node_id' => $entry->id, 'priority' => 1, 'weight' => 1]);
        $landing->update(['relay_entry_id' => null, 'relay_pool_id' => $pool->id]);
        $this->address($machine, '198.51.100.1', '2001:4860:4860::1');
        $this->address($otherMachine, '198.51.100.2', '2001:4860:4860::2');
        $this->report($first, ['198.51.100.1', '2001:4860:4860::1']);
        $this->assertSame('pending', RelayFirewallService::policy($landing)['status']);
        $this->report($second, ['198.51.100.2', '2001:4860:4860::2']);
        $this->assertSame(['198.51.100.1', '198.51.100.2', '2001:4860:4860::1', '2001:4860:4860::2'], RelayFirewallService::policy($landing)['sources']);
        // DNS 未发布及健康未知的成员仍在名单；显式移除成员才缩小。
        BalancerMember::where('pool_id', $pool->id)->where('runtime_node_id', $second->id)->delete();
        $this->assertSame(['198.51.100.1', '2001:4860:4860::1'], RelayFirewallService::policy($landing)['sources']);
    }

    public function test_partial_nat_invalid_or_stale_reports_never_publish_partial_allowlist(): void
    {
        [$entry, $landing, $machine] = $this->topology();
        $this->address($machine, '198.51.100.1', '2001:4860:4860::1');
        foreach ([[['198.51.100.1'], false], [['198.51.100.1', '10.0.0.1'], true],
            [['198.51.100.1', '2001:4860:4860::9'], true], [['198.51.100.0/24'], true], [[], true]] as [$sources, $complete]) {
            $this->travel(1)->seconds();
            $this->report($entry, $sources, $complete);
            $this->assertSame('pending', RelayFirewallService::policy($landing)['status']);
            $this->assertSame([], RelayFirewallService::policy($landing)['sources']);
        }
        $this->travel(1)->seconds();
        $this->report($entry, ['198.51.100.1']);
        $this->assertSame('ready', RelayFirewallService::policy($landing)['status']);
        $this->travel(121)->seconds();
        $this->assertSame('pending', RelayFirewallService::policy($landing)['status']);
    }

    public function test_binding_target_or_machine_changes_invalidate_previous_report(): void
    {
        [$entry, $landing, $machine] = $this->topology();
        $this->address($machine, '198.51.100.1');
        $targets = RelayFirewallService::targets($entry->fresh());
        $this->report($entry, ['198.51.100.1']);
        $landing->update(['port' => '28389']);
        $this->assertSame('pending', RelayFirewallService::policy($landing)['status']);
        $this->travel(1)->seconds();
        $this->report($entry, ['198.51.100.1'], targets: $targets);
        $this->assertSame('pending', RelayFirewallService::policy($landing)['status']);
        $this->travel(1)->seconds();
        $this->report($entry, ['198.51.100.1']);
        $this->assertSame('ready', RelayFirewallService::policy($landing)['status']);
        $runtime = $machine->fresh()->agent_runtime;
        $machine->update(['agent_runtime' => $runtime + ['boot_id' => 'new-process']]);
        $this->assertSame('pending', RelayFirewallService::policy($landing)['status']);
    }

    public function test_out_of_order_and_unbound_reports_cannot_replace_verified_sources(): void
    {
        [$entry, $landing, $machine] = $this->topology();
        $this->address($machine, '198.51.100.1');
        $this->report($entry, ['198.51.100.1']);
        $this->travel(-1)->seconds();
        $this->report($entry, ['198.51.100.9']);
        $this->assertSame(['198.51.100.1'], RelayFirewallService::policy($landing)['sources']);
        [$unbound] = $this->topology();
        $this->report($unbound, ['198.51.100.9'], targets: RelayFirewallService::targets($entry));
        $this->assertSame(['198.51.100.1'], RelayFirewallService::policy($landing)['sources']);
    }

    public function test_port_conflicts_manual_mode_and_warnings_are_preserved(): void
    {
        [$entry, $landing, $machine] = $this->topology();
        $this->address($machine, '198.51.100.1');
        $this->report($entry, ['198.51.100.1']);
        $other = $landing->replicate();
        $other->relay_entry_id = null;
        $other->save();
        $this->assertSame('pending', RelayFirewallService::policy($landing)['status']);
        $other->delete();
        $landing->update(['auto_firewall' => false]);
        $this->assertSame('pending', RelayFirewallService::policy($landing)['status']);
        $this->assertFalse(ServerService::buildNodeConfig($landing)['auto_firewall']);
        ServerService::updateMetrics($landing, ['firewall_warning' => '核对失败，保留原规则']);
        $this->assertSame('核对失败，保留原规则', $landing->fresh()->metrics['firewall_warning']);
        ServerService::updateMetrics($landing, []);
        $this->assertArrayNotHasKey('firewall_warning', $landing->fresh()->metrics);
    }

    public function test_new_reports_and_periodic_refresh_push_full_current_policy(): void
    {
        [$entry, $landing, $machine] = $this->topology();
        $messages = [];
        Redis::shouldReceive('publish')->andReturnUsing(function ($channel, $data) use (&$messages) {
            $messages[] = json_decode($data, true); return 1;
        });
        Cache::put('node_ws_alive:' . $landing->id, true);
        $this->address($machine, '198.51.100.1');
        $this->report($entry, ['198.51.100.1']);
        $this->assertSame('ready', collect($messages)->last()['data']['config']['relay']['firewall']['status']);
        $this->travel(121)->seconds();
        $this->artisan('relay:sync-firewall')->assertSuccessful();
        $this->assertSame('pending', collect($messages)->last()['data']['config']['relay']['firewall']['status']);
    }
}
