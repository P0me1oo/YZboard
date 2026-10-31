<?php

namespace Tests\Feature\Server;

use App\Models\Server;
use App\Models\ServerMachine;
use App\Services\AgentPrivacy;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Tests\TestCase;

class AgentPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private function machine(bool $enabled = true): ServerMachine
    {
        return ServerMachine::create(['name' => '文件保护测试', 'token' => Str::random(32),
            'is_active' => true, 'agent_log_disabled' => $enabled]);
    }

    public function test_keys_are_authenticated_machine_specific_and_stable_across_restarts_and_token_changes(): void
    {
        $machine = $this->machine();
        $auth = ['machine_id' => $machine->id, 'token' => $machine->token];
        $first = $this->postJson('/api/v2/server/machine/privacy', $auth)->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('data.enabled', true)->json('data.keys');
        $this->assertCount(1, $first);
        $this->postJson('/api/v2/server/machine/privacy', [...$auth, 'probe' => true])
            ->assertOk()->assertJsonPath('data.enabled', true)->assertJsonPath('data.keys', []);
        $this->assertSame(32, strlen(base64_decode($first[0]['key'], true)));
        $this->assertSame(substr(hash('sha256', base64_decode($first[0]['key'])), 0, 32), $first[0]['id']);
        $this->assertSame($first, $this->postJson('/api/v2/server/machine/privacy', $auth)->assertOk()->json('data.keys'));
        $machine->update(['token' => Str::random(32)]);
        $this->assertSame($first, AgentPrivacy::view($machine)['keys']);
        $other = $this->machine();
        $this->assertNotSame($first[0]['id'], AgentPrivacy::view($other)['keys'][0]['id']);
        $this->postJson('/api/v2/server/machine/privacy', $auth)->assertForbidden();
        $this->postJson('/api/v2/server/machine/privacy', ['machine_id' => $other->id, 'token' => $machine->token])->assertForbidden();
    }

    public function test_disabled_protection_does_not_return_keys_and_inactive_machine_can_recover_its_files(): void
    {
        $normal = $this->machine(false);
        $this->postJson('/api/v2/server/machine/privacy', ['machine_id' => $normal->id, 'token' => $normal->token])
            ->assertOk()->assertJsonPath('data', ['version' => 1, 'enabled' => false, 'keys' => []]);
        $private = $this->machine();
        $keys = AgentPrivacy::view($private)['keys'];
        $private->update(['is_active' => false]);
        $this->postJson('/api/v2/server/machine/privacy', ['machine_id' => $private->id, 'token' => $private->token])
            ->assertOk()->assertJsonPath('data.keys', $keys);
        $this->postJson('/api/v2/server/machine/privacy', [])->assertUnprocessable();
    }

    public function test_previous_panel_keys_remain_available_for_existing_encrypted_files(): void
    {
        $machine = $this->machine();
        $previous = Crypt::getKey();
        $old = AgentPrivacy::view($machine)['keys'][0];
        $encrypter = new Encrypter(random_bytes(32), 'AES-256-CBC');
        $encrypter->previousKeys([$previous]);
        Crypt::swap($encrypter);
        $keys = AgentPrivacy::view($machine)['keys'];
        $this->assertCount(2, $keys);
        $this->assertNotSame($old['id'], $keys[0]['id']);
        $this->assertSame($old, $keys[1]);
    }

    public function test_legacy_node_binding_can_only_get_its_own_machine_keys(): void
    {
        $token = Str::random(32);
        admin_setting(['server_token' => $token]);
        $machine = $this->machine();
        $node = Server::create(['name' => '文件保护节点', 'machine_id' => $machine->id,
            'type' => 'shadowsocks', 'host' => 'test.invalid', 'port' => '10001', 'server_port' => 10001,
            'rate' => 1, 'group_ids' => [], 'enabled' => true]);
        $this->postJson('/api/v2/server/privacy', ['node_id' => $node->id, 'token' => $token])
            ->assertOk()->assertJsonPath('data.keys', AgentPrivacy::view($machine)['keys']);
        $this->postJson('/api/v2/server/privacy', ['node_id' => $node->id, 'token' => Str::random(32)])
            ->assertUnprocessable();
    }
}
