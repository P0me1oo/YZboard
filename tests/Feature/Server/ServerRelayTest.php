<?php

namespace Tests\Feature\Server;

use App\Http\Controllers\V2\Admin\Server\ManageController;
use App\Http\Requests\Admin\ServerSave;
use App\Jobs\RelayNodeTrafficJob;
use App\Jobs\TrafficFetchJob;
use App\Models\Server;
use App\Models\StatServer;
use App\Models\User;
use App\Services\ServerRelayService;
use App\Services\ServerService;
use App\Support\Setting;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Mockery\MockInterface;
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
