<?php

namespace Tests\Feature\Server;

use App\Http\Controllers\V2\Admin\Server\ManageController;
use App\Http\Requests\Admin\ServerSave;
use App\Jobs\RelayNodeTrafficJob;
use App\Jobs\TrafficFetchJob;
use App\Models\Server;
use App\Models\StatServer;
use App\Models\User;
use App\Protocols\ClashMeta;
use App\Protocols\General;
use App\Protocols\Loon;
use App\Protocols\Shadowrocket;
use App\Protocols\SingBox;
use App\Protocols\Stash;
use App\Protocols\Surge;
use App\Services\ServerRelayService;
use App\Services\ServerService;
use App\Support\Setting;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * 中转能力验收：单一入口、订阅继承、路由编号、倍率继承和流量口径分离。
 */
class ServerRelayTest extends TestCase
{
    use RefreshDatabase;

    private const REALITY_PUBLIC_KEY = 'TESTonlyPUBLICkeyNOTaREALsecret0123456789ab';
    private const REALITY_PRIVATE_KEY = 'TESTonlyPRIVATEkeyNOTaREALsecret0123456789';
    private const LANDING_REALITY_PRIVATE_KEY = 'bBcDeFgHiJkLmNoPqRsTuVwXyZ0123456789abcdef0';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
        $this->mock(Setting::class, function (MockInterface $mock): void {
            $settings = [
                'server_token' => 'relay-test-token',
                'server_ws_enable' => 0,
                'server_push_interval' => 60,
            ];
            $mock->shouldReceive('get')->andReturnUsing(fn(string $key) => $settings[$key] ?? null);
            $mock->shouldReceive('save')->andReturn(true);
            $mock->shouldReceive('set')->andReturn(true);
        });
    }

    private function makeEntry(array $overrides = []): Server
    {
        return Server::create(array_merge([
            'type' => Server::TYPE_VLESS,
            'name' => '入口 A',
            'host' => 'entry.example.com',
            'port' => '24443',
            'server_port' => 24443,
            'rate' => 2,
            'show' => true,
            'group_ids' => ['1'],
            'sort' => 1,
            'protocol_settings' => [
                'tls' => 2,
                'flow' => 'xtls-rprx-vision',
                'network' => 'tcp',
                'reality_settings' => [
                    'server_name' => 'www.example.com',
                    'public_key' => self::REALITY_PUBLIC_KEY,
                    'private_key' => self::REALITY_PRIVATE_KEY,
                    'short_id' => '0123abcd',
                ],
                'utls' => ['enabled' => true, 'fingerprint' => 'chrome'],
            ],
        ], $overrides));
    }

    private function makeChild(Server $entry, array $overrides = []): Server
    {
        return Server::create(array_merge([
            'type' => Server::TYPE_SHADOWSOCKS,
            'name' => '落地 B',
            'relay_entry_id' => $entry->id,
            'host' => '203.0.113.7',
            'port' => '28388',
            'server_port' => 28388,
            'rate' => 9, // 逻辑节点自身填写的倍率不得生效
            'show' => true,
            'group_ids' => ['1'],
            'sort' => 2,
            'protocol_settings' => ['cipher' => '2022-blake3-aes-128-gcm'],
        ], $overrides));
    }

    private function makeHysteriaEntry(array $overrides = []): Server
    {
        return $this->makeEntry(array_replace_recursive([
            'type' => Server::TYPE_HYSTERIA,
            'name' => 'HY2 入口',
            'kernel_type' => Server::KERNEL_XRAY,
            'protocol_settings' => [
                'version' => 2,
                'bandwidth' => ['up' => 100, 'down' => 100],
                'obfs' => ['open' => false, 'type' => 'salamander'],
                'tls' => ['server_name' => 'entry.example.com', 'allow_insecure' => false],
            ],
        ], $overrides));
    }

    public function test_hysteria2_relay_subscription_inherits_entry_and_keeps_route_identity(): void
    {
        $entry = $this->makeHysteriaEntry();
        $ss = $this->makeChild($entry);
        $vless = $this->makeVlessChild($entry);
        $user = $this->makeUser();
        $servers = collect(ServerService::getAvailableServers($user))->keyBy('id');

        $this->assertCount(3, $servers);
        foreach ([$entry, $ss, $vless] as $node) {
            $out = $servers[$node->id];
            $this->assertSame(Server::TYPE_HYSTERIA, $out['type']);
            $this->assertSame(2, data_get($out, 'protocol_settings.version'));
            $this->assertSame($entry->host, $out['host']);
            $this->assertSame((int) $entry->port, $out['port']);
            $this->assertSame($entry->server_port, $out['server_port']);
            $this->assertSame($node->name, $out['name']);
            $this->assertSame(Helper::applyVlessRoute($user->uuid, $node->vless_route), $out['password']);
            $this->assertSame($entry->getCurrentRate(), (float) $out['rate']);
        }
        $this->assertSame($user->uuid, ServerService::getAvailableUsers($entry)->first()->uuid);
        foreach ([$ss, $vless] as $child) {
            $this->assertCount(0, ServerService::getAvailableUsers($child));
            $encoded = json_encode($servers[$child->id]);
            $this->assertStringNotContainsString($child->host, $encoded);
            $this->assertStringNotContainsString((string) $child->server_port, $encoded);
            $this->assertArrayNotHasKey('relay_entry', $servers[$child->id]);
        }
    }

    public function test_hysteria2_relay_config_preserves_both_landing_protocols_and_lifecycle(): void
    {
        $entry = $this->makeHysteriaEntry([
            'protocol_settings' => ['obfs' => ['open' => true, 'password' => bin2hex(random_bytes(16))]],
        ]);
        $ss = $this->makeChild($entry);
        $vless = $this->makeVlessChild($entry);
        $config = ServerService::buildNodeConfig($entry->fresh());

        $this->assertSame('hysteria', $config['protocol']);
        $this->assertSame(2, $config['version']);
        $this->assertSame('xray', $config['kernel_type']);
        $this->assertSame('salamander', $config['obfs']);
        $this->assertSame(data_get($entry->protocol_settings, 'obfs.password'), $config['obfs-password']);
        $this->assertSame('entry', data_get($config, 'relay.mode'));
        $children = collect(data_get($config, 'relay.children'))->keyBy('node_id');
        $this->assertCount(2, $children);
        $this->assertSame('shadowsocks', $children[$ss->id]['protocol']);
        $this->assertSame('vless', $children[$vless->id]['protocol']);
        $this->assertSame($children[$ss->id]['password'], data_get(ServerService::buildNodeConfig($ss), 'relay.password'));
        $this->assertSame($children[$vless->id]['vless']['id'], data_get(ServerService::buildNodeConfig($vless), 'relay.vless.id'));
        $this->assertSame($config, ServerService::buildNodeConfig($entry->fresh()));

        $ss->update(['enabled' => false]);
        $active = data_get(ServerService::buildNodeConfig($entry->fresh()), 'relay.children');
        $this->assertCount(1, $active);
        $this->assertSame($vless->id, $active[0]['node_id']);
        $ss->update(['enabled' => true]);
        $this->assertSame($config, ServerService::buildNodeConfig($entry->fresh()));
        $this->assertSame($ss->vless_route, $ss->fresh()->vless_route);
    }

    public function test_hysteria2_client_formats_preserve_the_selected_route(): void
    {
        $entry = $this->makeHysteriaEntry();
        $this->makeChild($entry);
        $this->makeVlessChild($entry);
        $user = $this->makeUser();
        $servers = ServerService::getAvailableServers($user);

        $singbox = new SingBox($user->toArray(), $servers, 'sing-box', '1.14.0');
        (new \ReflectionProperty(SingBox::class, 'config'))->setValue($singbox, ['outbounds' => []]);
        $outbounds = collect((new \ReflectionMethod(SingBox::class, 'buildOutbounds'))->invoke($singbox))->keyBy('tag');
        $this->assertCount(3, $outbounds);

        foreach ($servers as $server) {
            $password = $server['password'];
            $this->assertNotSame($user->uuid, $password);
            $this->assertSame($password, $outbounds[$server['name']]['password']);
            $this->assertSame('hysteria2', $outbounds[$server['name']]['type']);
            $this->assertSame($password, ClashMeta::buildHysteria($password, $server, $user)['password']);
            $this->assertSame($password, Stash::buildHysteria($password, $server)['auth']);
            foreach ([General::class, Shadowrocket::class] as $protocol) {
                $uri = trim($protocol::buildHysteria($password, $server));
                $this->assertSame('hysteria2', parse_url($uri, PHP_URL_SCHEME));
                $this->assertSame($password, parse_url($uri, PHP_URL_USER));
                $this->assertSame($entry->host, parse_url($uri, PHP_URL_HOST));
            }
            $this->assertStringContainsString('password=' . $password, Surge::buildHysteria($password, $server));
            $this->assertStringContainsString(',' . $password . ',', Loon::buildHysteria($password, $server, $user));
        }
    }

    public function test_hysteria2_entry_rejects_unsupported_protocol_settings_and_kernel(): void
    {
        $entry = $this->makeHysteriaEntry();
        $child = $this->makeChild($entry);
        $this->assertNull(ServerRelayService::validateEntry(
            $child->id, $entry->id, $child->type, $child->protocol_settings, $child->host, 'xray',
        ));
        $this->assertNull(ServerRelayService::validateEntry(
            $entry->id, null, $entry->type, $entry->protocol_settings, $entry->host, 'xray',
        ));
        $this->assertNull(ServerRelayService::validateEntry(
            $child->id, $entry->id, $child->type, $child->protocol_settings, $child->host, 'singbox',
        ));
        $this->assertNull(ServerRelayService::validateEntry(
            $entry->id, null, $entry->type, $entry->protocol_settings, $entry->host, 'singbox',
        ));
        $baseSettings = $entry->protocol_settings;
        foreach ([
            ['version' => 1],
            ['tls' => ['ech' => ['enabled' => true]]],
            ['obfs' => ['open' => true, 'type' => 'unknown']],
            ['obfs' => ['open' => true, 'type' => 'salamander', 'password' => '']],
        ] as $invalid) {
            $settings = array_replace_recursive($baseSettings, $invalid);
            $this->assertNotNull(ServerRelayService::validateEntry(
                $entry->id, null, $entry->type, $settings, $entry->host, 'xray',
            ));
            $entry->update(['protocol_settings' => $settings]);
            $this->assertNull(ServerRelayService::entryFor($child->fresh()));
            $this->assertCount(0, ServerRelayService::childrenOf($entry->fresh()));
        }
    }

    public function test_hysteria2_ech_entries_are_selectable_and_preserve_relay_and_subscription_settings(): void
    {
        $user = $this->makeUser();
        foreach (['xray', 'singbox'] as $entryKernel) {
            foreach (['xray', 'singbox'] as $landingKernel) {
                $material = app(ManageController::class)->generateEchKey(
                    Request::create('/', 'GET', ['public_name' => 'public.ech.example']),
                )->getData(true)['data'];
                $entry = $this->makeHysteriaEntry([
                    'kernel_type' => $entryKernel,
                    'name' => "ECH {$entryKernel} 入口 {$landingKernel}",
                    'protocol_settings' => ['tls' => ['ech' => [
                        'enabled' => true,
                        'key' => $material['key'],
                        'config' => $material['config'],
                        'query_server_name' => 'public.ech.example',
                    ]]],
                ]);
                $ss = $this->makeChild($entry, ['kernel_type' => $landingKernel, 'name' => "ECH SS {$entry->id}"]);
                $vless = $this->makeVlessChild($entry, ['kernel_type' => $landingKernel, 'name' => "ECH VLESS {$entry->id}"]);
                $nodes = collect(app(ManageController::class)->getNodes(new Request())->getData(true)['data'])->keyBy('id');
                $this->assertTrue($nodes[$entry->id]['relay_entry_supported']);
                $this->assertFalse($nodes[$ss->id]['relay_entry_supported']);
                foreach ([$ss, $vless] as $landing) {
                    $this->assertNull(ServerRelayService::validateEntry(
                        $landing->id, $entry->id, $landing->type, $landing->protocol_settings, $landing->host, $landingKernel,
                    ));
                    $this->assertArrayNotHasKey('relay_entry_id', $this->validateServerSave($landing->only([
                        'id', 'type', 'name', 'relay_entry_id', 'host', 'port', 'server_port', 'rate', 'group_ids',
                        'kernel_type', 'protocol_settings',
                    ])));
                }
                $config = ServerService::buildNodeConfig($entry->fresh());
                $this->assertSame($material['key'], data_get($config, 'tls_settings.ech.key'));
                $this->assertSame($entryKernel, $config['kernel_type']);
                $this->assertSame('entry', data_get($config, 'relay.mode'));
                $this->assertSame(['shadowsocks', 'vless'], array_column(data_get($config, 'relay.children'), 'protocol'));
                $this->assertSame($config, ServerService::buildNodeConfig($entry->fresh()));

                $servers = collect(ServerService::getAvailableServers($user))->keyBy('id');
                $selected = $servers->only([$entry->id, $ss->id, $vless->id])->values()->all();
                $this->assertCount(3, $selected);
                $singbox = (new SingBox($user, $selected, 'sing-box', '1.14.0'))->handle()->getData(true);
                $outbounds = collect($singbox['outbounds'])->keyBy('tag');
                $mihomoYaml = (new ClashMeta($user, $selected, 'meta', '1.19.9'))->handle()->getContent();
                $mihomo = collect(Yaml::parse($mihomoYaml)['proxies'])->keyBy('name');
                foreach ([$entry, $ss, $vless] as $logical) {
                    $password = Helper::applyVlessRoute($user->uuid, $logical->vless_route);
                    $boxOutbound = $outbounds[$logical->name];
                    $metaOutbound = $mihomo[$logical->name];
                    $this->assertSame('hysteria2', $boxOutbound['type']);
                    $this->assertSame($password, $boxOutbound['password']);
                    $this->assertSame($password, $metaOutbound['password']);
                    $this->assertSame($entry->host, $boxOutbound['server']);
                    $this->assertSame($entry->host, $metaOutbound['server']);
                    $this->assertSame((int) $entry->port, $boxOutbound['server_port']);
                    $this->assertSame((int) $entry->port, $metaOutbound['port']);
                    $this->assertTrue(data_get($boxOutbound, 'tls.ech.enabled'));
                    $this->assertSame([trim($material['config'])], data_get($boxOutbound, 'tls.ech.config'));
                    $this->assertSame('public.ech.example', data_get($boxOutbound, 'tls.ech.query_server_name'));
                    $this->assertTrue(data_get($metaOutbound, 'ech-opts.enable'));
                    $this->assertSame(Helper::toMihomoEchConfig($material['config']), data_get($metaOutbound, 'ech-opts.config'));
                    $this->assertSame('public.ech.example', data_get($metaOutbound, 'ech-opts.query-server-name'));
                    $this->assertStringNotContainsString('ECH KEYS', json_encode($boxOutbound));
                    $this->assertStringNotContainsString('ECH KEYS', json_encode($metaOutbound));
                }
                $oldMihomo = Yaml::parse((new ClashMeta($user, $selected, 'meta', '1.19.8'))->handle()->getContent());
                $this->assertCount(0, collect($oldMihomo['proxies'])->where('type', 'hysteria2'));
                $unversioned = Yaml::parse((new ClashMeta($user, $selected, 'meta'))->handle()->getContent());
                $this->assertCount(3, collect($unversioned['proxies'])->where('type', 'hysteria2'));
                foreach ([$ss, $vless] as $landing) {
                    $this->assertStringNotContainsString('ECH KEYS', json_encode(ServerService::buildNodeConfig($landing)));
                }
            }
        }
    }

    public function test_hysteria2_ech_subscription_supports_dns_config_and_keeps_plain_nodes_unchanged(): void
    {
        $entry = $this->makeHysteriaEntry();
        $user = $this->makeUser();
        $server = ServerService::getAvailableServers($user)[0];
        $plain = ClashMeta::buildHysteria($user->uuid, $server, $user);
        $this->assertArrayNotHasKey('ech-opts', $plain);

        data_set($server, 'protocol_settings.tls.ech', [
            'enabled' => true, 'query_server_name' => 'public.ech.example',
        ]);
        $meta = ClashMeta::buildHysteria($user->uuid, $server, $user);
        $this->assertSame(['enable' => true, 'query-server-name' => 'public.ech.example'], $meta['ech-opts']);
        $outbounds = collect((new SingBox($user, [$server], 'sing-box', '1.14.0'))->handle()->getData(true)['outbounds'])->keyBy('tag');
        $this->assertSame(
            ['enabled' => true, 'query_server_name' => 'public.ech.example'],
            data_get($outbounds[$entry->name], 'tls.ech'),
        );

        data_set($server, 'protocol_settings.tls.ech.enabled', false);
        $this->assertSame($plain, ClashMeta::buildHysteria($user->uuid, $server, $user));
    }

    public function test_hysteria2_ech_version_filter_only_uses_known_mihomo_core_versions(): void
    {
        $this->makeHysteriaEntry();
        $user = $this->makeUser();
        $server = ServerService::getAvailableServers($user)[0];
        $count = function (array $node, string $client, ?string $version) use ($user): int {
            $yaml = (new ClashMeta($user, [$node], $client, $version))->handle()->getContent();
            return collect(Yaml::parse($yaml)['proxies'])->where('type', 'hysteria2')->count();
        };
        foreach ([true, 1, '1'] as $enabled) {
            data_set($server, 'protocol_settings.tls.ech.enabled', $enabled);
            $this->assertSame(0, $count($server, 'meta', '1.19.8'));
            $this->assertSame(1, $count($server, 'meta', '1.19.9'));
            $this->assertSame(1, $count($server, 'meta', null));
            $this->assertSame(1, $count($server, 'flclash', '0.8.0'));
        }
        foreach ([false, 0, '0', null] as $disabled) {
            data_set($server, 'protocol_settings.tls.ech.enabled', $disabled);
            $this->assertSame(1, $count($server, 'meta', '1.19.8'));
        }
    }

    public function test_plain_hysteria_nodes_keep_original_auth_and_invalid_entries_hide_landings(): void
    {
        $user = $this->makeUser();
        $plain = $this->makeHysteriaEntry(['kernel_type' => 'singbox']);
        $v1 = $this->makeHysteriaEntry(['name' => 'HY1 普通节点', 'protocol_settings' => ['version' => 1]]);
        $servers = collect(ServerService::getAvailableServers($user))->keyBy('id');
        foreach ([$plain, $v1] as $node) {
            $this->assertSame($user->uuid, $servers[$node->id]['password']);
            $this->assertArrayNotHasKey('relay', ServerService::buildNodeConfig($node));
            $this->makeVlessChild($node, ['protocol_settings' => ['network' => 'xhttp', 'tls' => 1]]);
        }
        $servers = ServerService::getAvailableServers($user);
        $this->assertCount(2, $servers);
        $this->assertStringNotContainsString('10.0.0.7', json_encode($servers));
    }

    public function test_relay_kernel_switch_cannot_bypass_topology_validation(): void
    {
        foreach ([$this->makeEntry(), $this->makeHysteriaEntry()] as $entry) {
            $child = $this->makeVlessChild($entry, ['protocol_settings' => ['network' => 'xhttp', 'tls' => 1]]);
            $nodes = [$entry, $child];
            foreach ($nodes as $node) {
                foreach (['singbox', 'sing-box'] as $kernel) {
                    $request = Request::create('/', 'POST', ['id' => $node->id, 'kernel_type' => $kernel]);
                    try {
                        app(ManageController::class)->update($request);
                        $this->fail('中转节点切换到不支持的内核时未被拒绝');
                    } catch (ValidationException $error) {
                        $this->assertArrayHasKey('kernel_type', $error->errors());
                    }
                    $this->assertSame('xray', Server::effectiveKernelType($node->fresh()->kernel_type));
                }
                foreach (['xray', null] as $kernel) {
                    $response = app(ManageController::class)->update(
                        Request::create('/', 'POST', ['id' => $node->id, 'kernel_type' => $kernel]),
                    );
                    $this->assertSame(200, $response->getStatusCode());
                    $this->assertSame('xray', Server::effectiveKernelType($node->fresh()->kernel_type));
                }
            }
        }
        $ordinary = $this->makeHysteriaEntry();
        $response = app(ManageController::class)->update(
            Request::create('/', 'POST', ['id' => $ordinary->id, 'kernel_type' => 'singbox']),
        );
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('singbox', $ordinary->fresh()->kernel_type);
    }

    public function test_relay_save_uses_existing_values_for_omitted_optional_fields(): void
    {
        $entry = $this->makeHysteriaEntry();
        $child = $this->makeVlessChild($entry, [
            'kernel_type' => 'singbox', 'protocol_settings' => ['network' => 'xhttp', 'tls' => 1],
        ]);
        $payload = $child->only([
            'id', 'type', 'name', 'relay_entry_id', 'host', 'port', 'server_port', 'rate', 'group_ids', 'protocol_settings',
        ]);
        $this->assertArrayHasKey('relay_entry_id', $this->validateServerSave($payload));
        $payload['kernel_type'] = 'xray';
        $this->assertArrayNotHasKey('relay_entry_id', $this->validateServerSave($payload));

        unset($payload['relay_entry_id']);
        $payload['kernel_type'] = 'singbox';
        $this->assertArrayHasKey('relay_entry_id', $this->validateServerSave($payload));
        $payload['kernel_type'] = 'xray';
        $this->assertArrayNotHasKey('relay_entry_id', $this->validateServerSave($payload));

        $payload['relay_entry_id'] = 0;
        $payload['kernel_type'] = 'singbox';
        $this->assertArrayNotHasKey('relay_entry_id', $this->validateServerSave($payload));
    }

    public function test_hysteria2_relay_hides_landings_with_an_unsupported_kernel(): void
    {
        $entry = $this->makeHysteriaEntry();
        $invalid = $this->makeVlessChild($entry, [
            'kernel_type' => 'singbox', 'sort' => 2, 'protocol_settings' => ['network' => 'xhttp', 'tls' => 1],
        ]);
        $valid = $this->makeVlessChild($entry);
        $user = $this->makeUser();
        $this->assertNull(ServerRelayService::entryFor($invalid));
        $this->assertSame([$valid->id], ServerRelayService::childrenOf($entry)->pluck('id')->all());
        $this->assertSame([$entry->id, $valid->id], array_column(ServerService::getAvailableServers($user), 'id'));
        $this->assertSame([$valid->id], array_column(data_get(ServerService::buildNodeConfig($entry), 'relay.children'), 'node_id'));

        $invalid->update(['kernel_type' => 'xray']);
        $this->assertCount(3, ServerService::getAvailableServers($user));
        $this->assertCount(2, data_get(ServerService::buildNodeConfig($entry), 'relay.children'));
    }

    public function test_relay_supports_singbox_and_mixed_kernels(): void
    {
        $user = $this->makeUser();
        foreach (['vless', 'hysteria'] as $protocol) {
            foreach (['xray', 'singbox'] as $entryKernel) {
                foreach (['xray', 'singbox'] as $landingKernel) {
                    $entry = $protocol === 'vless'
                        ? $this->makeEntry(['kernel_type' => $entryKernel])
                        : $this->makeHysteriaEntry(['kernel_type' => $entryKernel]);
                    $children = [
                        $this->makeChild($entry, ['kernel_type' => $landingKernel]),
                        $this->makeVlessChild($entry, ['kernel_type' => $landingKernel]),
                    ];
                    foreach ($children as $child) {
                        $this->assertNull(ServerRelayService::validateEntry(
                            $child->id, $entry->id, $child->type, $child->protocol_settings, $child->host, $landingKernel,
                        ));
                        $this->assertSame($entry->id, ServerRelayService::entryFor($child)?->id);
                        $this->assertSame('landing', data_get(ServerService::buildNodeConfig($child), 'relay.mode'));
                        $this->assertSame((float) $entry->rate, $child->getEffectiveRate());
                    }
                    $this->assertCount(2, data_get(ServerService::buildNodeConfig($entry), 'relay.children'));
                    $servers = collect(ServerService::getAvailableServers($user))->keyBy('id');
                    foreach ([$entry, ...$children] as $node) {
                        $this->assertSame($entry->host, $servers[$node->id]['host']);
                        $this->assertSame($protocol, $servers[$node->id]['type']);
                        $this->assertSame(Helper::applyVlessRoute($user->uuid, $node->vless_route), $servers[$node->id]['password']);
                    }
                }
            }
        }
    }

    public function test_singbox_relay_checks_both_ends_of_internal_vless_link(): void
    {
        $entry = $this->makeHysteriaEntry(['kernel_type' => 'singbox']);
        $child = $this->makeVlessChild($entry);
        foreach (['xhttp', 'kcp', 'hysteria'] as $network) {
            $settings = ['network' => $network, 'tls' => 1];
            $this->assertNotNull(ServerRelayService::validateEntry(
                $child->id, $entry->id, $child->type, $settings, $child->host, 'xray',
            ));
        }
        $settings = $child->protocol_settings;
        $settings['encryption']['enabled'] = true;
        $this->assertStringContainsString('VLESS Encryption', ServerRelayService::validateEntry(
            $child->id, $entry->id, $child->type, $settings, $child->host, 'singbox',
        ));
        foreach (['xray', 'singbox', 'sing-box'] as $kernel) {
            $response = app(ManageController::class)->update(Request::create('/', 'POST', ['id' => $child->id, 'kernel_type' => $kernel]));
            $this->assertSame(200, $response->getStatusCode());
        }
    }

    private function makeVlessChild(Server $entry, array $overrides = []): Server
    {
        return Server::create(array_merge([
            'type' => Server::TYPE_VLESS,
            'name' => 'VLESS 落地 C',
            'relay_entry_id' => $entry->id,
            'host' => '10.0.0.7',
            'port' => '29388',
            'server_port' => 29388,
            'rate' => 9,
            'show' => true,
            'group_ids' => ['1'],
            'sort' => 3,
            'protocol_settings' => [
                'tls' => 2,
                'flow' => 'xtls-rprx-vision',
                'network' => 'tcp',
                'network_settings' => [],
                'reality_settings' => [
                    'server_name' => 'landing.example.com',
                    'public_key' => self::REALITY_PUBLIC_KEY,
                    'private_key' => self::LANDING_REALITY_PRIVATE_KEY,
                    'short_id' => '89abcdef',
                ],
                'utls' => ['enabled' => true, 'fingerprint' => 'chrome'],
                'encryption' => ['enabled' => false],
            ],
        ], $overrides));
    }

    private function makeUser(int $groupId = 1): User
    {
        return User::create([
            'email' => 'relay-tester@example.com',
            'password' => 'x',
            'uuid' => '11111111-2222-4333-8444-555555555555',
            'token' => Helper::guid(),
            'group_id' => $groupId,
            'transfer_enable' => 10 * 1024 * 1024 * 1024,
            'expired_at' => null,
            'banned' => 0,
            'u' => 0,
            'd' => 0,
        ]);
    }

    /**
     * parent_id 保持上游语义，与中转完全无关。
     *
     * 存量库里大量节点带 parent_id（v2board 迁移会填 0，也可能指向真实父节点），
     * 这些节点必须继续按普通节点处理：照常下发用户、照常出现在订阅里、
     * 节点配置里不出现 relay 段。
     */
    public function test_parent_id_does_not_make_a_node_a_relay_child(): void
    {
        $user = $this->makeUser();

        $makePlainSs = fn(string $name, $parentId, int $port) => Server::create([
            'type' => Server::TYPE_SHADOWSOCKS,
            'name' => $name,
            'parent_id' => $parentId,
            'host' => '198.51.100.9',
            'port' => (string) $port,
            'server_port' => $port,
            'rate' => 1,
            'show' => true,
            'group_ids' => ['1'],
            'sort' => 5,
            'protocol_settings' => ['cipher' => 'aes-128-gcm'],
        ]);

        $zeroParent = $makePlainSs('美国-03｜禁止直连', 0, 12345);
        $realParent = $makePlainSs('美国-04｜禁止直连', $zeroParent->id, 12346);

        foreach ([$zeroParent, $realParent] as $node) {
            $this->assertFalse($node->isRelayChild(), "{$node->name} 不应被当作中转逻辑节点");
            $this->assertNull($node->relayEntryId());
            $this->assertNotEmpty(ServerService::getAvailableUsers($node->fresh()));
            $this->assertArrayNotHasKey('relay', ServerService::buildNodeConfig($node->fresh()));
        }

        // 仍然以 Shadowsocks 节点出现在订阅中，参数不变。
        $servers = collect(ServerService::getAvailableServers($user))->keyBy('id');
        foreach ([$zeroParent, $realParent] as $node) {
            $this->assertArrayHasKey($node->id, $servers->all());
            $this->assertSame(Server::TYPE_SHADOWSOCKS, $servers[$node->id]['type']);
            $this->assertSame('198.51.100.9', $servers[$node->id]['host']);
        }
    }

    /**
     * 一个节点可以同时带 parent_id（沿用上游的状态共享）和 relay_entry_id（参与中转），
     * 两者互不干扰。
     */
    public function test_parent_id_and_relay_entry_id_are_independent(): void
    {
        $entry = $this->makeEntry();
        $other = $this->makeEntry(['name' => '另一个入口', 'port' => '25443', 'server_port' => 25443]);
        $child = $this->makeChild($entry, ['parent_id' => $other->id]);

        $child = $child->fresh();

        // 中转关系只看 relay_entry_id。
        $this->assertTrue($child->isRelayChild());
        $this->assertSame($entry->id, $child->relayEntryId());
        $this->assertSame($entry->id, ServerRelayService::entryFor($child)?->id);
        $this->assertCount(1, ServerRelayService::childrenOf($entry->fresh()));

        // parent_id 指向的节点不会因此变成前置入口。
        $this->assertCount(0, ServerRelayService::childrenOf($other->fresh()));
        $this->assertNull(data_get(ServerService::buildNodeConfig($other->fresh()), 'relay'));

        // parent_id 原有的关联仍然可用。
        $this->assertSame($other->id, $child->parent->id);
    }

    /**
     * relay_entry_id 为 0 或 null 都表示不使用中转。
     */
    public function test_zero_relay_entry_id_means_no_relay(): void
    {
        $entry = $this->makeEntry();
        $child = $this->makeChild($entry, ['relay_entry_id' => 0]);

        $this->assertNull($child->relayEntryId());
        $this->assertFalse($child->isRelayChild());
        $this->assertCount(0, ServerRelayService::childrenOf($entry->fresh()));
        $this->assertNull(
            ServerRelayService::validateEntry(null, 0, Server::TYPE_SHADOWSOCKS, ['cipher' => 'aes-128-gcm'])
        );
    }

    /**
     * 管理端节点列表下发 relay_entry_name，供表头的「前置入口」列直接展示。
     *
     * 入口已被删除时该字段为 null，与未设置前置入口的普通节点显示一致，
     * 不能因为解析不到名称就让整个列表报错。
     */
    public function test_admin_node_list_exposes_relay_entry_name(): void
    {
        $entry = $this->makeEntry();
        $child = $this->makeChild($entry);
        $orphan = $this->makeChild($entry, [
            'name' => '落地 孤儿',
            'relay_entry_id' => 999999,
            'port' => '28389',
            'server_port' => 28389,
        ]);

        $nodes = collect(
            json_decode(
                (new ManageController())->getNodes(new Request())->getContent(),
                true
            )['data']
        )->keyBy('id');

        $this->assertSame($entry->name, $nodes[$child->id]['relay_entry_name']);
        $this->assertNull($nodes[$entry->id]['relay_entry_name']);
        $this->assertNull($nodes[$orphan->id]['relay_entry_name']);
        $this->assertTrue($nodes[$entry->id]['relay_entry_supported']);
        $this->assertFalse($nodes[$child->id]['relay_entry_supported']);
    }

    public function test_route_ids_are_unique_stable_and_in_range(): void
    {
        $entry = $this->makeEntry();
        $child = $this->makeChild($entry);

        $this->assertNotNull($entry->vless_route);
        $this->assertNotNull($child->vless_route);
        $this->assertNotSame($entry->vless_route, $child->vless_route);

        foreach ([$entry, $child] as $server) {
            $this->assertGreaterThanOrEqual(Server::ROUTE_ID_MIN, $server->vless_route);
            $this->assertLessThanOrEqual(Server::ROUTE_ID_MAX, $server->vless_route);
        }

        // 编辑节点不得改变编号。
        $original = $child->vless_route;
        $child->update(['name' => '落地 B 改名']);
        $this->assertSame($original, $child->fresh()->vless_route);
    }

    public function test_deleted_route_id_is_not_reused_immediately(): void
    {
        $entry = $this->makeEntry();
        $child = $this->makeChild($entry);
        $used = [$entry->vless_route, $child->vless_route];

        $child->delete();
        $replacement = $this->makeChild($entry, ['name' => '落地 C']);

        $this->assertNotContains($replacement->vless_route, $used);
    }

    public function test_subscription_projects_child_onto_entry_parameters(): void
    {
        $entry = $this->makeEntry();
        $child = $this->makeChild($entry);
        $user = $this->makeUser();

        $servers = collect(ServerService::getAvailableServers($user))->keyBy('id');
        $entryOut = $servers[$entry->id];
        $childOut = $servers[$child->id];

        // 同一入口：地址、端口和完整客户端协议参数一致；本用例使用 Reality 入口。
        $this->assertSame($entryOut['type'], $childOut['type']);
        $this->assertSame($entryOut['host'], $childOut['host']);
        $this->assertSame($entryOut['port'], $childOut['port']);
        $this->assertSame(
            data_get($entryOut, 'protocol_settings.reality_settings'),
            data_get($childOut, 'protocol_settings.reality_settings')
        );
        $this->assertSame(
            data_get($entryOut, 'protocol_settings.utls'),
            data_get($childOut, 'protocol_settings.utls')
        );
        $this->assertSame(
            data_get($entryOut, 'protocol_settings.flow'),
            data_get($childOut, 'protocol_settings.flow')
        );

        // 名称独立。
        $this->assertSame('入口 A', $entryOut['name']);
        $this->assertSame('落地 B', $childOut['name']);

        // 路由编号不同，且都由同一个原始 UUID 派生。
        $this->assertNotSame($entryOut['password'], $childOut['password']);
        $this->assertSame(
            Helper::applyVlessRoute($user->uuid, $entry->vless_route),
            $entryOut['password']
        );
        $this->assertSame(
            Helper::applyVlessRoute($user->uuid, $child->vless_route),
            $childOut['password']
        );
        $this->assertSame(
            substr($user->uuid, 0, 14) . substr($user->uuid, 18),
            substr($childOut['password'], 0, 14) . substr($childOut['password'], 18)
        );
    }

    public function test_subscription_never_exposes_internal_shadowsocks_details(): void
    {
        $entry = $this->makeEntry();
        $child = $this->makeChild($entry);
        $user = $this->makeUser();

        $servers = collect(ServerService::getAvailableServers($user))->keyBy('id');
        $encoded = json_encode($servers[$child->id]);

        $this->assertStringNotContainsString('203.0.113.7', $encoded);
        $this->assertStringNotContainsString('28388', $encoded);
        $this->assertStringNotContainsString(
            ServerRelayService::transitCredential($child)['password'],
            $encoded
        );
    }

    public function test_child_inherits_entry_rate_including_time_ranges(): void
    {
        $entry = $this->makeEntry([
            'rate' => 2,
            'rate_time_enable' => true,
            'rate_time_ranges' => [['start' => '00:00', 'end' => '23:59', 'rate' => 3]],
        ]);
        $child = $this->makeChild($entry);
        $user = $this->makeUser();

        $servers = collect(ServerService::getAvailableServers($user))->keyBy('id');

        $this->assertSame(3.0, (float) $servers[$entry->id]['rate']);
        $this->assertSame(3.0, (float) $servers[$child->id]['rate']);
        $this->assertSame(3.0, $child->fresh()->getEffectiveRate());
    }

    public function test_child_without_permission_is_absent_from_subscription(): void
    {
        $entry = $this->makeEntry();
        $this->makeChild($entry, ['group_ids' => ['2']]);
        $user = $this->makeUser(1);

        $names = collect(ServerService::getAvailableServers($user))->pluck('name')->all();

        $this->assertContains('入口 A', $names);
        $this->assertNotContains('落地 B', $names);
    }

    public function test_disabled_or_orphaned_child_is_dropped_from_subscription(): void
    {
        $entry = $this->makeEntry();
        $disabled = $this->makeChild($entry, ['name' => '落地 禁用', 'enabled' => false]);
        $orphan = $this->makeChild($entry, ['name' => '落地 孤儿', 'relay_entry_id' => 999999]);
        $user = $this->makeUser();

        $names = collect(ServerService::getAvailableServers($user))->pluck('name')->all();

        $this->assertNotContains('落地 禁用', $names);
        $this->assertNotContains('落地 孤儿', $names);
        $this->assertContains('入口 A', $names);

        // 拓扑损坏时也不能退化成一个暴露内部地址的普通 Shadowsocks 节点。
        $encoded = json_encode(ServerService::getAvailableServers($user));
        $this->assertStringNotContainsString('203.0.113.7', $encoded);
        unset($disabled, $orphan);
    }

    public function test_entry_node_config_lists_children_and_landing_config_is_isolated(): void
    {
        $entry = $this->makeEntry();
        $child = $this->makeChild($entry);

        $entryConfig = ServerService::buildNodeConfig($entry->fresh());
        $this->assertSame('entry', data_get($entryConfig, 'relay.mode'));
        $this->assertSame($entry->vless_route, data_get($entryConfig, 'relay.route_id'));

        $children = data_get($entryConfig, 'relay.children');
        $this->assertCount(1, $children);
        $this->assertSame($child->id, $children[0]['node_id']);
        $this->assertSame("relay-{$child->id}", $children[0]['tag']);
        $this->assertSame($child->vless_route, $children[0]['route_id']);
        $this->assertSame('203.0.113.7', $children[0]['address']);
        $this->assertSame(28388, $children[0]['port']);

        $landingConfig = ServerService::buildNodeConfig($child->fresh());
        $this->assertSame('landing', data_get($landingConfig, 'relay.mode'));
        $this->assertSame(28388, data_get($landingConfig, 'relay.listen_port'));
        $this->assertSame($entry->id, data_get($landingConfig, 'relay.entry_node_id'));

        // 两端使用同一份内部凭据。
        $this->assertSame($children[0]['password'], data_get($landingConfig, 'relay.password'));
        $this->assertSame($children[0]['cipher'], data_get($landingConfig, 'relay.cipher'));

        // 普通节点结构不变。
        $plain = $this->makeEntry(['name' => '普通节点', 'port' => '443', 'server_port' => 443]);
        $this->assertArrayNotHasKey('relay', ServerService::buildNodeConfig($plain));
    }

    public function test_vless_relay_config_splits_client_and_server_secrets(): void
    {
        $entry = $this->makeEntry();
        $encryption = 'mlkem768x25519plus.native.0rtt.' . str_repeat('A', 43);
        $decryption = 'mlkem768x25519plus.native.600s.' . str_repeat('A', 43);
        $child = $this->makeVlessChild($entry, [
            'protocol_settings' => [
                'tls' => 2,
                'flow' => 'xtls-rprx-vision',
                'network' => 'xhttp',
                'network_settings' => [
                    'path' => '/relay',
                    'extra' => [
                        'downloadSettings' => [
                            'realitySettings' => ['privateKey' => 'nested-server-secret'],
                            'tlsSettings' => ['certificates' => [['key' => 'nested-cert-secret']]],
                        ],
                    ],
                ],
                'reality_settings' => [
                    'server_name' => 'landing.example.com',
                    'public_key' => self::REALITY_PUBLIC_KEY,
                    'private_key' => self::LANDING_REALITY_PRIVATE_KEY,
                    'short_id' => '89abcdef',
                ],
                'utls' => ['enabled' => true, 'fingerprint' => 'chrome'],
                'encryption' => [
                    'enabled' => true,
                    'encryption' => $encryption,
                    'decryption' => $decryption,
                ],
            ],
        ]);

        $entryConfig = ServerService::buildNodeConfig($entry->fresh());
        $outbound = collect(data_get($entryConfig, 'relay.children'))->firstWhere('node_id', $child->id);
        $client = $outbound['vless'];

        $this->assertSame(Server::TYPE_VLESS, $outbound['protocol']);
        $this->assertSame('xhttp', $client['network']);
        $this->assertSame('/relay', data_get($client, 'network_settings.path'));
        $this->assertStringNotContainsString('nested-server-secret', json_encode($client));
        $this->assertStringNotContainsString('nested-cert-secret', json_encode($client));
        $this->assertSame($encryption, $client['encryption']);
        $this->assertSame(self::REALITY_PUBLIC_KEY, data_get($client, 'reality_settings.public_key'));
        $this->assertArrayNotHasKey('private_key', $client['reality_settings']);
        $this->assertArrayNotHasKey('decryption', $client);

        $landingConfig = ServerService::buildNodeConfig($child->fresh());
        $this->assertSame(Server::TYPE_VLESS, data_get($landingConfig, 'relay.protocol'));
        $this->assertSame($client['id'], data_get($landingConfig, 'relay.vless.id'));
        $this->assertSame($decryption, $landingConfig['decryption']);
        $this->assertSame(self::LANDING_REALITY_PRIVATE_KEY, data_get($landingConfig, 'tls_settings.private_key'));

        // 内部 UUID 和两端私密配置都不能进入用户订阅。
        $subscription = json_encode(ServerService::getAvailableServers($this->makeUser()));
        $this->assertStringNotContainsString($client['id'], $subscription);
        $this->assertStringNotContainsString($decryption, $subscription);
        $this->assertStringNotContainsString(self::LANDING_REALITY_PRIVATE_KEY, $subscription);
    }

    public function test_vless_relay_transport_security_matrix(): void
    {
        $base = [
            'flow' => '',
            'network_settings' => [],
            'encryption' => ['enabled' => false],
            'tls_settings' => ['server_name' => 'landing.example.com'],
            'reality_settings' => [
                'server_name' => 'landing.example.com',
                'public_key' => self::REALITY_PUBLIC_KEY,
                'private_key' => self::LANDING_REALITY_PRIVATE_KEY,
                'short_id' => '89abcdef',
            ],
            'utls' => ['enabled' => true, 'fingerprint' => 'chrome'],
        ];

        $valid = [
            ['tcp', 0], ['tcp', 1], ['tcp', 2],
            ['ws', 0], ['ws', 1],
            ['grpc', 0], ['grpc', 1], ['grpc', 2],
            ['xhttp', 0], ['xhttp', 1], ['xhttp', 2],
            ['httpupgrade', 0], ['httpupgrade', 1],
            ['kcp', 0], ['kcp', 1],
            ['hysteria', 1],
        ];
        foreach ($valid as [$network, $tls]) {
            $settings = array_merge($base, ['network' => $network, 'tls' => $tls]);
            $this->assertNull(
                ServerRelayService::validateTransitSettings(Server::TYPE_VLESS, $settings, '10.0.0.7'),
                "{$network} + tls={$tls} 应为有效组合",
            );
        }

        $defaultNetwork = array_merge($base, ['network' => '', 'tls' => 0]);
        $this->assertNull(
            ServerRelayService::validateTransitSettings(Server::TYPE_VLESS, $defaultNetwork, '10.0.0.7'),
            '旧配置未保存 network 时应按 RAW/TCP 处理',
        );

        foreach ([['ws', 2], ['httpupgrade', 2], ['kcp', 2], ['hysteria', 0], ['hysteria', 2], ['h2', 1]] as [$network, $tls]) {
            $settings = array_merge($base, ['network' => $network, 'tls' => $tls]);
            $this->assertNotNull(
                ServerRelayService::validateTransitSettings(Server::TYPE_VLESS, $settings, '10.0.0.7'),
                "{$network} + tls={$tls} 应被拒绝",
            );
        }

        $publicPlain = array_merge($base, ['network' => 'tcp', 'tls' => 0]);
        $this->assertNotNull(ServerRelayService::validateTransitSettings(
            Server::TYPE_VLESS,
            $publicPlain,
            '8.8.8.8',
        ));

        $invalidEncryption = array_merge($base, [
            'network' => 'tcp',
            'tls' => 0,
            'encryption' => [
                'enabled' => true,
                'encryption' => 'mlkem768x25519plus.native.0rtt.' . str_repeat('A', 42),
                'decryption' => 'mlkem768x25519plus.native.600s.' . str_repeat('A', 42),
            ],
        ]);
        $this->assertNotNull(ServerRelayService::validateTransitSettings(
            Server::TYPE_VLESS,
            $invalidEncryption,
            '10.0.0.7',
        ));
    }

    public function test_node_config_is_idempotent_across_repeated_calls(): void
    {
        $entry = $this->makeEntry();
        $this->makeChild($entry);

        $first = ServerService::buildNodeConfig($entry->fresh());
        $second = ServerService::buildNodeConfig($entry->fresh());

        $this->assertSame(json_encode($first), json_encode($second));
    }

    public function test_landing_node_receives_no_panel_users(): void
    {
        $entry = $this->makeEntry();
        $child = $this->makeChild($entry);
        $this->makeUser();

        $this->assertNotEmpty(ServerService::getAvailableUsers($entry->fresh()));
        $this->assertEmpty(ServerService::getAvailableUsers($child->fresh()));
    }

    public function test_user_traffic_is_billed_once_at_the_entry_rate(): void
    {
        Bus::fake();

        $entry = $this->makeEntry(['rate' => 2]);
        $user = $this->makeUser();

        ServerService::processTraffic($entry->fresh(), [$user->id => [1024, 2048]]);

        Bus::assertDispatched(TrafficFetchJob::class, function (TrafficFetchJob $job) {
            $reflection = new \ReflectionProperty($job, 'server');
            $reflection->setAccessible(true);
            return (float) $reflection->getValue($job)['rate'] === 2.0;
        });
    }

    public function test_relay_traffic_is_node_only_and_skips_user_billing(): void
    {
        Bus::fake();

        $entry = $this->makeEntry();
        $child = $this->makeChild($entry);

        ServerService::processRelayTraffic($entry->fresh(), [
            (string) $child->id => [1000, 2000],
            'relay-' . $child->id => [10, 20],
        ]);

        Bus::assertNotDispatched(TrafficFetchJob::class);
        Bus::assertDispatched(RelayNodeTrafficJob::class, 2);
    }

    public function test_relay_traffic_rejects_nodes_outside_the_entry(): void
    {
        Bus::fake();

        $entry = $this->makeEntry();
        $other = $this->makeEntry(['name' => '别的入口', 'port' => '25443', 'server_port' => 25443]);

        ServerService::processRelayTraffic($entry->fresh(), [
            (string) $other->id => [1000, 2000],
            '999999' => [1, 1],
            'bogus-tag' => [1, 1],
        ]);

        Bus::assertNotDispatched(RelayNodeTrafficJob::class);
    }

    public function test_relay_node_traffic_job_records_node_stats_without_rate(): void
    {
        $entry = $this->makeEntry();
        $child = $this->makeChild($entry);

        (new RelayNodeTrafficJob($child->id, $child->type, 1000, 2000))->handle();
        (new RelayNodeTrafficJob($child->id, $child->type, 500, 700))->handle();

        $child->refresh();
        $this->assertSame(1500, $child->u);
        $this->assertSame(2700, $child->d);

        $stat = StatServer::where('server_id', $child->id)->first();
        $this->assertNotNull($stat);
        $this->assertSame(1500, (int) $stat->u);
        $this->assertSame(2700, (int) $stat->d);
    }

    public function test_helper_writes_route_into_the_third_uuid_group(): void
    {
        $uuid = '11111111-2222-4333-8444-555555555555';

        $this->assertSame('11111111-2222-01bb-8444-555555555555', Helper::applyVlessRoute($uuid, 443));
        $this->assertSame('11111111-2222-ffff-8444-555555555555', Helper::applyVlessRoute($uuid, 65535));
        $this->assertSame('11111111-2222-0001-8444-555555555555', Helper::applyVlessRoute($uuid, 1));

        // 非法输入原样返回。
        $this->assertSame($uuid, Helper::applyVlessRoute($uuid, 65536));
        $this->assertSame($uuid, Helper::applyVlessRoute($uuid, -1));
        $this->assertSame($uuid, Helper::applyVlessRoute($uuid, null));
        $this->assertSame($uuid, Helper::applyVlessRoute($uuid, 'abc'));
        $this->assertSame('not-a-uuid', Helper::applyVlessRoute('not-a-uuid', 443));
    }

    public function test_entry_validation_rejects_invalid_topologies(): void
    {
        $entry = $this->makeEntry();
        $child = $this->makeChild($entry);
        $ssSettings = ['cipher' => '2022-blake3-aes-128-gcm'];

        $this->assertNull(
            ServerRelayService::validateEntry($child->id, $entry->id, Server::TYPE_SHADOWSOCKS, $ssSettings)
        );
        $this->assertNull(ServerRelayService::validateEntry(null, null, Server::TYPE_VLESS));

        // 已被引用的入口不能在编辑时改成其它协议或无效的 VLESS 组合。
        $this->assertNotNull(ServerRelayService::validateEntry(
            $entry->id,
            null,
            Server::TYPE_TROJAN,
        ));
        $invalidEntrySettings = (array) $entry->protocol_settings;
        $invalidEntrySettings['network'] = 'ws';
        $invalidEntrySettings['tls'] = 2;
        $this->assertNotNull(ServerRelayService::validateEntry(
            $entry->id,
            null,
            Server::TYPE_VLESS,
            $invalidEntrySettings,
            $entry->host,
        ));

        // 自引用
        $this->assertNotNull(
            ServerRelayService::validateEntry($child->id, $child->id, Server::TYPE_SHADOWSOCKS, $ssSettings)
        );
        // 非 Shadowsocks 中转协议
        $this->assertNotNull(
            ServerRelayService::validateEntry(null, $entry->id, Server::TYPE_TROJAN, $ssSettings)
        );
        // 不支持的加密算法
        $this->assertNotNull(
            ServerRelayService::validateEntry(null, $entry->id, Server::TYPE_SHADOWSOCKS, ['cipher' => 'rc4-md5'])
        );
        // 父级不是 VLESS 入口
        $this->assertNotNull(
            ServerRelayService::validateEntry(null, $child->id, Server::TYPE_SHADOWSOCKS, $ssSettings)
        );
        // 父级不存在
        $this->assertNotNull(
            ServerRelayService::validateEntry(null, 999999, Server::TYPE_SHADOWSOCKS, $ssSettings)
        );
        // 多层中转：入口自身已有父级
        $nested = $this->makeEntry(['name' => '二级入口', 'relay_entry_id' => $entry->id, 'port' => '26443', 'server_port' => 26443]);
        $this->assertNotNull(
            ServerRelayService::validateEntry(null, $nested->id, Server::TYPE_SHADOWSOCKS, $ssSettings)
        );
        // 已经是别人的父级入口
        $this->assertNotNull(
            ServerRelayService::validateEntry($entry->id, $nested->id, Server::TYPE_SHADOWSOCKS, $ssSettings)
        );
    }

    /**
     * 管理端保存节点时的表单校验：多层中转必须被拒绝，合法的一层中转必须通过。
     */
    public function test_server_save_request_validates_relay_topology(): void
    {
        $entry = $this->makeEntry();
        $child = $this->makeChild($entry);

        $payload = [
            'type' => Server::TYPE_SHADOWSOCKS,
            'name' => '非法多层',
            'relay_entry_id' => $child->id,
            'host' => '203.0.113.9',
            'port' => '28389',
            'server_port' => 28389,
            'rate' => 1,
            'group_ids' => ['1'],
            'protocol_settings' => ['cipher' => '2022-blake3-aes-128-gcm'],
        ];

        $errors = $this->validateServerSave($payload);
        $this->assertArrayHasKey('relay_entry_id', $errors);

        $payload['relay_entry_id'] = $entry->id;
        $this->assertArrayNotHasKey('relay_entry_id', $this->validateServerSave($payload));

        // 其它协议仍然不能作为中转落地。
        $payload['type'] = Server::TYPE_TROJAN;
        $this->assertArrayHasKey('relay_entry_id', $this->validateServerSave($payload));

        // 编辑现有入口时，即使表单里的前置入口为空，也不能破坏正在使用的入口配置。
        $entryPayload = [
            'id' => $entry->id,
            'type' => Server::TYPE_VLESS,
            'name' => $entry->name,
            'relay_entry_id' => 0,
            'host' => $entry->host,
            'port' => (string) $entry->port,
            'server_port' => $entry->server_port,
            'rate' => 1,
            'group_ids' => ['1'],
            'protocol_settings' => array_merge((array) $entry->protocol_settings, [
                'network' => 'ws',
                'tls' => 2,
            ]),
        ];
        $this->assertArrayHasKey('relay_entry_id', $this->validateServerSave($entryPayload));
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function validateServerSave(array $payload): array
    {
        $request = ServerSave::create('/', 'POST', $payload);
        $request->setContainer(app());

        $validator = app('validator')->make($payload, $request->rules(), $request->messages(), $request->attributes());
        $request->withValidator($validator);

        return $validator->errors()->toArray();
    }
}
