<?php

namespace Tests\Feature\Server;

use App\Models\Server;
use App\Models\ServerMachine;
use App\Services\FailureLog;
use App\Services\NodeConnectionAuth;
use App\Services\NodeRegistry;
use App\Support\Setting;
use App\WebSocket\NodeWorker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;
use Workerman\Connection\TcpConnection;
use Workerman\Events\Select;
use Workerman\Protocols\Websocket;

class NodeHandshakeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        admin_setting(['server_token' => 'handshake-test-secret', 'server_ws_enable' => 0]);
        (new \ReflectionProperty(FailureLog::class, 'last'))->setValue(null, []);
        Log::spy();
    }

    /** 经过真实协议编码和本机 socket，避免只验证 close() 入参而漏掉 101/502 回归。 */
    private function handshake(array $params = [], array $headers = []): string
    {
        [$server, $client] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        stream_set_blocking($client, false);
        $conn = new TcpConnection(new Select(), $server, '127.0.0.1:12345');
        $conn->protocol = Websocket::class;
        $worker = (new \ReflectionClass(NodeWorker::class))->newInstanceWithoutConstructor();
        $conn->onWebSocketConnect = [$worker, 'onWebSocketConnect'];
        $fields = array_replace([
            'Host' => 'panel.test', 'Upgrade' => 'websocket', 'Connection' => 'Upgrade',
            'Sec-WebSocket-Version' => '13', 'Sec-WebSocket-Key' => 'dGhlIHNhbXBsZSBub25jZQ==',
        ], $headers);
        $request = 'GET /ws?' . http_build_query($params) . " HTTP/1.1\r\n";
        foreach ($fields as $key => $value) $request .= $key . ': ' . $value . "\r\n";
        try {
            Websocket::input($request . "\r\n", $conn);
            return stream_get_contents($client);
        } finally {
            $conn->destroy();
            fclose($client);
        }
    }

    public function test_missing_invalid_and_malformed_credentials_return_http_rejections_without_upgrade(): void
    {
        foreach ([
            [[], 401, 'missing_credentials'],
            [['token' => ['not-a-string']], 401, 'missing_credentials'],
            [['node_id' => 1, 'token' => 'private-invalid-token'], 401, 'invalid_credentials'],
            [['node_id' => ['bad'], 'token' => 'handshake-test-secret'], 400, 'invalid_node_id'],
            [['machine_id' => ['bad'], 'token' => 'handshake-test-secret'], 400, 'invalid_machine_id'],
            [['machine_id' => 99999, 'token' => 'private-invalid-token'], 401, 'invalid_credentials'],
        ] as [$params, $status, $reason]) {
            $response = $this->handshake($params);
            $this->assertStringStartsWith('HTTP/1.1 ' . $status . ' ', $response);
            $this->assertStringContainsString('"error":"' . $reason . '"', $response);
            $this->assertStringNotContainsString('101 Switching', $response);
            $this->assertStringNotContainsString('private-invalid-token', $response);
            $this->assertStringNotContainsString('handshake-test-secret', $response);
        }
    }

    public function test_disabled_machine_is_forbidden_and_admin_handshake_remains_available(): void
    {
        $machine = ServerMachine::create(['name' => 'handshake-test', 'token' => 'test-machine-token', 'is_active' => false]);
        $response = $this->handshake(['machine_id' => $machine->id, 'token' => 'test-machine-token']);
        $this->assertStringStartsWith('HTTP/1.1 403 ', $response);
        $this->assertStringContainsString('machine_disabled', $response);
        $this->assertStringNotContainsString('101 Switching', $response);
        $this->assertStringStartsWith('HTTP/1.1 101 ', $this->handshake(['client' => 'admin']));
    }

    public function test_invalid_upgrade_headers_return_http_errors(): void
    {
        foreach ([
            [['Upgrade' => 'http'], 426],
            [['Sec-WebSocket-Version' => '12'], 426],
            [['Connection' => 'keep-alive'], 400],
            [['Sec-WebSocket-Key' => 'invalid'], 400],
        ] as [$headers, $status]) {
            $response = $this->handshake(['client' => 'admin'], $headers);
            $this->assertStringStartsWith('HTTP/1.1 ' . $status . ' ', $response);
            $this->assertStringNotContainsString('101 Switching', $response);
        }
        $this->assertStringStartsWith('HTTP/1.1 101 ', $this->handshake(['client' => 'admin'], ['Connection' => 'keep-alive, Upgrade']));
    }

    public function test_authentication_dependency_failure_returns_503_without_stopping_worker_or_leaking_reason(): void
    {
        $this->app->bind(Setting::class, fn () => throw new \RuntimeException('setting failed token=private-server-secret'));
        $response = $this->handshake(['node_id' => 1, 'token' => 'handshake-test-secret']);
        $this->assertStringStartsWith('HTTP/1.1 503 ', $response);
        $this->assertStringContainsString('service_unavailable', $response);
        $this->assertStringNotContainsString('private-server-secret', $response);
        Log::shouldHaveReceived('log')->once()->withArgs(fn ($level, $message, $context) =>
            $level === 'error' && $message === '[WS] 握手处理失败' && !str_contains(json_encode($context), 'private-server-secret'));
    }

    public function test_renewal_baseline_recovery_is_debug_but_actual_failure_stays_warning(): void
    {
        $node = Server::create(['name' => 'renewal-test', 'type' => 'vmess', 'host' => '127.0.0.1',
            'port' => 443, 'server_port' => 443, 'rate' => 1, 'group_ids' => [1], 'enabled' => true]);
        $conn = \Mockery::mock(TcpConnection::class);
        $conn->nodeId = (int) $node->id;
        $conn->shouldReceive('send')->twice()->withArgs(fn ($data) => json_decode($data, true)['data']['code'] === 412);
        NodeConnectionAuth::bind($conn, 'handshake-test-secret');
        NodeRegistry::add((int) $node->id, $conn);
        $worker = (new \ReflectionClass(NodeWorker::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(NodeWorker::class, 'handlers'))->setValue($worker, ['device.sync' => function () {
            throw new \Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException('snapshot required');
        }]);
        try {
            foreach ([true, false] as $unchanged) {
                $worker->onMessage($conn, json_encode(['event' => 'device.sync', 'data' => ['unchanged' => $unchanged]]));
            }
            foreach (['debug', 'warning'] as $expected) {
                Log::shouldHaveReceived('log')->withArgs(fn ($level, $message, $context) =>
                    $level === $expected && $context['code'] === 412 && $context['event'] === 'device.sync')->once();
            }
        } finally {
            NodeRegistry::remove((int) $node->id, $conn);
        }
    }
}
