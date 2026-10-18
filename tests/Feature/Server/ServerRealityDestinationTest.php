<?php

namespace Tests\Feature\Server;

use App\Http\Controllers\V2\Admin\Server\ManageController;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Protocols\ClashMeta;
use App\Protocols\General;
use App\Protocols\SingBox;
use App\Services\ServerService;
use App\Support\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ServerRealityDestinationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Route::post('/_tests/reality-destination/save', [ManageController::class, 'save']);
    }

    private function payload(array $reality = [], array $overrides = []): array
    {
        return array_replace([
            'name' => '独立回源测试', 'type' => 'vless', 'host' => 'node.example.com',
            'port' => '443', 'server_port' => 443, 'rate' => 1, 'kernel_type' => 'xray',
            'protocol_settings' => [
                'tls' => 2, 'network' => 'tcp', 'flow' => 'xtls-rprx-vision',
                'reality_settings' => array_replace([
                    'dest' => '127.0.0.1:8443', 'server_name' => 'sni.example.com', 'server_port' => 443,
                    // 测试占位值，不是可用的节点密钥。
                    'public_key' => 'TEST-ONLY-PUBLIC-KEY', 'private_key' => 'TEST-ONLY-PRIVATE-KEY',
                    'short_id' => '0123abcd', 'anti_abuse' => false,
                ], $reality),
            ],
        ], $overrides);
    }

    public function test_independent_destination_survives_save_reload_and_repeat_save_for_both_kernels(): void
    {
        foreach (['xray', 'singbox'] as $kernel) {
            $payload = $this->payload(overrides: ['kernel_type' => $kernel]);
            $this->postJson('/_tests/reality-destination/save', $payload)->assertOk();
            $server = Server::latest('id')->firstOrFail();
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $settings = $server->fresh()->protocol_settings;
                $this->assertSame('127.0.0.1:8443', data_get($settings, 'reality_settings.dest'));
                $this->assertSame('sni.example.com', data_get($settings, 'reality_settings.server_name'));
                $config = ServerService::buildNodeConfig($server->fresh());
                $this->assertSame('127.0.0.1:8443', data_get($config, 'tls_settings.dest'));
                $this->assertSame('sni.example.com', data_get($config, 'tls_settings.server_name'));
                $this->postJson('/_tests/reality-destination/save', array_replace($payload, [
                    'id' => $server->id, 'protocol_settings' => $settings,
                ]))->assertOk();
            }
        }
    }

    public function test_legacy_or_cleared_destination_uses_sni_and_legacy_port(): void
    {
        foreach ([null, ''] as $dest) {
            $this->postJson('/_tests/reality-destination/save', $this->payload([
                'dest' => $dest, 'server_port' => 9443,
            ]))->assertOk();
            $server = Server::latest('id')->firstOrFail();
            $this->assertSame('sni.example.com:9443', data_get(ServerService::buildNodeConfig($server), 'tls_settings.dest'));
        }
        // 模拟数据库里完全没有新增字段的历史记录。
        $payload = $this->payload();
        unset($payload['protocol_settings']['reality_settings']['dest'], $payload['protocol_settings']['reality_settings']['server_port']);
        $this->postJson('/_tests/reality-destination/save', $payload)->assertOk();
        $server = Server::latest('id')->firstOrFail();
        Server::whereKey($server->id)->update(['protocol_settings' => json_encode($payload['protocol_settings'])]);
        $this->assertSame('sni.example.com:443', data_get(ServerService::buildNodeConfig($server->fresh()), 'tls_settings.dest'));
    }

    public static function invalidDestinations(): array
    {
        return array_map(fn ($value) => [$value], [
            '127.0.0.1', 'https://example.com:443', '127.0.0.1:0', '127.0.0.1:65536',
            'example.com:443/path', 'name@127.0.0.1:443', 'example.com:abc', ['127.0.0.1:443'],
        ]);
    }

    #[DataProvider('invalidDestinations')]
    public function test_invalid_destination_is_rejected_without_overwriting_saved_configuration(mixed $dest): void
    {
        $this->postJson('/_tests/reality-destination/save', $this->payload())->assertOk();
        $server = Server::latest('id')->firstOrFail();
        $this->postJson('/_tests/reality-destination/save', $this->payload(['dest' => $dest], ['id' => $server->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('protocol_settings.reality_settings.dest');
        $this->assertSame('127.0.0.1:8443', data_get($server->fresh()->protocol_settings, 'reality_settings.dest'));
    }

    public function test_anti_abuse_conflict_is_rejected_and_can_be_corrected(): void
    {
        $this->postJson('/_tests/reality-destination/save', $this->payload(['anti_abuse' => true]))
            ->assertUnprocessable()->assertJsonValidationErrors('protocol_settings.reality_settings.dest');
        $this->assertSame(0, Server::count());
        $this->postJson('/_tests/reality-destination/save', $this->payload([
            'anti_abuse' => true, 'dest' => 'sni.example.com:8443',
        ]))->assertOk();
        $server = Server::latest('id')->firstOrFail();
        $this->postJson('/_tests/reality-destination/save', $this->payload(overrides: ['id' => $server->id]))->assertOk();
        $this->assertFalse(data_get($server->fresh()->protocol_settings, 'reality_settings.anti_abuse'));
    }

    public function test_ipv6_destination_is_accepted_by_xray_and_rejected_for_the_existing_singbox_adapter(): void
    {
        $this->postJson('/_tests/reality-destination/save', $this->payload(['dest' => '[::1]:8443']))->assertOk();
        $server = Server::latest('id')->firstOrFail();
        $this->assertSame('[::1]:8443', data_get(ServerService::buildNodeConfig($server), 'tls_settings.dest'));
        $this->postJson('/_tests/reality-destination/save', $this->payload(
            ['dest' => '[::1]:8443'], ['id' => $server->id, 'kernel_type' => 'singbox'],
        ))->assertUnprocessable()->assertJsonValidationErrors('protocol_settings.reality_settings.dest');
        $this->assertSame('xray', $server->fresh()->kernel_type);
    }

    public function test_both_node_api_versions_deliver_the_independent_fields(): void
    {
        $credential = bin2hex(random_bytes(24));
        $machine = ServerMachine::create(['name' => '独立回源测试机器', 'token' => $credential, 'is_active' => true]);
        $this->postJson('/_tests/reality-destination/save', $this->payload(overrides: ['machine_id' => $machine->id]))->assertOk();
        $server = Server::latest('id')->firstOrFail();
        $this->mock(Setting::class, function (MockInterface $mock) use ($credential): void {
            $mock->shouldReceive('get')->andReturnUsing(fn (string $key) => $key === 'server_token' ? $credential : null);
        });
        foreach (['/api/v2/server/config', '/api/v1/server/UniProxy/config'] as $endpoint) {
            $this->getJson($endpoint . '?' . http_build_query([
                'machine_id' => $machine->id, 'node_id' => $server->id, 'token' => $credential,
            ]))->assertOk()->assertJsonPath('tls_settings.dest', '127.0.0.1:8443')
                ->assertJsonPath('tls_settings.server_name', 'sni.example.com');
        }
    }

    public function test_subscriptions_use_sni_and_do_not_export_the_origin_address(): void
    {
        $this->postJson('/_tests/reality-destination/save', $this->payload())->assertOk();
        $server = Server::latest('id')->firstOrFail()->toArray();
        $password = (string) Str::uuid();
        $link = General::buildVless($password, $server);
        parse_str(parse_url(trim($link), PHP_URL_QUERY), $query);
        $this->assertSame('sni.example.com', $query['sni']);
        $this->assertStringNotContainsString('127.0.0.1', $link);
        foreach ([ClashMeta::class => 'servername', SingBox::class => 'tls.server_name'] as $class => $path) {
            $protocol = new $class(['uuid' => $password], []);
            $method = new \ReflectionMethod($class, 'buildVless');
            $proxy = $method->invoke($protocol, $password, $server);
            $this->assertSame('sni.example.com', data_get($proxy, $path));
            $this->assertStringNotContainsString('127.0.0.1', json_encode($proxy));
            $this->assertArrayNotHasKey('dest', $proxy);
        }
    }
}
