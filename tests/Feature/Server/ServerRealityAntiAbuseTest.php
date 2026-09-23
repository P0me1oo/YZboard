<?php

namespace Tests\Feature\Server;

use App\Http\Controllers\V2\Admin\Server\ManageController;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Services\ServerService;
use App\Support\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * REALITY 防盗用开关的验收：管理端能存、能改、能下发给节点，非法取值不落库。
 */
class ServerRealityAntiAbuseTest extends TestCase
{
    use RefreshDatabase;

    // 测试专用的占位密钥，不是任何真实节点的凭据。
    private const REALITY_PUBLIC_KEY = 'TESTonlyPUBLICkeyNOTaREALsecret0123456789ab';
    private const REALITY_PRIVATE_KEY = 'TESTonlyPRIVATEkeyNOTaREALsecret0123456789';

    protected function setUp(): void
    {
        parent::setUp();
        Route::post('/_tests/reality-anti-abuse/save', [ManageController::class, 'save']);
    }

    private function payload(string $type = Server::TYPE_VLESS, array $realityOverrides = [], array $overrides = []): array
    {
        return array_replace([
            'name' => '防盗用测试节点',
            'type' => $type,
            'host' => 'reality.example.invalid',
            'port' => '443',
            'server_port' => 443,
            'rate' => 1,
            'enabled' => true,
            'protocol_settings' => [
                'tls' => 2,
                'network' => 'tcp',
                'flow' => 'xtls-rprx-vision',
                'reality_settings' => array_replace([
                    'server_name' => 'www.example.com',
                    'server_port' => '443',
                    'public_key' => self::REALITY_PUBLIC_KEY,
                    'private_key' => self::REALITY_PRIVATE_KEY,
                    'short_id' => '0123abcd',
                ], $realityOverrides),
            ],
        ], $overrides);
    }

    public function test_switch_is_saved_and_delivered_to_the_node(): void
    {
        foreach ([Server::TYPE_VLESS, Server::TYPE_TROJAN] as $type) {
            $this->postJson('/_tests/reality-anti-abuse/save', $this->payload($type, ['anti_abuse' => true]))->assertOk();

            $server = Server::latest('id')->firstOrFail();
            $this->assertTrue(
                data_get($server->protocol_settings, 'reality_settings.anti_abuse'),
                $type . ' 未保存防盗用开关'
            );

            // 面板把整个 reality_settings 作为 tls_settings 下发，Node 据此改写伪装回源。
            $config = ServerService::buildNodeConfig($server);
            $this->assertTrue(data_get($config, 'tls_settings.anti_abuse'), $type . ' 未把防盗用开关下发给节点');
            $this->assertSame('www.example.com', data_get($config, 'tls_settings.server_name'));
        }
    }

    public function test_switch_defaults_to_off_and_can_be_turned_back_off(): void
    {
        // 老节点没有这个字段，读出来必须是关闭而不是 null。
        $this->postJson('/_tests/reality-anti-abuse/save', $this->payload())->assertOk();
        $server = Server::latest('id')->firstOrFail();
        $this->assertFalse(data_get($server->protocol_settings, 'reality_settings.anti_abuse'));
        $this->assertFalse(data_get(ServerService::buildNodeConfig($server), 'tls_settings.anti_abuse'));

        $this->postJson('/_tests/reality-anti-abuse/save', $this->payload(
            realityOverrides: ['anti_abuse' => true],
            overrides: ['id' => $server->id]
        ))->assertOk();
        $this->assertTrue(data_get($server->fresh()->protocol_settings, 'reality_settings.anti_abuse'));

        $this->postJson('/_tests/reality-anti-abuse/save', $this->payload(
            realityOverrides: ['anti_abuse' => false],
            overrides: ['id' => $server->id]
        ))->assertOk();
        $this->assertFalse(data_get($server->fresh()->protocol_settings, 'reality_settings.anti_abuse'));
    }

    public function test_invalid_switch_value_is_rejected_and_keeps_the_saved_state(): void
    {
        $this->postJson('/_tests/reality-anti-abuse/save', $this->payload(realityOverrides: ['anti_abuse' => true]))->assertOk();
        $server = Server::latest('id')->firstOrFail();

        $this->postJson('/_tests/reality-anti-abuse/save', $this->payload(
            realityOverrides: ['anti_abuse' => 'maybe'],
            overrides: ['id' => $server->id]
        ))->assertUnprocessable()->assertJsonValidationErrors('protocol_settings.reality_settings.anti_abuse');

        $this->assertTrue(data_get($server->fresh()->protocol_settings, 'reality_settings.anti_abuse'));
    }

    public function test_node_config_endpoints_expose_the_switch(): void
    {
        $credential = bin2hex(random_bytes(24));
        $machine = ServerMachine::create(['name' => '防盗用测试机器', 'token' => $credential, 'is_active' => true]);

        $this->postJson('/_tests/reality-anti-abuse/save', $this->payload(
            realityOverrides: ['anti_abuse' => true],
            overrides: ['machine_id' => $machine->id]
        ))->assertOk();
        $server = Server::latest('id')->firstOrFail();

        $this->mock(Setting::class, function (MockInterface $mock) use ($credential): void {
            $mock->shouldReceive('get')->andReturnUsing(fn(string $key) => $key === 'server_token' ? $credential : null);
        });

        $this->getJson('/api/v2/server/config?' . http_build_query([
            'machine_id' => $machine->id,
            'token' => $credential,
            'node_id' => $server->id,
        ]))->assertOk()->assertJsonPath('tls_settings.anti_abuse', true);

        $this->getJson('/api/v1/server/UniProxy/config?' . http_build_query([
            'token' => $credential,
            'node_id' => $server->id,
        ]))->assertOk()->assertJsonPath('tls_settings.anti_abuse', true);
    }
}
