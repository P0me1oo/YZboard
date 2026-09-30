<?php

namespace Tests\Feature\Server;

use App\Http\Controllers\V2\Admin\Server\ManageController;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Services\MachineAgentService;
use App\Services\NodeControlStateService;
use App\Services\RelayFirewallService;
use App\Services\ServerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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

    private function report(Server $entry, Server $landing, array $sources, ?int $time = null): void
    {
        ServerService::updateMetrics($entry, ['relay_egress' => ['checked_at' => $time ?? time(), 'children' => [[
            'node_id' => $landing->id, 'address' => $landing->host, 'port' => (int) $landing->port, 'sources' => $sources,
        ]]]]);
    }

    public function test_only_bound_landing_receives_policy_and_public_address_alone_is_not_proof(): void
    {
        [$entry, $landing, $machine] = $this->topology();
        MachineAgentService::recordAddress($machine, '8.8.8.8');
        $this->assertSame('pending', RelayFirewallService::policy($landing)['status']);
        $this->report($entry, $landing, ['8.8.8.8']);
        $this->assertSame(['8.8.8.8'], ServerService::buildNodeConfig($landing)['relay']['firewall']['sources']);
        $this->assertArrayNotHasKey('firewall', ServerService::buildNodeConfig($entry)['relay']);
        $landing->relay_entry_id = null;
        $this->assertArrayNotHasKey('relay', ServerService::buildNodeConfig($landing));
    }

    public function test_address_change_and_binding_change_replace_config_and_old_sources(): void
    {
        [$entry, $landing, $machine] = $this->topology();
        MachineAgentService::recordAddress($machine, '8.8.8.8');
        $this->report($entry, $landing, ['8.8.8.8']);
        $old = app(NodeControlStateService::class)->snapshot($landing);
        MachineAgentService::recordAddress($machine, '8.8.4.4');
        $this->assertSame('pending', RelayFirewallService::policy($landing)['status']);
        $this->report($entry, $landing, ['8.8.4.4']);
        $new = app(NodeControlStateService::class)->snapshot($landing);
        $this->assertGreaterThan($old['sequence'], $new['sequence']);
        $this->assertSame(['8.8.4.4'], $new['config']['relay']['firewall']['sources']);
        $replacement = $entry->replicate();
        $replacement->name = '新前置';
        $replacement->save();
        $landing->update(['relay_entry_id' => $replacement->id]);
        $this->assertSame('pending', RelayFirewallService::policy($landing)['status']);
        $this->report($replacement, $landing, ['8.8.4.4']);
        $this->assertSame('ready', RelayFirewallService::policy($landing)['status']);
    }

    public function test_dual_stack_deduplicates_and_does_not_allow_unobserved_or_private_routes(): void
    {
        [$entry, $landing, $machine] = $this->topology();
        MachineAgentService::recordAddress($machine, '8.8.8.8');
        MachineAgentService::recordAddress($machine, '2606:4700:4700::1111');
        $this->report($entry, $landing, ['8.8.8.8', '2606:4700:4700::1111', '8.8.8.8']);
        $this->assertSame(['2606:4700:4700::1111', '8.8.8.8'], RelayFirewallService::policy($landing)['sources']);
        foreach ([['8.8.8.8', '10.0.0.2'], ['8.8.4.4'], ['0.0.0.0/0'], [], [null], [['bad']]] as $sources) {
            $this->report($entry, $landing, $sources);
            $this->assertSame('pending', RelayFirewallService::policy($landing)['status']);
        }
    }

    public function test_stale_report_changed_destination_and_shared_port_require_confirmation(): void
    {
        [$entry, $landing, $machine] = $this->topology();
        MachineAgentService::recordAddress($machine, '8.8.8.8');
        $this->report($entry, $landing, ['8.8.8.8'], time() - 300);
        $this->assertSame('pending', RelayFirewallService::policy($landing)['status']);
        $this->report($entry, $landing, ['8.8.8.8']);
        $landing->host = '192.0.2.9';
        $this->assertSame('pending', RelayFirewallService::policy($landing)['status']);
        $landing->refresh();
        $duplicate = $landing->replicate();
        $duplicate->name = '共用端口';
        $duplicate->save();
        $policy = RelayFirewallService::policy($landing);
        $this->assertSame('pending', $policy['status']);
        $this->assertStringContainsString('共用端口', $policy['message']);
        $this->assertArrayNotHasKey('confirmation_key', $policy, '人工地址确认不能绕过端口冲突');
    }

    public function test_admin_confirmation_is_scoped_and_invalidated_when_public_ip_changes(): void
    {
        [$entry, $landing, $machine] = $this->topology();
        MachineAgentService::recordAddress($machine, '8.8.8.8');
        $policy = RelayFirewallService::policy($landing);
        $request = Request::create('/', 'POST', ['id' => $landing->id, 'confirmation_key' => $policy['confirmation_key'], 'sources' => ['8.8.4.4']]);
        app(ManageController::class)->confirmRelayFirewall($request);
        $this->assertSame(['8.8.4.4'], RelayFirewallService::policy($landing->fresh())['sources']);
        MachineAgentService::recordAddress($machine, '1.1.1.1');
        $this->assertSame('pending', RelayFirewallService::policy($landing->fresh())['status']);
        $this->expectException(ValidationException::class);
        app(ManageController::class)->confirmRelayFirewall($request);
    }

    public function test_runtime_warning_is_visible_and_cleared_by_successful_report(): void
    {
        [, $landing] = $this->topology();
        ServerService::updateMetrics($landing, ['firewall_warning' => '端口冲突，请确认']);
        $this->assertSame('端口冲突，请确认', $landing->metrics['firewall_warning']);
        ServerService::updateMetrics($landing, []);
        $this->assertNull($landing->fresh()->metrics['firewall_warning']);
    }

    public function test_only_explicit_confirmation_can_use_a_private_transit_source(): void
    {
        [$entry, $landing, $machine] = $this->topology();
        MachineAgentService::recordAddress($machine, '8.8.8.8');
        $this->report($entry, $landing, ['10.0.0.8']);
        $policy = RelayFirewallService::policy($landing);
        $this->assertSame('pending', $policy['status']);
        app(ManageController::class)->confirmRelayFirewall(Request::create('/', 'POST', [
            'id' => $landing->id, 'confirmation_key' => $policy['confirmation_key'], 'sources' => ['10.0.0.8'],
        ]));
        $this->assertSame(['10.0.0.8'], RelayFirewallService::policy($landing->fresh())['sources']);
        $this->assertFalse(RelayFirewallService::validSources(['0.0.0.0/0']));
        $this->assertFalse(RelayFirewallService::validSources(['224.0.0.1']));
        $this->assertFalse(RelayFirewallService::validSources(['ff02::1']));
    }
}
