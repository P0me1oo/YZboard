<?php

namespace Tests\Feature\Server;

use App\Http\Controllers\V2\Admin\Server\ManageController;
use App\Models\Server;
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
