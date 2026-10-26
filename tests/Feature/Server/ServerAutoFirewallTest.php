<?php

namespace Tests\Feature\Server;

use App\Http\Controllers\V2\Admin\Server\ManageController;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Services\ServerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ServerAutoFirewallTest extends TestCase
{
    use RefreshDatabase;

    private array $pushes = [];

    protected function setUp(): void
    {
        parent::setUp();
        Route::post('/_tests/firewall/save', [ManageController::class, 'save']);
        Redis::shouldReceive('publish')->byDefault()->andReturnUsing(function ($channel, $message) {
            $this->pushes[] = json_decode($message, true);
            return 1;
        });
    }

    public function test_defaults_save_push_repeat_and_omitted_field(): void
    {
        $this->postJson('/_tests/firewall/save', $this->payload())->assertOk();
        $node = Server::latest('id')->firstOrFail();
        $other = Server::create($this->payload(['name' => '另一测试节点']));
        $this->assertTrue($node->auto_firewall);
        $this->assertTrue(ServerService::buildNodeConfig($node)['auto_firewall']);
        Cache::put("node_ws_alive:{$node->id}", true);
        $payload = $this->payload(['id' => $node->id, 'auto_firewall' => false]);
        $this->postJson('/_tests/firewall/save', $payload)->assertOk();
        $this->assertFalse($node->fresh()->auto_firewall);
        $push = collect($this->pushes)->where('event', 'sync.config')->last();
        $this->assertFalse($push['data']['config']['auto_firewall']);
        $this->assertTrue(ServerService::buildNodeConfig($other->fresh())['auto_firewall']);
        $this->pushes = [];
        $this->postJson('/_tests/firewall/save', $payload)->assertOk();
        $this->assertEmpty($this->pushes);
        unset($payload['auto_firewall']);
        $this->postJson('/_tests/firewall/save', $payload)->assertOk();
        $this->assertFalse($node->fresh()->auto_firewall);
        $payload['auto_firewall'] = true;
        $this->postJson('/_tests/firewall/save', $payload)->assertOk();
        $this->assertTrue($node->fresh()->auto_firewall);
    }

    public function test_invalid_values_are_rejected_and_core_policy_is_preserved(): void
    {
        foreach ([null, 'yes', [], 2] as $value) {
            $this->postJson('/_tests/firewall/save', $this->payload(['auto_firewall' => $value]))
                ->assertUnprocessable()->assertJsonValidationErrors('auto_firewall');
        }
        $this->postJson('/_tests/firewall/save', $this->payload([
            'auto_firewall' => false,
            'source_policy' => ['mode' => 'whitelist', 'allow_ips' => ['198.51.100.7']],
        ]))->assertOk();
        $node = Server::latest('id')->firstOrFail();
        $config = ServerService::buildNodeConfig($node);
        $this->assertFalse($config['auto_firewall']);
        $this->assertSame(['198.51.100.7'], $config['source_policy']['allow_ips']);
    }

    public function test_listener_address_follows_literal_host_for_both_kernels(): void
    {
        foreach (['xray', 'singbox'] as $kernel) {
            foreach (Server::VALID_TYPES as $type) {
                $settings = ['cipher' => 'aes-128-gcm'];
                if (in_array($type, [Server::TYPE_HYSTERIA, Server::TYPE_TUIC, Server::TYPE_ANYTLS], true)) {
                    $settings += ['tls' => ['server_name' => 'firewall.example.invalid'],
                        'bandwidth' => ['up' => 100, 'down' => 100], 'obfs' => ['open' => false]];
                }
                foreach (['2001:db8::10' => '::', '[2001:db8::10]' => '::',
                    '192.0.2.10' => '0.0.0.0', 'firewall.example.invalid' => '0.0.0.0'] as $host => $listen) {
                    $node = Server::make($this->payload([
                        'type' => $type, 'kernel_type' => $kernel, 'host' => $host, 'protocol_settings' => $settings,
                    ]));
                    $config = ServerService::buildNodeConfig($node);
                    $this->assertSame($listen, $config['listen_ip'], "$kernel / $type / $host");
                    $this->assertSame(32443, $config['server_port']);
                }
            }
        }
    }

    public function test_host_family_changes_push_listener_and_repeat_or_invalid_save_does_not(): void
    {
        $this->postJson('/_tests/firewall/save', $this->payload(['host' => '192.0.2.10']))->assertOk();
        $node = Server::latest('id')->firstOrFail();
        $other = Server::create($this->payload(['name' => '独立 IPv4 节点', 'host' => '192.0.2.11']));
        Cache::put("node_ws_alive:{$node->id}", true);
        $payload = $this->payload(['id' => $node->id, 'host' => '2001:db8::10']);
        $this->pushes = [];

        $this->postJson('/_tests/firewall/save', $payload)->assertOk();
        $pushes = collect($this->pushes)->where('event', 'sync.config')->values();
        $this->assertCount(1, $pushes);
        $this->assertSame($node->id, $pushes[0]['node_id']);
        $this->assertSame('::', $pushes[0]['data']['config']['listen_ip']);
        $this->assertSame('::', ServerService::buildNodeConfig($node->fresh())['listen_ip']);

        $this->pushes = [];
        $this->postJson('/_tests/firewall/save', $payload)->assertOk();
        $this->assertEmpty($this->pushes);
        $this->postJson('/_tests/firewall/save', array_replace($payload, [
            'host' => '192.0.2.10', 'server_port' => 0,
        ]))->assertUnprocessable()->assertJsonValidationErrors('server_port');
        $this->assertEmpty($this->pushes);
        $this->assertSame('::', ServerService::buildNodeConfig($node->fresh())['listen_ip']);

        $payload['host'] = '192.0.2.10';
        $this->postJson('/_tests/firewall/save', $payload)->assertOk();
        $pushes = collect($this->pushes)->where('event', 'sync.config')->values();
        $this->assertCount(1, $pushes);
        $this->assertSame('0.0.0.0', $pushes[0]['data']['config']['listen_ip']);
        $this->assertSame('0.0.0.0', ServerService::buildNodeConfig($node->fresh())['listen_ip']);
        $this->assertSame('0.0.0.0', ServerService::buildNodeConfig($other->fresh())['listen_ip']);
    }

    public function test_machine_config_api_delivers_ipv6_listener(): void
    {
        $credential = bin2hex(random_bytes(24));
        $machine = ServerMachine::create(['name' => 'IPv6 测试机器', 'token' => $credential, 'is_active' => true]);
        $node = Server::create($this->payload(['host' => '2001:db8::10', 'machine_id' => $machine->id]));

        $this->getJson('/api/v2/server/config?' . http_build_query([
            'machine_id' => $machine->id, 'token' => $credential, 'node_id' => $node->id,
        ]))->assertOk()->assertJsonPath('listen_ip', '::')->assertJsonPath('server_port', 32443)
            ->assertJsonPath('auto_firewall', true);
    }

    public function test_migration_defaults_old_rows_and_preserves_explicit_off_when_repeated(): void
    {
        $node = Server::create($this->payload(['auto_firewall' => false]));
        $migration = require database_path('migrations/2026_10_20_000001_add_auto_firewall_to_servers.php');
        $migration->up();
        $this->assertFalse($node->fresh()->auto_firewall);
        $before = DB::table('v2_server')->where('id', $node->id)->first();
        $migration->down();
        $migration->up();
        $after = DB::table('v2_server')->where('id', $node->id)->first();
        $this->assertTrue((bool) $after->auto_firewall);
        $this->assertSame($before->protocol_settings, $after->protocol_settings);
        $this->assertSame($before->updated_at, $after->updated_at);
        $migration->up();
        $this->assertTrue(ServerService::buildNodeConfig($node->fresh())['auto_firewall']);
    }

    private function payload(array $override = []): array
    {
        return array_replace([
            'name' => '防火墙管理测试', 'type' => 'shadowsocks', 'host' => 'firewall.example.invalid',
            'port' => '32443', 'server_port' => 32443, 'rate' => 1,
            'protocol_settings' => ['cipher' => 'aes-128-gcm'],
        ], $override);
    }
}
