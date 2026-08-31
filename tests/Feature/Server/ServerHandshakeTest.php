<?php

namespace Tests\Feature\Server;

use App\Jobs\ProcessNodeReportBatch;
use App\Models\NodeReportBatch;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\StatServer;
use App\Models\StatUser;
use App\Models\User;
use App\Support\Setting;
use App\Utils\CacheKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
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

        Bus::assertDispatched(ProcessNodeReportBatch::class, function (ProcessNodeReportBatch $job) use ($user): bool {
            $property = new \ReflectionProperty($job, 'batchId');
            $batch = NodeReportBatch::find($property->getValue($job));
            return ($batch?->traffic[$user->id] ?? null) === [1234, 5678];
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

    public function test_v2_report_does_not_dispatch_duplicate_traffic_for_same_batch(): void
    {
        Bus::fake();

        $server = $this->makeServer(Server::TYPE_HYSTERIA);
        $user = User::create([
            'email' => 'hysteria-idempotent@example.invalid',
            'password' => 'unused',
            'uuid' => '22222222-2222-2222-2222-222222222222',
            'token' => str_repeat('b', 32),
            'group_id' => 1,
            'transfer_enable' => 1024 * 1024 * 1024,
            'expired_at' => time() + 3600,
        ]);

        $payload = [
            'token' => 'server-token',
            'node_id' => $server->id,
            'report_id' => 'test-boot-1-1',
            'traffic' => [(string) $user->id => [100, 200]],
            'online' => [(string) $user->id => 1],
        ];

        $this->postJson('/api/v2/server/report', $payload)->assertOk();
        $this->postJson('/api/v2/server/report', $payload)->assertOk();

        Bus::assertDispatchedTimes(ProcessNodeReportBatch::class, 1);
        $this->assertSame(1, NodeReportBatch::count());

        // 不同批次仍应正常接受并派发。
        $payload['report_id'] = 'test-boot-1-2';
        $this->postJson('/api/v2/server/report', $payload)->assertOk();
        Bus::assertDispatchedTimes(ProcessNodeReportBatch::class, 2);
        $this->assertSame(2, NodeReportBatch::count());

        $server->refresh();
        $this->assertNotNull($server->last_check_at);
        $this->assertNotNull($server->last_push_at);
        $this->assertSame(
            1,
            Cache::get(CacheKey::get(
                'USER_ONLINE_CONN_' . Server::TYPE_HYSTERIA . '_' . $server->id,
                $user->id
            ))
        );
    }

    public function test_report_batch_job_is_idempotent_after_success(): void
    {
        Bus::fake();

        $server = $this->makeServer(Server::TYPE_HYSTERIA);
        $server->forceFill(['rate' => 2])->save();
        $user = User::create([
            'email' => 'hysteria-batch@example.invalid',
            'password' => 'unused',
            'uuid' => '33333333-3333-3333-3333-333333333333',
            'token' => str_repeat('c', 32),
            'group_id' => 1,
            'transfer_enable' => 1024 * 1024 * 1024,
            'expired_at' => time() + 3600,
        ]);

        $this->postJson('/api/v2/server/report', [
            'token' => 'server-token',
            'node_id' => $server->id,
            'report_id' => 'idempotent-job-1',
            'traffic' => [(string) $user->id => [100, 200]],
        ])->assertOk();

        $batch = NodeReportBatch::firstOrFail();
        Redis::shouldReceive('sadd')
            ->once()
            ->with('traffic:pending_check', $user->id)
            ->andReturn(1);

        (new ProcessNodeReportBatch($batch->id))->handle();
        (new ProcessNodeReportBatch($batch->id))->handle();

        $user->refresh();
        $server->refresh();
        $batch->refresh();
        $this->assertSame(200, (int) $user->u);
        $this->assertSame(400, (int) $user->d);
        $this->assertSame(100, (int) $server->u);
        $this->assertSame(200, (int) $server->d);
        $this->assertSame(NodeReportBatch::STATUS_PROCESSED, $batch->status);
        $this->assertSame(1, StatUser::count());
        $this->assertSame(1, StatServer::count());
    }

    public function test_empty_online_snapshot_clears_stale_online_state(): void
    {
        Bus::fake();

        $server = $this->makeServer(Server::TYPE_HYSTERIA);
        $user = User::create([
            'email' => 'hysteria-offline@example.invalid',
            'password' => 'unused',
            'uuid' => '44444444-4444-4444-4444-444444444444',
            'token' => str_repeat('d', 32),
            'group_id' => 1,
            'transfer_enable' => 1024 * 1024 * 1024,
            'expired_at' => time() + 3600,
        ]);

        $base = [
            'token' => 'server-token',
            'node_id' => $server->id,
        ];
        $this->postJson('/api/v2/server/report', $base + [
            'online' => [(string) $user->id => 2],
        ])->assertOk();
        $this->postJson('/api/v2/server/report', $base + [
            'online' => [],
        ])->assertOk();

        $this->assertSame(0, $server->online);
        $this->assertNull(Cache::get(CacheKey::get(
            'USER_ONLINE_CONN_' . Server::TYPE_HYSTERIA . '_' . $server->id,
            $user->id
        )));
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
