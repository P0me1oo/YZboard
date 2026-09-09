<?php

namespace Tests\Feature\Server;

use App\Http\Requests\Admin\ServerSave;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Protocols\ClashMeta;
use App\Protocols\General;
use App\Protocols\SingBox;
use App\Services\ServerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class PortHoppingTest extends TestCase
{
    use RefreshDatabase;

    private function node(array $overrides = []): Server
    {
        return Server::create(array_replace([
            'name' => '跳跃测试节点', 'type' => Server::TYPE_HYSTERIA,
            'host' => 'node.example.invalid', 'port' => '20000-20010,20300',
            'server_port' => 8443, 'rate' => '1', 'group_ids' => [1], 'enabled' => true,
            'protocol_settings' => ['version' => 2, 'hop_interval' => 5,
                'tls' => ['server_name' => 'node.example.invalid'],
                'bandwidth' => ['up' => 100, 'down' => 100],
                'obfs' => ['open' => false]],
        ], $overrides));
    }

    public function test_node_config_preserves_listener_and_sends_hopping_ports(): void
    {
        $node = $this->node();
        $config = ServerService::buildNodeConfig($node);
        $this->assertSame(8443, $config['server_port']);
        $this->assertSame('20000-20010,20300', $config['port_hopping']);

        $node->port = '20000 - 20010 ,20300,20001';
        $this->assertSame('20000-20010,20300', ServerService::buildNodeConfig($node)['port_hopping']);

        $node->port = '8443';
        $this->assertArrayNotHasKey('port_hopping', ServerService::buildNodeConfig($node));
        $node->port = '20000-20010';
        $node->protocol_settings = array_replace($node->protocol_settings, ['version' => 1]);
        $this->assertArrayNotHasKey('port_hopping', ServerService::buildNodeConfig($node));
    }

    public function test_machine_api_delivers_ranges_and_omits_disabled_nodes(): void
    {
        $credential = bin2hex(random_bytes(24));
        $machine = ServerMachine::create(['name' => '跳跃测试机器', 'token' => $credential, 'is_active' => true]);
        $node = $this->node(['machine_id' => $machine->id]);
        $this->node(['machine_id' => $machine->id, 'enabled' => false, 'port' => '21000-21100']);
        $response = $this->postJson('/api/v2/server/machine/nodes', [
            'machine_id' => $machine->id, 'token' => $credential,
        ]);
        $response->assertOk()->assertJsonCount(1, 'nodes');
        $response->assertJsonPath('nodes.0.id', $node->id);
        $this->getJson('/api/v2/server/config?' . http_build_query([
            'machine_id' => $machine->id, 'token' => $credential, 'node_id' => $node->id,
        ]))->assertOk()
            ->assertJsonPath('port_hopping', '20000-20010,20300')
            ->assertJsonPath('server_port', 8443);
    }

    public function test_hysteria2_validation_rejects_invalid_ranges_and_short_intervals(): void
    {
        $base = ['type' => 'hysteria', 'name' => '跳跃测试', 'host' => 'node.example.invalid',
            'port' => '20000-20010,20300', 'server_port' => 8443, 'rate' => 1,
            'protocol_settings' => ['version' => 2, 'hop_interval' => 5]];
        foreach ([['port' => '20010-20000'], ['port' => '65536'], ['port' => '20000,'],
            ['server_port' => '8443-8444'], ['protocol_settings' => ['version' => 2, 'hop_interval' => 4]]] as $invalid) {
            $request = new ServerSave();
            $request->merge(array_replace($base, $invalid));
            $this->assertTrue(Validator::make($request->all(), $request->rules())->fails());
        }
        $request = new ServerSave();
        $request->merge($base);
        $this->assertFalse(Validator::make($request->all(), $request->rules())->fails());
    }

    public function test_subscriptions_keep_port_list_and_supported_hop_interval(): void
    {
        $node = $this->node()->toArray();
        $node['port'] = 20000;
        $node['ports'] = '20000-20010,20300';
        $credential = bin2hex(random_bytes(16));
        $clash = ClashMeta::buildHysteria($credential, $node, []);
        $this->assertSame('20000-20010,20300', $clash['ports']);
        $this->assertSame(5, $clash['hop-interval']);
        $uri = General::buildHysteria($credential, $node);
        parse_str((string) parse_url(trim($uri), PHP_URL_QUERY), $query);
        $this->assertSame('20000-20010,20300', $query['mport']);
        $singbox = new SingBox(['uuid' => $credential], [$node], 'sing-box', '1.14.0');
        $outbound = (new \ReflectionMethod(SingBox::class, 'buildHysteria'))->invoke($singbox, $credential, $node);
        $this->assertSame(['20000:20010', '20300:20300'], $outbound['server_ports']);
        $this->assertSame('5s', $outbound['hop_interval']);
    }
}
