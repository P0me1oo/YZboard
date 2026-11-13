<?php

namespace Tests\Feature\Server;

use App\Models\Server;
use App\Models\ServerMachine;
use App\Services\NodeConnectionAuth;
use App\Services\NodeRegistry;
use App\WebSocket\NodeWorker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;
use Workerman\Connection\TcpConnection;

class NodeCredentialRevocationTest extends TestCase
{
    use RefreshDatabase;

    private ServerMachine $machine;
    private Server $node;
    private TcpConnection $conn;
    private NodeWorker $worker;
    private array $frames = [];
    private int $closed = 0;

    protected function setUp(): void
    {
        parent::setUp(); Bus::fake();
        Redis::shouldReceive('publish')->andReturn(0);
        $this->machine = ServerMachine::create(['name' => 'revocation-test', 'token' => 'old-test-token', 'is_active' => true]);
        $this->node = Server::create(['name' => 'revocation-test', 'machine_id' => $this->machine->id,
            'type' => 'vmess', 'host' => '127.0.0.1', 'port' => 443, 'server_port' => 443,
            'rate' => 1, 'group_ids' => [1], 'enabled' => true]);
        $this->conn = \Mockery::mock(TcpConnection::class);
        $this->conn->machineId = (int) $this->machine->id;
        $this->conn->machineNodeIds = [(int) $this->node->id];
        $this->conn->realtime = true;
        $this->conn->shouldReceive('send')->andReturnUsing(function ($message) { $this->frames[] = json_decode($message, true); return true; });
        $this->conn->shouldReceive('close')->andReturnUsing(function () { $this->closed++; });
        NodeConnectionAuth::bind($this->conn, 'old-test-token');
        NodeRegistry::addMachine((int) $this->machine->id, $this->conn);
        NodeRegistry::add((int) $this->node->id, $this->conn);
        $this->worker = (new \ReflectionClass(NodeWorker::class))->newInstanceWithoutConstructor();
    }

    protected function tearDown(): void
    {
        NodeRegistry::remove((int) $this->node->id, $this->conn);
        NodeRegistry::removeMachine((int) $this->machine->id, $this->conn);
        unset($this->conn->authorizeSend);
        parent::tearDown();
    }

    public function test_reset_invalidates_existing_socket_before_traffic_and_configuration_delivery(): void
    {
        $this->assertTrue(NodeConnectionAuth::check($this->conn));
        app(\App\Http\Controllers\V2\Admin\Server\MachineController::class)->resetToken(Request::create('/', 'POST', ['id' => $this->machine->id]));
        $this->postJson('/api/v2/server/handshake', ['machine_id' => $this->machine->id, 'token' => 'old-test-token'])->assertStatus(401);
        $this->worker->onMessage($this->conn, json_encode(['event' => 'report.traffic', 'data' => [
            'node_id' => $this->node->id, 'report_id' => 'revoked-report', 'traffic' => [],
        ]]));
        $this->assertSame([], $this->frames);
        $this->assertFalse(NodeRegistry::send((int) $this->node->id, 'sync.config', ['config' => []]));
        $this->assertGreaterThan(0, $this->closed);
    }

    public function test_periodic_check_closes_idle_revoked_session_without_redis_notification(): void
    {
        $this->machine->update(['token' => 'new-test-token']);
        $this->worker->revalidateConnections();
        $this->assertSame(1, $this->closed);
    }

    public function test_current_token_stays_valid_even_when_revoke_notification_arrives_late(): void
    {
        $this->machine->update(['token' => 'new-test-token']);
        NodeConnectionAuth::bind($this->conn, 'new-test-token');
        $this->worker->revalidateConnections();
        $this->assertSame(0, $this->closed);
        $this->assertTrue(NodeRegistry::send((int) $this->node->id, 'sync.config', ['config' => []]));
    }

    public function test_disabled_machine_and_reassigned_node_reject_existing_connection(): void
    {
        $this->machine->update(['is_active' => false]);
        $this->assertFalse(NodeConnectionAuth::check($this->conn));
        $this->machine->update(['is_active' => true]);
        $this->node->update(['machine_id' => null]);
        $this->assertFalse(NodeRegistry::send((int) $this->node->id, 'sync.snapshot', []));
    }

    public function test_legacy_connection_rechecks_global_token_and_handles_read_failure(): void
    {
        unset($this->conn->machineId);
        $this->conn->nodeId = (int) $this->node->id;
        admin_setting(['server_token' => 'old-test-token']);
        $this->assertTrue(NodeConnectionAuth::check($this->conn));
        admin_setting(['server_token' => 'replacement-test-token']);
        $this->assertFalse(NodeConnectionAuth::check($this->conn));
        $this->app->bind(\App\Support\Setting::class, fn () => throw new \RuntimeException('test settings unavailable'));
        $this->assertFalse(NodeConnectionAuth::check($this->conn));
    }

    public function test_missing_handshake_identity_is_rejected(): void
    {
        unset($this->conn->authTokenHash);
        $this->worker->onMessage($this->conn, json_encode(['event' => 'pong']));
        $this->assertSame(1, $this->closed);
    }

    public function test_node_message_checks_machine_and_node_once_then_next_message_rechecks(): void
    {
        $calls = 0;
        (new \ReflectionProperty(NodeWorker::class, 'handlers'))->setValue($this->worker,
            ['test.state' => function () use (&$calls): void { $calls++; }]);
        $this->mock(\App\Services\NodeRuntimeMetadata::class, function ($mock): void {
            $mock->shouldReceive('machineOrFail')->with($this->machine->id)->twice()->andReturn($this->machine);
            $mock->shouldReceive('node')->with($this->node->id)->twice()->andReturn($this->node);
        });
        $message = json_encode(['event' => 'test.state', 'data' => ['node_id' => $this->node->id]]);
        $this->worker->onMessage($this->conn, $message);
        $this->worker->onMessage($this->conn, $message);
        $this->assertSame(2, $calls);
        $this->assertSame(0, $this->closed);
    }

    public function test_unknown_event_and_unregistered_node_remain_ignored_but_revoked_identity_is_closed(): void
    {
        $this->worker->onMessage($this->conn, json_encode(['event' => 'unknown', 'data' => ['node_id' => $this->node->id]]));
        $this->worker->onMessage($this->conn, json_encode(['event' => 'report.traffic', 'data' => ['node_id' => 999999]]));
        $this->assertSame(0, $this->closed);
        $this->assertSame([], $this->frames);
        $this->machine->update(['token' => 'revoked-test-token']);
        $this->worker->onMessage($this->conn, json_encode(['event' => 'unknown']));
        $this->assertSame(1, $this->closed);
    }

    public function test_machine_handshake_binds_revocable_identity_before_sending_snapshots(): void
    {
        unset($this->conn->authTokenHash, $this->conn->authorizeSend);
        $this->conn->shouldReceive('getRemoteIp')->andReturn('127.0.0.1');
        $this->worker->onWebSocketConnect($this->conn, '/ws?' . http_build_query([
            'machine_id' => $this->machine->id, 'token' => 'old-test-token', 'realtime' => '1',
        ]));
        $this->assertSame('auth.success', $this->frames[0]['event']);
        $this->assertContains('sync.snapshot', array_column($this->frames, 'event'));
        $this->assertTrue(NodeConnectionAuth::check($this->conn));
        $this->machine->update(['token' => 'changed-after-handshake']);
        $this->assertFalse(NodeRegistry::send((int) $this->node->id, 'sync.config', []));
    }
}
