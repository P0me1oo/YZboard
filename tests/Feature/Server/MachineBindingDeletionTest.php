<?php

namespace Tests\Feature\Server;

use App\Http\Controllers\V2\Admin\Server\MachineController;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Services\MachineBindingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class MachineBindingDeletionTest extends TestCase
{
    use RefreshDatabase;

    private function machine(): ServerMachine
    {
        return ServerMachine::create(['name' => 'binding-test', 'token' => Str::random(32), 'is_active' => true]);
    }

    private function heartbeat(ServerMachine $machine, ?string $token = null, ?string $nonce = null, string $route = 'control')
    {
        return $this->postJson('/api/v2/server/machine/' . $route, [
            'machine_id' => $machine->id, 'token' => $token ?? $machine->token,
            'nonce' => $nonce ?? bin2hex(random_bytes(32)), 'version' => 'test', 'boot_id' => 'test-boot', 'manageable' => false,
        ]);
    }

    public function test_deleted_binding_has_a_fresh_authenticated_proof_and_detaches_nodes(): void
    {
        $machine = $this->machine();
        $node = Server::create(['name' => 'binding-node', 'type' => 'shadowsocks', 'host' => 'example.invalid',
            'port' => 12345, 'server_port' => 12345, 'rate' => 1, 'machine_id' => $machine->id]);
        app(MachineController::class)->drop(Request::create('/drop', 'POST', ['id' => $machine->id]));
        $this->assertNull($machine->fresh());
        $this->assertNull($node->fresh()->machine_id);
        $record = DB::table('v2_machine_binding_deletion')->where('machine_id', $machine->id)->first();
        $this->assertSame(hash('sha256', $machine->token), $record->token_hash);
        $this->assertStringNotContainsString($machine->token, json_encode($record));

        foreach (['control', 'control', 'nodes'] as $route) {
            $nonce = bin2hex(random_bytes(32));
            $response = $this->heartbeat($machine, nonce: $nonce, route: $route)->assertOk()
                ->assertHeader('Cache-Control', 'no-store, private')
                ->assertJsonPath('binding_deletion.state', 'deleted')->assertJsonPath('binding_deletion.version', 1)
                ->assertJsonPath('binding_deletion.machine_id', $machine->id)->assertJsonPath('binding_deletion.nonce', $nonce);
            $expected = hash_hmac('sha256', "yz-agent-machine-deleted-v1\n{$machine->id}\n{$nonce}", hash('sha256', $machine->token, true));
            $this->assertSame($expected, $response->json('binding_deletion.proof'));
        }
        $this->heartbeat($machine, Str::random(32))->assertForbidden();
    }

    public function test_disabled_reset_token_and_unknown_id_never_return_deletion_proof(): void
    {
        $machine = $this->machine();
        $this->heartbeat($machine)->assertOk()->assertJsonMissingPath('binding_deletion')->assertJsonMissingPath('binding_deletion.proof');
        $this->assertNotNull($machine->fresh()->last_seen_at);
        $machine->update(['is_active' => false]);
        $this->heartbeat($machine)->assertForbidden()->assertJsonMissingPath('binding_deletion.proof');
        $oldToken = $machine->token;
        $machine->update(['token' => Str::random(32)]);
        $this->heartbeat($machine, $oldToken)->assertForbidden();
        MachineBindingService::delete($machine->id);
        $this->heartbeat($machine, $oldToken)->assertForbidden();
        $this->heartbeat($machine)->assertOk()->assertJsonPath('binding_deletion.state', 'deleted');

        $oldDeletion = $this->machine();
        $oldDeletion->delete(); // Pre-upgrade deletion without a retained proof.
        $this->heartbeat($oldDeletion)->assertForbidden();
    }

    public function test_deletion_and_proof_roll_back_together_on_failure(): void
    {
        $machine = $this->machine();
        $this->failNextQueryMatching('/delete from [`"]?v2_server_machine[`"]?\s/i');
        try {
            MachineBindingService::delete($machine->id);
            $this->fail('Expected injected deletion failure');
        } catch (\Illuminate\Database\QueryException) {
            $this->assertNotNull($machine->fresh());
            $this->assertDatabaseMissing('v2_machine_binding_deletion', ['machine_id' => $machine->id]);
        }
        $this->heartbeat($machine)->assertOk()->assertJsonMissingPath('binding_deletion');
        MachineBindingService::delete($machine->id);
        $this->heartbeat($machine)->assertOk()->assertJsonPath('binding_deletion.state', 'deleted');
    }

    public function test_proof_storage_failure_leaves_the_machine_intact(): void
    {
        $machine = $this->machine();
        $this->failNextQueryMatching('/insert into [`"]?v2_machine_binding_deletion/i');
        try {
            MachineBindingService::delete($machine->id);
            $this->fail('Expected injected proof write failure');
        } catch (\Illuminate\Database\QueryException) {
            $this->assertNotNull($machine->fresh());
        }
    }

    public function test_deleted_identity_cannot_authorize_another_or_recreated_machine(): void
    {
        $machine = $this->machine();
        $other = $this->machine();
        MachineBindingService::delete($machine->id);
        $this->heartbeat($other, $machine->token)->assertForbidden();
        $replacementToken = Str::random(32);
        DB::table('v2_server_machine')->insert(['id' => $machine->id, 'name' => 'restored-id', 'token' => $replacementToken, 'is_active' => true]);
        $this->heartbeat($machine)->assertForbidden();
        $this->heartbeat($machine, $replacementToken)->assertOk()->assertJsonMissingPath('binding_deletion');
    }

    public function test_old_agent_heartbeat_keeps_its_normal_response_and_never_receives_a_proof(): void
    {
        $machine = $this->machine();
        $body = ['machine_id' => $machine->id, 'token' => $machine->token,
            'version' => 'old', 'boot_id' => 'old-boot', 'manageable' => false];
        $route = '/api/v2/server/machine/control';
        $this->postJson($route, $body)->assertOk()->assertJsonMissingPath('binding_deletion');
        MachineBindingService::delete($machine->id);
        $this->postJson($route, $body)->assertForbidden()->assertJsonMissingPath('binding_deletion');
        $this->postJson($route, $body + ['node_id' => 2, 'nonce' => bin2hex(random_bytes(32))])->assertForbidden();
        $this->postJson('/api/v2/server/machine/privacy', $body + ['nonce' => bin2hex(random_bytes(32))])->assertForbidden();
    }

    public function test_nonce_and_identity_validation_reject_invalid_requests(): void
    {
        $machine = $this->machine();
        $this->heartbeat($machine, nonce: 'old')->assertUnprocessable();
        $this->postJson('/api/v2/server/machine/binding-status', ['machine_id' => $machine->id])->assertNotFound();
    }
}
