<?php

namespace Tests\Feature\Server;

use App\Http\Controllers\V2\Admin\Server\LoadBalancerController;
use App\Models\BalancerDnsPreset;
use App\Models\Server;
use App\Models\ServerMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class LoadBalancerTest extends TestCase
{
    use RefreshDatabase;

    private function node(string $name, string $address): Server
    {
        $machine = ServerMachine::create(['name' => $name, 'token' => Str::random(32), 'is_active' => true, 'agent_runtime' => ['version' => 'v2.25.0']]);
        return Server::create(['name' => $name, 'machine_id' => $machine->id, 'host' => $address, 'type' => 'vless',
            'kernel_type' => 'xray', 'port' => '443', 'server_port' => 443, 'enabled' => true, 'show' => true, 'rate' => 1,
            'group_ids' => [1], 'protocol_settings' => ['network' => 'tcp', 'tls' => 2, 'flow' => 'xtls-rprx-vision',
                'reality_settings' => ['server_name' => 'handshake.example.com', 'server_port' => 443,
                    'private_key' => rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='),
                    'public_key' => rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='), 'short_id' => bin2hex(random_bytes(8))]]]);
    }

    public function test_saved_cloudflare_token_is_encrypted_and_never_returned(): void
    {
        $token = Str::random(40);
        $controller = new LoadBalancerController();
        $controller->savePreset(Request::create('/', 'POST', ['name' => '测试域名', 'domain' => 'example.com', 'zone_id' => str_repeat('a', 32), 'api_token' => $token]));
        $preset = BalancerDnsPreset::firstOrFail();
        $this->assertNotSame($token, DB::table('v2_balancer_dns_preset')->value('api_token'));
        $this->assertFalse(array_key_exists('api_token', $preset->toArray()));
        $controller->savePreset(Request::create('/', 'POST', ['id' => $preset->id, 'name' => '改名', 'domain' => 'example.com', 'zone_id' => str_repeat('a', 32), 'api_token' => '']));
        $this->assertTrue(hash_equals($token, $preset->fresh()->api_token));
    }

    public function test_zero_pool_selection_is_normalized_to_no_pool(): void
    {
        $group = new \App\Models\ServerGroup();
        $group->name = '测试权限组';
        $group->save();
        $node = $this->node('普通直连', '192.0.2.10');
        $node->update(['group_ids' => [$group->id]]);
        $data = $node->toArray();
        $data['relay_pool_id'] = '0';
        $request = \App\Http\Requests\Admin\ServerSave::create('/', 'POST', $data);
        $request->setContainer($this->app)->setRedirector($this->app['redirect']);
        $request->validateResolved();
        $this->assertNull($request->validated()['relay_pool_id']);
    }
}
