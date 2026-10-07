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

class SourcePolicyTest extends TestCase
{
    use RefreshDatabase;

    private array $pushes = [];

    protected function setUp(): void
    {
        parent::setUp();
        Route::post('/_tests/source-policy/save', [ManageController::class, 'save']);
        Redis::shouldReceive('publish')->byDefault()->andReturnUsing(function ($channel, $message) {
            $this->pushes[] = json_decode($message, true);
            return 1;
        });
    }

    public function test_default_off_enable_update_disable_and_push(): void
    {
        $payload = $this->payload();
        $this->postJson('/_tests/source-policy/save', $payload)->assertOk();
        $node = Server::latest('id')->firstOrFail();
        $this->assertNull($node->source_policy);
        $this->assertArrayNotHasKey('source_policy', ServerService::buildNodeConfig($node));
        Cache::put("node_ws_alive:{$node->id}", true);
        $payload['id'] = $node->id;
        $payload['source_policy'] = ['block_cn' => true, 'allow_ips' => [' 198.51.100.7 ', '2001:DB8::7']];
        $this->postJson('/_tests/source-policy/save', $payload)->assertOk();
        $expected = ['block_cn' => true, 'allow_ips' => ['198.51.100.7', '2001:db8::7']];
        $this->assertSame($expected, $node->fresh()->source_policy);
        $this->assertSame($expected, ServerService::buildNodeConfig($node->fresh())['source_policy']);
        $push = collect($this->pushes)->where('event', 'sync.config')->last();
        $this->assertSame($expected, $push['data']['config']['source_policy']);
        $this->pushes = [];
        $this->postJson('/_tests/source-policy/save', $payload)->assertOk();
        $this->assertEmpty($this->pushes, '重复保存不应触发额外推送');
        unset($payload['source_policy']);
        $this->postJson('/_tests/source-policy/save', $payload)->assertOk();
        $this->assertSame($expected, $node->fresh()->source_policy, '旧调用省略字段应保留策略');
        $payload['source_policy'] = ['block_cn' => false, 'allow_ips' => $expected['allow_ips']];
        $this->postJson('/_tests/source-policy/save', $payload)->assertOk();
        $this->assertFalse($node->fresh()->source_policy['block_cn']);
        $push = collect($this->pushes)->where('event', 'sync.config')->last();
        $this->assertArrayNotHasKey('source_policy', $push['data']['config']);
    }

    public function test_invalid_policy_is_rejected_without_creating_node(): void
    {
        foreach (['example.invalid', '198.51.100.0/24', '0.0.0.0', '::ffff:0.0.0.0', 'ff02::1', '224.0.0.1'] as $ip) {
            $this->postJson('/_tests/source-policy/save', $this->payload([
                'source_policy' => ['block_cn' => true, 'allow_ips' => [$ip]],
            ]))->assertUnprocessable()->assertJsonValidationErrors('source_policy.allow_ips.0');
        }
        foreach ([['block_cn' => 'invalid'], ['allow_ips' => []], ['block_cn' => true, 'extra' => 1],
            ['block_cn' => true, 'allow_ips' => ['198.51.100.7', '::ffff:198.51.100.7']],
            ['block_cn' => true, 'allow_ips' => array_fill(0, 129, '198.51.100.7')]] as $policy) {
            $this->postJson('/_tests/source-policy/save', $this->payload(['source_policy' => $policy]))->assertUnprocessable();
        }
        $this->assertSame(0, Server::count());
    }

    public function test_policy_is_independent_per_node_and_direct_wireguard_accepts_enable(): void
    {
        $this->postJson('/_tests/source-policy/save', $this->payload(['source_policy' => ['block_cn' => true]]))->assertOk();
        $enabled = Server::latest('id')->firstOrFail();
        $this->postJson('/_tests/source-policy/save', $this->payload())->assertOk();
        $other = Server::latest('id')->firstOrFail();
        $this->assertArrayNotHasKey('source_policy', ServerService::buildNodeConfig($other));
        $this->assertTrue(ServerService::buildNodeConfig($enabled)['source_policy']['block_cn']);
        $this->postJson('/_tests/source-policy/save', $this->payload([
            'type' => 'wireguard', 'protocol_settings' => [], 'source_policy' => ['block_cn' => true],
        ]))->assertOk();
        $wg = Server::latest('id')->firstOrFail();
        $this->assertTrue(ServerService::buildNodeConfig($wg)['source_policy']['block_cn']);
    }

    private function payload(array $override = []): array
    {
        static $port = 32100;
        $port++;
        return array_replace([
            'name' => '来源拦截测试', 'type' => 'shadowsocks', 'host' => 'source.example.invalid',
            'port' => (string) $port, 'server_port' => $port, 'rate' => 1,
            'protocol_settings' => ['cipher' => 'aes-128-gcm'],
        ], $override);
    }
}
