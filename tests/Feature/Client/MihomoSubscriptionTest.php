<?php

namespace Tests\Feature\Client;

use App\Http\Controllers\V1\Client\ClientController;
use App\Http\Controllers\V2\Admin\Server\ManageController;
use App\Models\Server;
use App\Models\SubscribeTemplate;
use App\Protocols\ClashMeta;
use App\Support\Setting;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class MihomoSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private array $subscriptionUser;
    private array $echMaterial;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('v2board', []);
        $this->mock(Setting::class, function (MockInterface $mock): void {
            $mock->shouldReceive('get')->andReturnNull();
        });
        SubscribeTemplate::setContent('clashmeta', "proxies: []\nproxy-groups:\n  - name: SELECT\n    type: select\n    proxies: []\nrules:\n  - MATCH,DIRECT\n");
        $this->subscriptionUser = [
            'uuid' => (string) Str::uuid(), 'u' => 0, 'd' => 0,
            'transfer_enable' => 1024, 'expired_at' => null,
        ];
        $this->echMaterial = app(ManageController::class)
            ->generateEchKey(Request::create('/', 'GET', ['public_name' => 'public.ech.example']))
            ->getData(true)['data'];
    }

    private function node(string $type = 'vless', array $settings = []): array
    {
        $server = new Server();
        $server->forceFill([
            'type' => $type, 'name' => '测试节点-' . $type, 'host' => '127.0.0.1', 'port' => 443,
            'protocol_settings' => $settings + ['network' => 'tcp', 'tls' => 1],
        ]);
        return $server->toArray() + ['password' => $this->subscriptionUser['uuid']];
    }

    private function ech($enabled = true, bool $inline = true): array
    {
        return [
            'enabled' => $enabled,
            'config' => $inline ? $this->echMaterial['config'] : null,
            'query_server_name' => 'public.ech.example',
            'key' => $this->echMaterial['key'],
            'key_path' => '/test-only/private.pem',
            'config_path' => '/test-only/public.pem',
        ];
    }

    private function echNode(string $type, int $protocolVersion, $enabled = true, bool $inline = true): array
    {
        $tls = ['server_name' => 'service.example', 'ech' => $this->ech($enabled, $inline)];
        $settings = in_array($type, ['vless', 'vmess', 'trojan'], true)
            ? ['tls' => 1, 'tls_settings' => $tls]
            : ['tls' => $tls];
        return $this->node($type, $settings + [
            'version' => $protocolVersion, 'bandwidth' => ['up' => 10, 'down' => 10],
        ]);
    }

    private function proxies(array $nodes, string $client = 'meta', ?string $version = '1.19.30', ?string $agent = null): array
    {
        $yaml = (new ClashMeta($this->subscriptionUser, $nodes, $client, $version, $agent))->handle()->getContent();
        $this->assertFalse(str_contains($yaml, $this->echMaterial['key']), '订阅不能包含服务端 ECH 密钥');
        $this->assertStringNotContainsString('/test-only/', $yaml);
        return Yaml::parse($yaml)['proxies'];
    }

    public static function echProtocols(): array
    {
        return [
            'VLESS' => ['vless', 0], 'VMess' => ['vmess', 0], 'Trojan' => ['trojan', 0],
            'HY1' => ['hysteria', 1], 'HY2' => ['hysteria', 2],
            'TUIC' => ['tuic', 5], 'AnyTLS' => ['anytls', 0],
        ];
    }

    #[DataProvider('echProtocols')]
    public function test_ech_export_and_core_version_filter(string $type, int $protocolVersion): void
    {
        foreach ([true, 1, '1'] as $enabled) {
            $node = $this->echNode($type, $protocolVersion, $enabled);
            $this->assertCount(0, $this->proxies([$node], 'meta', '1.19.8'));
            $proxy = $this->proxies([$node], 'mihomo', '1.19.9')[0];
            $this->assertTrue($proxy['ech-opts']['enable']);
            $this->assertTrue($proxy['ech-opts']['config'] === Helper::toMihomoEchConfig($this->echMaterial['config']));
            $this->assertSame('public.ech.example', $proxy['ech-opts']['query-server-name']);
        }
        foreach ([false, 0, '0', null] as $disabled) {
            $proxy = $this->proxies([$this->echNode($type, $protocolVersion, $disabled)], 'meta', '1.19.8')[0];
            $this->assertArrayNotHasKey('ech-opts', $proxy);
        }
    }

    #[DataProvider('echProtocols')]
    public function test_ech_dns_query_name_has_its_own_version_requirement(string $type, int $protocolVersion): void
    {
        $node = $this->echNode($type, $protocolVersion, true, false);
        $this->assertCount(0, $this->proxies([$node], 'meta', '1.19.19'));
        $proxy = $this->proxies([$node], 'meta', '1.19.20')[0];
        $this->assertSame(['enable' => true, 'query-server-name' => 'public.ech.example'], $proxy['ech-opts']);

        $path = in_array($type, ['vless', 'vmess', 'trojan'], true) ? 'tls_settings.ech' : 'tls.ech';
        data_set($node, 'protocol_settings.' . $path . '.query_server_name', null);
        $this->assertCount(1, $this->proxies([$node], 'meta', '1.19.9'));
    }

    public function test_application_versions_and_unknown_core_versions_preserve_ech_nodes(): void
    {
        $node = $this->echNode('vless', 0);
        foreach ([['flclash', '0.8.0'], ['verge', '1.3.8'], ['clashmetaforandroid', '2.11.9'], ['meta', null]] as [$client, $version]) {
            $this->assertCount(1, $this->proxies([$node], $client, $version));
        }
        $this->assertCount(1, $this->proxies([$node], 'meta', '2.4.5', 'clash-verge/2.4.5 clash.meta/alpha-test'));
        $this->assertCount(1, $this->proxies([$node], 'flclash', '0.8.0', 'FlClash/0.8.0 mihomo/v1.19.9'));
        $this->assertCount(0, $this->proxies([$node], 'flclash', '0.8.0', 'FlClash/0.8.0 mihomo/v1.19.8'));
    }

    public function test_xhttp_basic_modes_require_supported_core_and_keep_node_identity(): void
    {
        foreach (['auto', 'stream-one', 'stream-up', 'packet-up'] as $mode) {
            $node = $this->node('vless', [
                'network' => 'xhttp', 'network_settings' => ['path' => '/audit', 'host' => 'transport.example', 'mode' => $mode],
            ]);
            $this->assertCount(0, $this->proxies([$node], 'meta', '1.19.21'));
            $proxy = $this->proxies([$node], 'meta', '1.19.22')[0];
            $this->assertSame('xhttp', $proxy['network']);
            $this->assertSame($mode, $proxy['xhttp-opts']['mode']);
            $this->assertSame('/audit', $proxy['xhttp-opts']['path']);
            $this->assertTrue($proxy['uuid'] === $node['password']);
            $this->assertCount(1, $this->proxies([$node], 'flclash', '0.8.0'));
        }
    }

    public function test_xhttp_extra_priority_and_advanced_values_survive_subscription_serialization(): void
    {
        $extra = [
            'path' => '/ignored', 'host' => 'ignored.example', 'mode' => 'ignored',
            'headers' => ['X-Transport' => 'extra'],
            'noGRPCHeader' => false, 'xPaddingBytes' => '200-300', 'xPaddingObfsMode' => true,
            'xPaddingKey' => 'pad', 'xPaddingHeader' => 'X-Pad', 'xPaddingPlacement' => 'header',
            'xPaddingMethod' => 'tokenish', 'uplinkHTTPMethod' => 'PUT',
            'sessionIDPlacement' => 'query', 'sessionIDKey' => 'sid', 'seqPlacement' => 'query', 'seqKey' => 'seq',
            'uplinkDataPlacement' => 'header', 'uplinkDataKey' => 'payload', 'uplinkChunkSize' => 4096,
            'scMaxEachPostBytes' => '500000-1000000', 'scMinPostsIntervalMs' => 0,
            'xmux' => ['maxConcurrency' => '2-4', 'maxConnections' => 0, 'cMaxReuseTimes' => 0,
                'hMaxRequestTimes' => '600-900', 'hMaxReusableSecs' => '1800-3000', 'hKeepAlivePeriod' => -1],
            'noSSEHeader' => true, 'scMaxBufferedPosts' => 50, 'privateKey' => $this->echMaterial['key'],
        ];
        $node = $this->node('vless', ['network' => 'xhttp', 'network_settings' => [
            'path' => '/audit', 'host' => 'transport.example', 'mode' => 'packet-up',
            'headers' => ['X-Transport' => 'outer'], 'extra' => $extra,
        ]]);
        $before = serialize($node);
        $this->assertCount(0, $this->proxies([$node], 'meta', '1.19.23'));
        $proxy = $this->proxies([$node], 'meta', '1.19.24')[0];
        $options = $proxy['xhttp-opts'];
        $this->assertSame('/audit', $options['path']);
        $this->assertSame('transport.example', $options['host']);
        $this->assertSame('packet-up', $options['mode']);
        $this->assertSame(['X-Transport' => 'extra'], $options['headers']);
        $this->assertFalse($options['no-grpc-header']);
        $this->assertTrue($options['x-padding-obfs-mode']);
        $this->assertSame('200-300', $options['x-padding-bytes']);
        $this->assertSame('header', $options['x-padding-placement']);
        $this->assertSame('tokenish', $options['x-padding-method']);
        $this->assertSame('query', $options['session-placement']);
        $this->assertSame('sid', $options['session-key']);
        $this->assertSame('4096', $options['uplink-chunk-size']);
        $this->assertSame('30', $options['sc-min-posts-interval-ms']);
        $this->assertSame('0', $options['reuse-settings']['max-connections']);
        $this->assertSame(-1, $options['reuse-settings']['h-keep-alive-period']);
        $this->assertArrayNotHasKey('noSSEHeader', $options);
        $this->assertArrayNotHasKey('scMaxBufferedPosts', $options);
        $this->assertArrayNotHasKey('privateKey', $options);
        $this->assertTrue(serialize($node) === $before, '生成订阅不能修改原节点设置');
        $this->assertTrue($proxy === $this->proxies([$node], 'meta', '1.19.24')[0], '重复生成应得到相同结果');
    }

    public function test_xhttp_root_options_and_reuse_versions_are_supported(): void
    {
        $node = $this->node('vless', ['network' => 'xhttp', 'network_settings' => [
            'path' => '/audit', 'headers' => ['X-Transport' => 'root'], 'noGRPCHeader' => true,
        ]]);
        $options = $this->proxies([$node], 'meta', '1.19.22')[0]['xhttp-opts'];
        $this->assertSame(['X-Transport' => 'root'], $options['headers']);
        $this->assertTrue($options['no-grpc-header']);

        data_set($node, 'protocol_settings.network_settings.xmux', ['maxConcurrency' => '2-4']);
        $this->assertCount(0, $this->proxies([$node], 'meta', '1.19.22'));
        $this->assertCount(1, $this->proxies([$node], 'meta', '1.19.23'));
        data_set($node, 'protocol_settings.network_settings.xmux.hKeepAlivePeriod', 0);
        $this->assertCount(0, $this->proxies([$node], 'meta', '1.19.23'));
        $this->assertCount(1, $this->proxies([$node], 'meta', '1.19.24'));
    }

    private function downloadSettings(string $security = 'tls'): array
    {
        return [
            'address' => '127.0.0.1', 'port' => 8443, 'network' => 'xhttp', 'security' => $security,
            'xhttpSettings' => ['path' => '/download', 'host' => 'download.example'],
            'tlsSettings' => ['serverName' => 'download.example', 'allowInsecure' => false, 'fingerprint' => 'chrome',
                'certificates' => [['key' => $this->echMaterial['key']]], 'echServerKeys' => $this->echMaterial['key']],
        ];
    }

    public function test_xhttp_download_tls_ech_and_reality_are_independent_of_upload(): void
    {
        $node = $this->echNode('vless', 0);
        data_set($node, 'protocol_settings.network', 'xhttp');
        data_set($node, 'protocol_settings.network_settings', [
            'path' => '/upload', 'host' => 'upload.example', 'mode' => 'stream-up',
            'extra' => ['headers' => ['X-Upload' => 'only'], 'downloadSettings' => $this->downloadSettings()],
        ]);
        $proxy = $this->proxies([$node], 'meta', '1.19.22')[0];
        $download = $proxy['xhttp-opts']['download-settings'];
        $this->assertSame(8443, $download['port']);
        $this->assertSame('/download', $download['path']);
        $this->assertSame('download.example', $download['servername']);
        $this->assertSame([], $download['headers']);
        $this->assertTrue($download['tls']);
        $this->assertFalse($download['skip-cert-verify']);
        $this->assertFalse($download['ech-opts']['enable']);
        $this->assertSame('', $download['reality-opts']['public-key']);
        $this->assertTrue($proxy['ech-opts']['enable']);
        $this->assertArrayNotHasKey('certificates', $download);

        data_set($node, 'protocol_settings.network_settings.extra.downloadSettings.tlsSettings.echConfigList',
            Helper::toMihomoEchConfig($this->echMaterial['config']));
        $download = $this->proxies([$node])[0]['xhttp-opts']['download-settings'];
        $this->assertTrue($download['ech-opts']['enable']);
        $this->assertTrue($download['ech-opts']['config'] === Helper::toMihomoEchConfig($this->echMaterial['config']));

        data_set($node, 'protocol_settings.network_settings.extra.downloadSettings', $this->downloadSettings('none'));
        $download = $this->proxies([$node])[0]['xhttp-opts']['download-settings'];
        $this->assertFalse($download['tls']);
        $this->assertFalse($download['ech-opts']['enable']);
        $this->assertSame('', $download['servername']);
        $this->assertSame('', $download['client-fingerprint']);
        $this->assertSame(['http/1.1'], $download['alpn']);
        $this->assertCount(0, $this->proxies([$node], 'meta', '1.19.23'));

        $publicKey = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $settings = $this->downloadSettings('reality') + ['realitySettings' => [
            'serverName' => 'reality.example', 'publicKey' => $publicKey, 'shortId' => bin2hex(random_bytes(4)),
            'fingerprint' => 'chrome', 'privateKey' => $this->echMaterial['key'],
        ]];
        data_set($node, 'protocol_settings.network_settings.extra.downloadSettings', $settings);
        $download = $this->proxies([$node])[0]['xhttp-opts']['download-settings'];
        $this->assertTrue($download['reality-opts']['public-key'] === $publicKey);
        $this->assertSame('reality.example', $download['servername']);
        $this->assertFalse($download['ech-opts']['enable']);
    }

    public function test_xhttp_download_h3_requires_its_supported_core_version(): void
    {
        $settings = $this->downloadSettings();
        $settings['tlsSettings']['alpn'] = ['h3'];
        $node = $this->node('vless', ['network' => 'xhttp', 'network_settings' => [
            'mode' => 'packet-up', 'extra' => ['downloadSettings' => $settings],
        ]]);
        $this->assertCount(0, $this->proxies([$node], 'meta', '1.19.23'));
        $this->assertSame(['h3'], $this->proxies([$node], 'meta', '1.19.24')[0]['xhttp-opts']['download-settings']['alpn']);
    }

    public function test_xhttp_ranges_follow_xray_defaults_and_order(): void
    {
        $node = $this->node('vless', ['network' => 'xhttp', 'network_settings' => [
            'xPaddingBytes' => 0, 'scMaxEachPostBytes' => '0-0', 'scMinPostsIntervalMs' => '',
            'xmux' => ['maxConcurrency' => '4-2', 'cMaxReuseTimes' => 0],
        ]]);
        $options = $this->proxies([$node])[0]['xhttp-opts'];
        $this->assertSame('100-1000', $options['x-padding-bytes']);
        $this->assertSame('1000000', $options['sc-max-each-post-bytes']);
        $this->assertSame('30', $options['sc-min-posts-interval-ms']);
        $this->assertSame('2-4', $options['reuse-settings']['max-concurrency']);
        $this->assertSame('0', $options['reuse-settings']['c-max-reuse-times']);

        data_set($node, 'protocol_settings.network_settings.xmux', []);
        $reuse = $this->proxies([$node])[0]['xhttp-opts']['reuse-settings'];
        $this->assertSame('6', $reuse['max-connections']);
        $this->assertSame('600-900', $reuse['h-max-request-times']);
    }

    public function test_xhttp_download_reuse_does_not_inherit_upload_and_works_on_its_own(): void
    {
        $node = $this->node('vless', ['network' => 'xhttp', 'network_settings' => [
            'xmux' => ['maxConnections' => 2, 'hKeepAlivePeriod' => -1],
            'downloadSettings' => $this->downloadSettings(),
        ]]);
        $options = $this->proxies([$node])[0]['xhttp-opts'];
        $this->assertSame('2', $options['reuse-settings']['max-connections']);
        $this->assertSame('6', $options['download-settings']['reuse-settings']['max-connections']);
        $this->assertArrayNotHasKey('h-keep-alive-period', $options['download-settings']['reuse-settings']);

        unset($node['protocol_settings']['network_settings']['xmux']);
        data_set($node, 'protocol_settings.network_settings.downloadSettings.xhttpSettings.xmux', ['maxConnections' => 3]);
        $options = $this->proxies([$node])[0]['xhttp-opts'];
        $this->assertSame('6', $options['reuse-settings']['max-connections']);
        $this->assertSame('3', $options['download-settings']['reuse-settings']['max-connections']);
        $this->assertCount(0, $this->proxies([$node], 'meta', '1.19.22'));
        $this->assertCount(1, $this->proxies([$node], 'meta', '1.19.23'));
    }

    public function test_xhttp_padding_and_upload_headers_receive_xray_defaults(): void
    {
        $node = $this->node('vless', ['network' => 'xhttp', 'network_settings' => [
            'mode' => 'packet-up', 'xPaddingObfsMode' => true, 'xPaddingHeader' => '',
            'uplinkDataPlacement' => 'header', 'uplinkHTTPMethod' => 'put',
        ]]);
        $options = $this->proxies([$node])[0]['xhttp-opts'];
        $this->assertSame('x_padding', $options['x-padding-key']);
        $this->assertSame('X-Padding', $options['x-padding-header']);
        $this->assertSame('queryInHeader', $options['x-padding-placement']);
        $this->assertSame('repeat-x', $options['x-padding-method']);
        $this->assertSame('X-Data', $options['uplink-data-key']);
        $this->assertSame('3000-4000', $options['uplink-chunk-size']);
        $this->assertSame('PUT', $options['uplink-http-method']);
    }

    public function test_xhttp_download_cannot_implicitly_inherit_a_different_session_or_padding_format(): void
    {
        $shared = ['xPaddingObfsMode' => true, 'sessionIDPlacement' => 'query', 'sessionIDKey' => 'sid'];
        $node = $this->node('vless', ['network' => 'xhttp', 'network_settings' => $shared + [
            'mode' => 'packet-up', 'downloadSettings' => $this->downloadSettings(),
        ]]);
        $this->assertCount(0, $this->proxies([$node]));
        data_set($node, 'protocol_settings.network_settings.downloadSettings.xhttpSettings', $shared + ['path' => '/download']);
        $this->assertCount(1, $this->proxies([$node]));
        data_set($node, 'protocol_settings.network_settings.downloadSettings.xhttpSettings.sessionIDKey', 'different');
        $this->assertCount(0, $this->proxies([$node]));
    }

    public function test_invalid_xhttp_settings_only_filter_the_affected_node(): void
    {
        $invalid = [
            ['mode' => 'invalid'], ['extra' => 'invalid'], ['headers' => ['Host' => 'wrong.example']],
            ['mode' => 'stream-one', 'extra' => ['downloadSettings' => $this->downloadSettings()]],
            ['extra' => ['downloadSettings' => ['address' => 'missing-port.example']]],
            ['extra' => ['sessionIDTable' => 'number']],
            ['scMinPostsIntervalMs' => 'invalid'], ['xmux' => ['maxConnections' => -1]],
        ];
        foreach ($invalid as $settings) {
            $bad = $this->node('vless', ['network' => 'xhttp', 'network_settings' => $settings]);
            $bad['name'] = '无效节点';
            $good = $this->node();
            $proxies = $this->proxies([$bad, $good]);
            $this->assertCount(1, $proxies);
            $this->assertSame($good['name'], $proxies[0]['name']);
        }
    }

    public function test_http2_alias_keeps_transport_and_tcp_http_headers_keep_their_existing_behavior(): void
    {
        foreach (['vless', 'vmess'] as $type) {
            $node = $this->node($type, ['network' => 'http', 'network_settings' => ['path' => '/legacy', 'host' => ['legacy.example']]]);
            $proxy = $this->proxies([$node])[0];
            $this->assertSame('h2', $proxy['network']);
            $this->assertSame('/legacy', $proxy['h2-opts']['path']);
            $this->assertSame(['legacy.example'], $proxy['h2-opts']['host']);

            data_set($node, 'protocol_settings.network', 'tcp');
            data_set($node, 'protocol_settings.network_settings', ['header' => ['type' => 'http',
                'request' => ['path' => ['/http'], 'headers' => ['Host' => ['http.example']]]]]);
            $proxy = $this->proxies([$node])[0];
            $this->assertSame('http', $proxy['network']);
            $this->assertSame(['/http'], $proxy['http-opts']['path']);
        }
    }

    public function test_websocket_and_httpupgrade_preserve_headers_and_host_priority(): void
    {
        foreach (['vless', 'vmess', 'trojan'] as $type) {
            foreach (['ws', 'httpupgrade'] as $network) {
                $node = $this->node($type, ['network' => $network, 'network_settings' => [
                    'path' => '/transport?ed=2048', 'host' => 'preferred.example',
                    'headers' => ['host' => 'legacy.example', 'X-Transport' => 'required'],
                ]]);
                $options = $this->proxies([$node])[0]['ws-opts'];
                $this->assertSame('/transport?ed=2048', $options['path']);
                $this->assertSame(['X-Transport' => 'required', 'Host' => 'preferred.example'], $options['headers']);
                $this->assertSame($network === 'httpupgrade', $options['v2ray-http-upgrade'] ?? false);
            }
            $node = $this->node($type, ['network' => 'ws', 'tls_settings' => ['server_name' => 'tls.example']]);
            $this->assertSame('tls.example', $this->proxies([$node])[0]['ws-opts']['headers']['Host']);
        }
    }

    public function test_tcp_http_method_and_grpc_user_agent_are_preserved(): void
    {
        foreach (['vless', 'vmess'] as $type) {
            $node = $this->node($type, ['network' => 'tcp', 'network_settings' => [
                'header' => ['type' => 'http', 'request' => ['method' => 'POST', 'path' => ['/mask']]],
            ]]);
            $this->assertSame('POST', $this->proxies([$node])[0]['http-opts']['method']);
        }
        foreach (['vless', 'vmess', 'trojan'] as $type) {
            $node = $this->node($type, ['network' => 'grpc', 'tls_settings' => ['server_name' => 'tls.example'],
                'network_settings' => ['serviceName' => 'Service', 'user_agent' => 'transport-test', 'authority' => 'tls.example'],
            ]);
            $this->assertSame('transport-test', $this->proxies([$node])[0]['grpc-opts']['grpc-user-agent']);
            data_set($node, 'protocol_settings.network_settings.authority', 'different.example');
            $this->assertCount(0, $this->proxies([$node]));
        }
    }

    public function test_grpc_authority_preserves_defaults_and_allows_custom_names_without_tls(): void
    {
        foreach (['vless', 'vmess', 'trojan'] as $type) {
            $node = $this->node($type, ['network' => 'grpc', 'network_settings' => ['authority' => '127.0.0.1']]);
            $proxy = $this->proxies([$node])[0];
            $this->assertSame('grpc', $proxy['network']);
            if ($type === 'trojan') {
                continue;
            }
            $this->assertSame('127.0.0.1', $proxy['servername']);
            data_set($node, 'protocol_settings.network_settings.authority', '127.0.0.1:443');
            $this->assertCount(1, $this->proxies([$node]));
            data_set($node, 'protocol_settings.tls', 0);
            data_set($node, 'protocol_settings.tls_settings.server_name', 'unused.example');
            data_set($node, 'protocol_settings.network_settings.authority', 'custom.example');
            $proxy = $this->proxies([$node])[0];
            $this->assertSame('custom.example', $proxy['servername']);
            $this->assertFalse($proxy['tls'] ?? false);
        }
    }

    public function test_singbox_hysteria_v1_alpn_matches_the_node_inbound(): void
    {
        $node = $this->node('hysteria', ['version' => 1, 'bandwidth' => ['up' => 20, 'down' => 40]]);
        $node['kernel_type'] = 'singbox';
        $proxy = $this->proxies([$node])[0];
        $this->assertSame(['h3'], $proxy['alpn']);
        $this->assertSame('hysteria', $proxy['type']);
        unset($node['kernel_type']);
        $this->assertArrayNotHasKey('alpn', $this->proxies([$node])[0]);
        $node['kernel_type'] = 'singbox';
        data_set($node, 'protocol_settings.version', 2);
        $proxy = $this->proxies([$node])[0];
        $this->assertSame('hysteria2', $proxy['type']);
        $this->assertArrayNotHasKey('alpn', $proxy);
    }

    public function test_http_tls_preserves_server_name_and_unsupported_tls_options_are_filtered(): void
    {
        $node = $this->node('http', ['tls_settings' => ['server_name' => 'tls.example']]);
        $this->assertSame('tls.example', $this->proxies([$node])[0]['sni']);
        foreach (['http', 'socks'] as $type) {
            $node = $this->node($type, ['tls_settings' => ['ech' => $this->ech()]]);
            $this->assertCount(0, $this->proxies([$node]));
            data_set($node, 'protocol_settings.tls', 0);
            $this->assertCount(1, $this->proxies([$node]));
        }
        $node = $this->node('socks', ['tls_settings' => ['server_name' => 'different.example']]);
        $this->assertCount(0, $this->proxies([$node]));
        data_set($node, 'protocol_settings.tls_settings.server_name', $node['host']);
        $this->assertCount(1, $this->proxies([$node]));
    }

    public function test_mieru_range_udp_and_traffic_pattern_are_preserved(): void
    {
        // TrafficPattern 的 protobuf 字段 1 是 seed，此处只生成非敏感测试参数。
        $pattern = base64_encode(pack('CC', 8, random_int(1, 127)));
        $node = $this->node('mieru', ['transport' => 'UDP', 'traffic_pattern' => $pattern]);
        $node['ports'] = '10000-10010';
        $proxy = $this->proxies([$node])[0];
        $this->assertArrayNotHasKey('port', $proxy);
        $this->assertSame('10000-10010', $proxy['port-range']);
        $this->assertSame('UDP', $proxy['transport']);
        $this->assertTrue($proxy['udp']);
        $this->assertSame($pattern, $proxy['traffic-pattern']);
        $this->assertCount(0, $this->proxies([$node], 'meta', '1.19.20'));
        $this->assertCount(1, $this->proxies([$node], 'meta', '1.19.21'));
        unset($node['ports']);
        $this->assertSame(443, $this->proxies([$node])[0]['port']);
    }

    public function test_shadowsocks_plugin_booleans_and_restls_required_fields_are_preserved(): void
    {
        foreach (['v2ray-plugin', 'gost-plugin'] as $plugin) {
            $node = $this->node('shadowsocks', ['cipher' => 'aes-128-gcm', 'plugin' => $plugin,
                'plugin_opts' => 'server;tls=false;mux=0;skip-cert-verify=false;host=plugin.example;path=/plugin',
            ]);
            $options = $this->proxies([$node])[0]['plugin-opts'];
            $this->assertFalse($options['tls']);
            $this->assertFalse($options['mux']);
            $this->assertFalse($options['skip-cert-verify']);
            data_set($node, 'protocol_settings.plugin_opts', 'tls;mux');
            $options = $this->proxies([$node])[0]['plugin-opts'];
            $this->assertTrue($options['tls']);
            $this->assertTrue($options['mux']);
            data_set($node, 'protocol_settings.plugin_opts', '');
            $this->assertSame('websocket', $this->proxies([$node])[0]['plugin-opts']['mode']);
        }
        $password = bin2hex(random_bytes(16));
        $node = $this->node('shadowsocks', ['cipher' => 'aes-128-gcm', 'plugin' => 'restls',
            'plugin_opts' => 'host=plugin.example;password=' . $password . ';version-hint=tls13',
        ]);
        $options = $this->proxies([$node])[0]['plugin-opts'];
        $this->assertSame('tls13', $options['version-hint']);
        $this->assertArrayNotHasKey('restls-script', $options);
        $this->assertTrue($options['password'] === $password);
        data_set($node, 'protocol_settings.plugin_opts', 'host=plugin.example;password=' . $password . ';version-hint=TLS13');
        $this->assertCount(0, $this->proxies([$node]));
        data_set($node, 'protocol_settings.plugin_opts', 'host=plugin.example');
        $this->assertCount(0, $this->proxies([$node]));
        data_set($node, 'protocol_settings.plugin', 'unsupported-plugin');
        $this->assertCount(0, $this->proxies([$node]));
    }

    public function test_reality_always_has_a_usable_client_fingerprint(): void
    {
        foreach (['vless', 'trojan'] as $type) {
            $node = $this->node($type, ['tls' => 2, 'reality_settings' => [
                'public_key' => rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='),
                'short_id' => bin2hex(random_bytes(4)), 'server_name' => 'reality.example',
            ]]);
            $this->assertSame('chrome', $this->proxies([$node])[0]['client-fingerprint']);
            data_set($node, 'protocol_settings.utls', ['enabled' => true, 'fingerprint' => 'firefox']);
            $this->assertSame('firefox', $this->proxies([$node])[0]['client-fingerprint']);
            data_set($node, 'protocol_settings.utls.fingerprint', 'none');
            $this->assertSame('chrome', $this->proxies([$node])[0]['client-fingerprint']);
        }
    }

    public function test_singbox_multiplex_is_only_enabled_for_the_matching_server_kernel(): void
    {
        foreach (['vless', 'vmess', 'trojan'] as $type) {
            $node = $this->node($type, ['multiplex' => ['enabled' => true, 'protocol' => 'yamux',
                'max_connections' => 4, 'padding' => true, 'brutal' => ['enabled' => true, 'up_mbps' => 20, 'down_mbps' => 40],
            ]]);
            $node['kernel_type'] = 'singbox';
            $options = $this->proxies([$node])[0]['smux'];
            $this->assertTrue($options['enabled']);
            $this->assertSame('yamux', $options['protocol']);
            $this->assertSame(4, $options['max-connections']);
            $this->assertSame(20, $options['brutal-opts']['up']);
            $this->assertSame(40, $options['brutal-opts']['down']);
            $node['kernel_type'] = 'xray';
            $this->assertArrayNotHasKey('smux', $this->proxies([$node])[0]);
            unset($node['kernel_type']);
            $this->assertArrayNotHasKey('smux', $this->proxies([$node])[0]);
        }
    }

    public function test_new_protocols_and_vless_encryption_have_core_version_requirements(): void
    {
        foreach ([['anytls', '1.19.2', '1.19.3'], ['mieru', '1.18.10', '1.19.0']] as [$type, $old, $supported]) {
            $node = $this->node($type);
            $this->assertCount(0, $this->proxies([$node], 'meta', $old));
            $this->assertCount(1, $this->proxies([$node], 'meta', $supported));
            $this->assertCount(1, $this->proxies([$node], 'meta', null));
        }
        $publicKey = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $node = $this->node('vless', ['encryption' => ['enabled' => true, 'encryption' => 'mlkem768x25519plus.native.1rtt.' . $publicKey]]);
        $this->assertCount(0, $this->proxies([$node], 'meta', '1.19.12'));
        $proxy = $this->proxies([$node], 'meta', '1.19.13')[0];
        $this->assertTrue($proxy['encryption'] === $node['protocol_settings']['encryption']['encryption']);
        $this->assertArrayNotHasKey('alterId', $proxy);
        $this->assertArrayNotHasKey('cipher', $proxy);
    }

    public static function mihomoRequestIdentities(): array
    {
        return [
            ['mihomo/1.19.22', null, 1], ['Clash.Meta/v1.19.22', null, 1],
            ['mihomo/1.19.21', null, 0], ['FlClash/0.8.0 mihomo/1.19.21', null, 0],
            ['clash-verge/2.4.5 mihomo/v1.19.22', null, 1],
            ['FlClash/0.8.0', null, 1], ['clash-verge/mihomo', null, 1],
            ['mihomo/1.19.21', 'mihomo', 1], ['mihomo/1.19.21', 'meta', 1],
        ];
    }

    #[DataProvider('mihomoRequestIdentities')]
    public function test_subscription_request_matches_mihomo_and_uses_only_explicit_core_versions(string $agent, ?string $flag, int $expected): void
    {
        $node = $this->node('vless', ['network' => 'xhttp', 'network_settings' => ['path' => '/audit']]);
        $request = Request::create('/', 'GET', $flag === null ? [] : ['flag' => $flag], [], [], ['HTTP_USER_AGENT' => $agent]);
        $response = app(ClientController::class)->doSubscribe($request, $this->subscriptionUser, [$node]);
        $this->assertSame('text/yaml', $response->headers->get('Content-Type'));
        $config = Yaml::parse($response->getContent());
        $this->assertCount($expected, $config['proxies']);
        if ($expected) {
            $this->assertSame('xhttp', $config['proxies'][0]['network']);
        }
    }
}
