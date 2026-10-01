<?php

namespace Tests\Feature\Server;

use App\Http\Controllers\V2\Admin\Server\ManageController;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Services\ServerService;
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

    public function test_bound_landing_no_longer_requires_source_confirmation(): void
    {
        [$entry, $landing] = $this->topology();
        foreach ([true, false] as $enabled) {
            $entry->enabled = $enabled;
            $entry->save();
            $config = ServerService::buildNodeConfig($landing);
            $this->assertArrayNotHasKey('firewall', $config['relay']);
        }
        ServerService::updateMetrics($landing, ['firewall_warning' => '旧程序告警', 'relay_egress' => []]);
        $this->assertArrayNotHasKey('firewall_warning', $landing->fresh()->metrics);
        $response = app(ManageController::class)->getNodes(Request::create('/', 'GET'));
        foreach ($response->getData(true)['data'] as $node) {
            $this->assertArrayNotHasKey('relay_firewall', $node);
        }
    }
}
