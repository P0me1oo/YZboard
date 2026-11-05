<?php

namespace Tests\Feature\Client;

use App\Http\Controllers\V2\Admin\Server\ManageController;
use App\Models\Server;
use App\Protocols\General;
use App\Protocols\Shadowrocket;
use App\Services\ServerRelayService;
use App\Services\ServerService;
use App\Support\XhttpSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class XhttpSupportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Route::post('/_tests/xhttp/save', [ManageController::class, 'save']);
    }

    private function download(): array
    {
        return ['address' => 'download.example.com', 'port' => 443, 'network' => 'xhttp',
            'security' => 'tls', 'tlsSettings' => [], 'sockopt' => [],
            'xhttpSettings' => ['path' => '/', 'headers' => []]];
    }

    private function payload(array $settings): array
    {
        return ['name' => 'XHTTP 测试', 'type' => 'vless', 'host' => 'upload.example.com',
            'port' => 443, 'server_port' => 24443, 'rate' => 1, 'kernel_type' => 'xray',
            'protocol_settings' => $settings + ['network' => 'xhttp', 'tls' => 0, 'flow' => '']];
    }

    public function test_saved_root_settings_reach_both_subscription_formats_as_valid_objects(): void
    {
        $settings = ['network' => 'splithttp', 'tls' => 0, 'network_settings' => [
            'path' => '/', 'mode' => 'packet-up', 'headers' => [],
            'sessionPlacement' => 'query', 'sessionKey' => 'sid', 'downloadSettings' => $this->download(),
        ]];
        $this->postJson('/_tests/xhttp/save', $this->payload($settings))->assertOk();
        $server = Server::latest('id')->firstOrFail();
        $saved = $server->protocol_settings;
        $this->assertSame('xhttp', $saved['network']);
        $this->assertSame('query', $saved['network_settings']['sessionIDPlacement']);
        $this->assertArrayNotHasKey('sessionPlacement', $saved['network_settings']);
        $uuid = (string) Str::uuid();
        foreach ([General::class, Shadowrocket::class] as $protocol) {
            $link = $protocol::buildVless($uuid, $server->toArray());
            parse_str(parse_url(trim($link), PHP_URL_QUERY), $query);
            $extra = json_decode($query['extra'], false, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('query', $extra->sessionIDPlacement);
            $this->assertSame('download.example.com', $extra->downloadSettings->address);
            $this->assertInstanceOf(\stdClass::class, $extra->headers);
            $this->assertInstanceOf(\stdClass::class, $extra->downloadSettings->tlsSettings);
            $this->assertInstanceOf(\stdClass::class, $extra->downloadSettings->sockopt);
        }
    }

    public function test_extra_priority_and_legacy_connection_lifetime_are_preserved(): void
    {
        $normalized = XhttpSettings::normalize(['path' => '/outer', 'sessionIDPlacement' => 'query',
            'extra' => ['path' => '/ignored', 'xmux' => ['cMaxLifetimeMs' => '1000-3000']]]);
        $this->assertSame('/outer', $normalized['path']);
        $this->assertArrayNotHasKey('sessionIDPlacement', $normalized);
        $this->assertSame('1-3', $normalized['xmux']['hMaxReusableSecs']);
        $this->assertArrayNotHasKey('cMaxLifetimeMs', $normalized['xmux']);
    }

    public function test_invalid_xhttp_combinations_fail_before_saving(): void
    {
        $bad = [
            ['mode' => 'invalid'],
            ['mode' => 'stream-one', 'downloadSettings' => $this->download()],
            ['downloadSettings' => ['address' => '', 'port' => 443]],
            ['xmux' => ['maxConcurrency' => 3, 'maxConnections' => 3]],
            ['headers' => ['Host' => 'wrong.example']],
            ['uplinkDataPlacement' => 'cookie', 'mode' => 'auto'],
            ['sessionIDTable' => 'Base62', 'sessionIDLength' => '1-2'],
            ['headers' => ['array']],
            [123],
        ];
        foreach ($bad as $settings) {
            $this->postJson('/_tests/xhttp/save', $this->payload(['network_settings' => $settings]))
                ->assertUnprocessable()->assertJsonValidationErrors('protocol_settings.network_settings');
        }
        $this->assertSame(0, Server::count());
    }

    public function test_reality_download_uses_default_fingerprint_and_numeric_port(): void
    {
        $download = ['address' => 'download.example.com', 'port' => '443', 'network' => 'xhttp',
            'security' => 'reality', 'realitySettings' => ['publicKey' => rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=')]];
        $this->postJson('/_tests/xhttp/save', $this->payload(['network_settings' => ['downloadSettings' => $download]]))->assertOk();
        $saved = data_get(Server::latest('id')->firstOrFail()->protocol_settings, 'network_settings.downloadSettings');
        $this->assertSame(443, $saved['port']);
        $this->assertSame('chrome', $saved['realitySettings']['fingerprint']);
    }

    public function test_vision_and_h3_constraints_are_validated_and_alpn_is_downstream(): void
    {
        $this->postJson('/_tests/xhttp/save', $this->payload(['flow' => 'xtls-rprx-vision']))
            ->assertUnprocessable()->assertJsonValidationErrors('protocol_settings.flow');
        $this->postJson('/_tests/xhttp/save', $this->payload(['tls' => 0, 'tls_settings' => ['alpn' => ['h3']]]))
            ->assertUnprocessable()->assertJsonValidationErrors('protocol_settings.tls_settings.alpn');
        $this->postJson('/_tests/xhttp/save', $this->payload(['tls' => 1, 'tls_settings' => ['alpn' => ['h3']]]))->assertOk();
        $server = Server::latest('id')->firstOrFail();
        $this->assertSame(['h3'], data_get(ServerService::buildNodeConfig($server), 'tls_settings.alpn'));
        foreach ([General::class, Shadowrocket::class] as $protocol) {
            parse_str(parse_url(trim($protocol::buildVless((string) Str::uuid(), $server->toArray())), PHP_URL_QUERY), $query);
            $this->assertSame('h3', $query['alpn']);
        }
        $this->assertSame(['h3'], \App\Protocols\ClashMeta::buildVless((string) Str::uuid(), $server->toArray())['alpn']);
        $this->assertFalse(Server::supportsProxyProtocol('vless', $server->protocol_settings));
        $this->assertSame(['udp'], \App\Services\ServerPortService::transports($server));
    }

    public function test_relay_keeps_root_client_fields_and_removes_server_secrets(): void
    {
        $server = Server::create($this->payload(['tls' => 0, 'network_settings' => [
            'sessionIDPlacement' => 'query', 'sessionIDKey' => 'sid', 'downloadSettings' => $this->download(),
            'h2Params' => ['MaxConcurrentStreams' => 100], 'noSSEHeader' => true,
        ]]) + ['vless_route' => 100]);
        $config = ServerRelayService::vlessClientConfig($server);
        $this->assertSame('sid', data_get($config, 'network_settings.sessionIDKey'));
        $this->assertSame('download.example.com', data_get($config, 'network_settings.downloadSettings.address'));
        $this->assertArrayNotHasKey('h2Params', $config['network_settings']);
        $this->assertArrayNotHasKey('noSSEHeader', $config['network_settings']);
    }
}
