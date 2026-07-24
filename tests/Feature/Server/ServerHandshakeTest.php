<?php

namespace Tests\Feature\Server;

use App\Jobs\TrafficFetchJob;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\User;
use App\Support\Setting;
use App\Utils\CacheKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Mockery\MockInterface;
use Tests\TestCase;

class ServerHandshakeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.key', 'base64:' . base64_encode(str_repeat('a', 32)));
        $this->mock(Setting::class, function (MockInterface $mock): void {
            $settings = [
                'server_token' => 'server-token',
                'server_ws_enable' => 0,
                'server_push_interval' => 60,
            ];
            $mock->shouldReceive('get')->andReturnUsing(
                fn (string $key) => $settings[$key] ?? null
            );
        });
    }

    public function test_v2_handshake_accepts_token_only_without_node(): void
    {
        $response = $this->postJson('/api/v2/server/handshake', [
            'token' => 'server-token',
        ]);

        $response->assertOk()->assertJsonStructure(['websocket' => ['enabled']]);
    }

    public function test_v2_handshake_rejects_invalid_token(): void
    {
        $response = $this->postJson('/api/v2/server/handshake', [
            'token' => 'wrong-token',
        ]);

        $response->assertStatus(422);
    }

    public function test_v2_report_works_without_node_type(): void
    {
        Bus::fake();

        $server = $this->makeServer();

        $response = $this->postJson('/api/v2/server/report', [
            'token' => 'server-token',
            'node_id' => $server->id,
        ]);

        $response->assertOk()->assertJson(['data' => true]);
    }

    public function test_v2_report_ignores_node_type_field(): void
    {
        Bus::fake();

        $server = $this->makeServer();

        // legacy node clients may still send node_type; V2 must accept it as no-op.
        $response = $this->postJson('/api/v2/server/report', [
            'token' => 'server-token',
            'node_id' => $server->id,
            'node_type' => 'this-would-be-rejected-by-v1',
        ]);

        $response->assertOk()->assertJson(['data' => true]);
    }

    public function test_v2_report_rejects_unknown_node(): void
    {
        $response = $this->postJson('/api/v2/server/report', [
            'token' => 'server-token',
            'node_id' => 999999,
        ]);

        $response->assertStatus(400);
        $response->assertJson(['message' => 'Server does not exist']);
    }

    public function test_v2_machine_handshake_with_machine_id_and_no_node(): void
    {
        $machine = ServerMachine::create([
            'name' => 'test-machine',
            'token' => 'machine-token',
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/v2/server/handshake', [
            'machine_id' => $machine->id,
            'token' => 'machine-token',
        ]);

        $response->assertOk();
    }

    public function test_v2_machine_report_requires_node_id(): void
    {
        $machine = ServerMachine::create([
            'name' => 'test-machine',
            'token' => 'machine-token',
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/v2/server/report', [
            'machine_id' => $machine->id,
            'token' => 'machine-token',
        ]);

        $response->assertStatus(422);
    }

    public function test_v2_hysteria_report_preserves_traffic_directions_and_node_state(): void
    {
        Bus::fake();

        $server = $this->makeServer(Server::TYPE_HYSTERIA);
        $user = User::create([
            'email' => 'hysteria-report@example.invalid',
            'password' => 'unused',
            'uuid' => '11111111-1111-1111-1111-111111111111',
            'token' => str_repeat('a', 32),
            'group_id' => 1,
            'transfer_enable' => 1024 * 1024 * 1024,
            'expired_at' => time() + 3600,
        ]);

        $response = $this->postJson('/api/v2/server/report', [
            'token' => 'server-token',
            'node_id' => $server->id,
            'traffic' => [(string) $user->id => [1234, 5678]],
            'online' => [(string) $user->id => 1],
            'metrics' => [
                'active_connections' => 1,
                'active_users' => 1,
                'total_users' => 1,
                'kernel_status' => true,
            ],
        ]);

        $response->assertOk()->assertJson(['data' => true]);

        Bus::assertDispatched(TrafficFetchJob::class, function (TrafficFetchJob $job) use ($user): bool {
            $property = new \ReflectionProperty($job, 'data');
            $data = $property->getValue($job);
            return ($data[$user->id] ?? null) === [1234, 5678];
        });

        $this->assertNotNull($server->last_check_at);
        $this->assertNotNull($server->last_push_at);
        $this->assertSame(1, $server->online);
        $this->assertSame(
            1,
            Cache::get(CacheKey::get(
                'USER_ONLINE_CONN_' . Server::TYPE_HYSTERIA . '_' . $server->id,
                $user->id
            ))
        );
    }

    private function makeServer(string $type = Server::TYPE_VMESS): Server
    {
        return Server::create([
            'name' => 'test-node',
            'type' => $type,
            'host' => '127.0.0.1',
            'port' => 443,
            'server_port' => 443,
            'rate' => '1',
            'group_ids' => [1],
            'enabled' => true,
        ]);
    }
}
