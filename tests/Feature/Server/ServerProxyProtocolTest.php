<?php

namespace Tests\Feature\Server;

use App\Http\Controllers\V2\Admin\Server\ManageController;
use App\Models\Server;
use App\Services\ServerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ServerProxyProtocolTest extends TestCase
{
    use RefreshDatabase;

    private array $pushes = [];

    protected function setUp(): void
    {
        parent::setUp();
        Route::post('/_tests/proxy/save', [ManageController::class, 'save']);
        Redis::shouldReceive('publish')->byDefault()->andReturnUsing(function ($channel, $message) {
            $this->pushes[] = json_decode($message, true);
            return 1;
        });
    }

    public function test_save_push_repeat_disable_and_other_nodes_remain_independent(): void
    {
        $this->postJson('/_tests/proxy/save', $this->payload())->assertOk();
        $other = Server::latest('id')->firstOrFail();
        $this->assertFalse($other->accept_proxy_protocol);
        $this->postJson('/_tests/proxy/save', $this->payload(['accept_proxy_protocol' => true]))->assertOk();
        $node = Server::latest('id')->firstOrFail();
        $this->assertTrue(ServerService::buildNodeConfig($node)['accept_proxy_protocol']);
        $this->assertArrayNotHasKey('device_ip_exclude', ServerService::buildNodeConfig($node));
        Cache::put("node_ws_alive:{$node->id}", true);
        $payload = $this->payload(['id' => $node->id, 'accept_proxy_protocol' => false]);
        $this->postJson('/_tests/proxy/save', $payload)->assertOk();
        $push = collect($this->pushes)->where('event', 'sync.config')->last();
        $this->assertFalse($push['data']['config']['accept_proxy_protocol']);
        $this->assertFalse(ServerService::buildNodeConfig($other->fresh())['accept_proxy_protocol']);
        $this->pushes = [];
        $this->postJson('/_tests/proxy/save', $payload)->assertOk();
        $this->assertEmpty($this->pushes);
        unset($payload['accept_proxy_protocol']);
        $this->postJson('/_tests/proxy/save', $payload)->assertOk();
        $this->assertFalse($node->fresh()->accept_proxy_protocol, '旧调用省略开关不得覆盖已保存状态');
    }

    public function test_old_nodes_keep_legacy_settings_and_statistics_exclusion(): void
    {
        admin_setting(['device_ip_exclude' => ['203.0.114.7']]);
        $node = Server::create($this->payload([
            'type' => 'vless', 'protocol_settings' => [
                'tls' => 0, 'network' => 'tcp', 'network_settings' => ['acceptProxyProtocol' => true],
            ],
        ]));
        $legacy = ServerService::buildNodeConfig($node);
        $this->assertArrayNotHasKey('accept_proxy_protocol', $legacy);
        $this->assertTrue($legacy['networkSettings']['acceptProxyProtocol']);
        $this->assertSame(['203.0.114.7'], $legacy['device_ip_exclude']);
        foreach ([true, false] as $enabled) {
            $node->update(['accept_proxy_protocol' => $enabled]);
            $config = ServerService::buildNodeConfig($node->fresh());
            $this->assertSame($enabled, $config['accept_proxy_protocol']);
            $this->assertSame(['203.0.114.7'], $config['device_ip_exclude']);
        }
    }

    public function test_invalid_switch_and_non_tcp_transports_are_rejected(): void
    {
        $this->postJson('/_tests/proxy/save', $this->payload(['accept_proxy_protocol' => 'invalid']))
            ->assertUnprocessable()->assertJsonValidationErrors('accept_proxy_protocol');
        foreach ([
            ['type' => 'hysteria', 'protocol_settings' => ['version' => 2]],
            ['type' => 'vless', 'protocol_settings' => ['tls' => 0, 'network' => 'kcp']],
            ['type' => 'mieru', 'protocol_settings' => ['transport' => 'TCP']],
        ] as $override) {
            $this->postJson('/_tests/proxy/save', $this->payload($override + ['accept_proxy_protocol' => true]))
                ->assertUnprocessable()->assertJsonValidationErrors('accept_proxy_protocol');
        }
        $this->assertSame(0, Server::count());
    }

    public function test_changing_to_udp_requires_disabling_previously_enabled_receiver(): void
    {
        $this->postJson('/_tests/proxy/save', $this->payload(['accept_proxy_protocol' => true]))->assertOk();
        $node = Server::latest('id')->firstOrFail();
        $payload = $this->payload([
            'id' => $node->id, 'type' => 'hysteria', 'protocol_settings' => ['version' => 2],
        ]);
        $this->postJson('/_tests/proxy/save', $payload)->assertUnprocessable()
            ->assertJsonValidationErrors('accept_proxy_protocol');
        $this->assertSame('shadowsocks', $node->fresh()->type);
        $payload['accept_proxy_protocol'] = false;
        $this->postJson('/_tests/proxy/save', $payload)->assertOk();
        $this->assertFalse($node->fresh()->accept_proxy_protocol);
    }

    private function payload(array $override = []): array
    {
        return array_replace([
            'name' => 'PROXY 接收测试', 'type' => 'shadowsocks', 'host' => 'proxy.example.invalid',
            'port' => '32443', 'server_port' => 32443, 'rate' => 1,
            'protocol_settings' => ['cipher' => 'aes-128-gcm'],
        ], $override);
    }
}
