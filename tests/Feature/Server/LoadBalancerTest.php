<?php

namespace Tests\Feature\Server;

use App\Http\Controllers\V2\Admin\Server\LoadBalancerController;
use App\Models\BalancerDnsPreset;
use App\Models\BalancerDnsRecord;
use App\Models\BalancerPool;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Services\BalancerDnsService;
use App\Services\LoadBalancerService;
use App\Services\ServerRelayService;
use App\Services\ServerService;
use App\Utils\CacheKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LoadBalancerTest extends TestCase
{
    use RefreshDatabase;

    private function node(string $name, string $address): Server
    {
        $machine = ServerMachine::create(['name' => $name, 'token' => Str::random(32), 'is_active' => true, 'agent_runtime' => ['version' => 'v2.25.0']]);
        return Server::create(['name' => $name, 'machine_id' => $machine->id, 'host' => $address, 'type' => 'vless',
            'kernel_type' => 'xray', 'port' => '443', 'server_port' => 443, 'enabled' => true, 'show' => true, 'rate' => 1,
            'group_ids' => [1], 'protocol_settings' => ['network' => 'tcp', 'tls' => 2, 'flow' => 'xtls-rprx-vision',
                'reality_settings' => ['server_name' => 'handshake.example.com', 'server_port' => 443,
                    'private_key' => rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='),
                    'public_key' => rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='), 'short_id' => bin2hex(random_bytes(8))]]]);
    }

    private function pool(): array
    {
        $a = $this->node('入口 A', '192.0.2.10');
        $b = $this->node('入口 B', '192.0.2.11');
        $gateway = ServerMachine::create(['name' => '调度入口', 'token' => Str::random(32), 'is_active' => true, 'agent_runtime' => ['version' => 'v2.25.0']]);
        $pool = app(LoadBalancerService::class)->save(['name' => '测试前置池', 'domain' => 'entry.example.com',
            'dns_preset_id' => null, 'gateway_machine_id' => $gateway->id, 'gateway_address' => '192.0.2.1',
            'gateway_port' => 8443, 'enabled' => true,
            'members' => [['node_id' => $a->id, 'priority' => 1], ['node_id' => $b->id, 'priority' => 2]]]);
        return [$pool, $a, $b];
    }

    public function test_pool_creates_independent_instances_without_overwriting_direct_nodes(): void
    {
        [$pool, $a, $b] = $this->pool();
        $this->assertSame('443', (string) $a->fresh()->port);
        $this->assertTrue($a->fresh()->show);
        $this->assertSame('443', (string) $b->fresh()->port);
        $this->assertCount(2, $pool->members);
        foreach ($pool->members as $member) {
            $this->assertNotSame($member->source_node_id, $member->runtime_node_id);
            $this->assertSame('member', $member->runtimeNode->balancer_role);
            $this->assertFalse($member->runtimeNode->show);
            $this->assertNull($member->runtimeNode->parent_id);
            $this->assertSame($pool->backend_port, $member->runtimeNode->server_port);
        }
        $this->assertCount(2, ServerService::getAllServers());
        $config = ServerService::buildNodeConfig($pool->gateway);
        $this->assertSame('balancer', $config['protocol']);
        $this->assertSame('tcp', $config['balancer']['network']);
        $this->assertSame([1, 2], array_column($config['balancer']['targets'], 'priority'));
        $this->assertSame([false, false], array_column($config['balancer']['targets'], 'online'));
        $this->assertFalse(array_key_exists('template_config', $pool->toArray()));
    }

    public function test_controller_reads_machine_ip_and_first_node_port_without_form_fields(): void
    {
        $a = $this->node('自动前置 A', '192.0.2.10');
        $b = $this->node('自动前置 B', '192.0.2.11');
        $a->update(['port' => '9443']);
        $gateway = ServerMachine::create(['name' => '自动入口', 'token' => Str::random(32), 'is_active' => true,
            'agent_runtime' => ['version' => 'v2.25.0', 'public_ipv4' => '192.0.2.1', 'public_ipv6' => '2001:db8::1']]);
        (new LoadBalancerController())->save(Request::create('/', 'POST', ['name' => '自动配置池', 'domain' => 'auto.example.com',
            'gateway_machine_id' => $gateway->id, 'enabled' => true,
            'members' => [['node_id' => $a->id, 'priority' => 1], ['node_id' => $b->id, 'priority' => 1]]]), app(LoadBalancerService::class));
        $pool = BalancerPool::firstOrFail();
        $this->assertSame('192.0.2.1', $pool->gateway_address);
        $this->assertSame(9443, $pool->gateway_port);
        $this->assertSame(['192.0.2.1', '2001:db8::1'], $pool->gateway_source_ips);
        $this->assertSame($pool->gateway_source_ips, ServerService::buildNodeConfig($pool->members->first()->runtimeNode)['balancer_receiver']['trusted_addresses']);
        $this->assertSame(9443, ServerService::buildNodeConfig($pool->gateway)['server_port']);
        $this->assertSame('9443', (string) $a->fresh()->port);
        $this->assertSame(443, $a->fresh()->server_port);
    }

    public function test_automatic_connection_handles_port_conflicts_and_keeps_existing_endpoint(): void
    {
        $a = $this->node('共用机器前置', '192.0.2.10');
        $a->machine->update(['agent_runtime' => ['version' => 'v2.25.0', 'public_ip' => '192.0.2.10']]);
        $data = ['name' => '共用入口池', 'domain' => 'shared.example.com', 'gateway_machine_id' => $a->machine_id, 'enabled' => true,
            'members' => [['node_id' => $a->id, 'priority' => 1]]];
        $pool = app(LoadBalancerService::class)->save($data);
        $this->assertSame(20000, $pool->gateway_port);
        $this->assertSame(20001, $pool->backend_port);
        $this->assertSame('443', (string) $a->fresh()->port);
        $a->machine->update(['agent_runtime' => ['version' => 'v2.25.0', 'public_ipv4' => '192.0.2.99']]);
        $updated = app(LoadBalancerService::class)->save(['id' => $pool->id] + $data);
        $this->assertSame('192.0.2.10', $updated->gateway_address);
        $this->assertSame(20000, $updated->gateway_port);
        $this->assertSame($pool->gateway_node_id, $updated->gateway_node_id);
        $this->assertSame($pool->members->first()->runtime_node_id, $updated->members->first()->runtime_node_id);
    }

    public function test_changing_gateway_reads_ipv6_and_avoids_occupied_port(): void
    {
        [$pool, $a, $b] = $this->pool();
        $newGateway = $this->node('新入口机器', '192.0.2.20');
        $newGateway->update(['server_port' => 8443]);
        $newGateway->machine->update(['agent_runtime' => ['version' => 'v2.25.0', 'public_ipv4' => '192.0.2.20',
            'public_ipv4_at' => time() - 1000, 'public_ipv6' => '2001:db8::20', 'public_ipv6_at' => time(), 'public_ip' => '192.0.2.20']]);
        $data = $pool->only(['id', 'name', 'domain', 'dns_preset_id', 'enabled']);
        $data['gateway_machine_id'] = $newGateway->machine_id;
        $data['members'] = [['node_id' => $a->id, 'priority' => 1], ['node_id' => $b->id, 'priority' => 2]];
        $updated = app(LoadBalancerService::class)->save($data);
        $this->assertSame('2001:db8::20', $updated->gateway_address);
        $this->assertSame(20000, $updated->gateway_port);
        $this->assertSame('::', ServerService::buildNodeConfig($updated->gateway)['listen_ip']);
        $this->assertSame($pool->backend_port, $updated->backend_port);
    }

    public function test_missing_reported_gateway_ip_rejects_creation_without_side_effects(): void
    {
        $a = $this->node('未上报地址', '192.0.2.10');
        try {
            app(LoadBalancerService::class)->save(['name' => '无地址池', 'domain' => 'missing.example.com',
                'gateway_machine_id' => $a->machine_id, 'enabled' => true, 'members' => [['node_id' => $a->id, 'priority' => 1]]]);
            $this->fail('接受了没有上报地址的机器');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('gateway_machine_id', $error->errors());
            $this->assertSame(0, BalancerPool::count());
            $this->assertSame(1, Server::count());
        }
    }

    public function test_heartbeat_threshold_uses_each_runtime_instead_of_parent(): void
    {
        $this->travelTo(now()->startOfMinute());
        [$pool, $a] = $this->pool();
        $runtime = $pool->members->first()->runtimeNode;
        $key = CacheKey::get('SERVER_VLESS_LAST_CHECK_AT', $runtime->id);
        Cache::put(CacheKey::get('SERVER_VLESS_LAST_CHECK_AT', $a->id), now()->timestamp);
        $this->assertFalse(LoadBalancerService::online($runtime));
        Cache::put($key, now()->timestamp - 179);
        $this->assertTrue(LoadBalancerService::online($runtime));
        Cache::put($key, now()->timestamp - 180);
        $this->assertFalse(LoadBalancerService::online($runtime));
        Cache::put($key, now()->timestamp);
        $this->assertTrue(LoadBalancerService::online($runtime));
        admin_setting(['load_balance_offline_threshold' => 300]);
        Cache::put($key, now()->timestamp - 200);
        $this->assertTrue(LoadBalancerService::online($runtime));
    }

    public function test_fixed_landing_is_shared_and_reports_from_unrelated_pool_are_rejected(): void
    {
        [$pool] = $this->pool();
        $child = Server::create(['name' => '固定落地', 'type' => 'shadowsocks', 'kernel_type' => 'xray',
            'host' => '192.0.2.30', 'port' => '23000', 'server_port' => 23000, 'enabled' => true, 'show' => true,
            'rate' => 1, 'group_ids' => [1], 'relay_pool_id' => $pool->id,
            'protocol_settings' => ['cipher' => 'aes-128-gcm']]);
        $this->assertTrue($child->isRelayChild());
        $entry = ServerRelayService::entryFor($child);
        $this->assertSame('entry.example.com', $entry->host);
        $this->assertSame('8443', (string) $entry->port);
        foreach ($pool->members as $member) {
            $config = ServerService::buildNodeConfig($member->runtimeNode);
            $this->assertSame($child->id, $config['relay']['children'][0]['node_id']);
            $this->assertSame('192.0.2.30', $config['relay']['children'][0]['address']);
            $this->assertCount(1, ServerService::normalizeRelayTraffic($member->runtimeNode, [$child->id => [10, 20]]));
        }
        $unrelated = $this->node('其他前置', '192.0.2.40');
        $this->assertSame([], ServerService::normalizeRelayTraffic($unrelated, [$child->id => [10, 20]]));
        $this->assertSame('landing', ServerService::buildNodeConfig($child)['relay']['mode']);
    }

    public function test_saved_cloudflare_token_is_encrypted_and_never_returned(): void
    {
        $token = Str::random(40);
        $controller = new LoadBalancerController();
        $controller->savePreset(Request::create('/', 'POST', ['name' => '测试域名', 'domain' => 'example.com', 'zone_id' => str_repeat('a', 32), 'api_token' => $token]));
        $preset = BalancerDnsPreset::firstOrFail();
        $this->assertNotSame($token, DB::table('v2_balancer_dns_preset')->value('api_token'));
        $this->assertFalse(array_key_exists('api_token', $preset->toArray()));
        $controller->savePreset(Request::create('/', 'POST', ['id' => $preset->id, 'name' => '改名', 'domain' => 'example.com', 'zone_id' => str_repeat('a', 32), 'api_token' => '']));
        $this->assertTrue(hash_equals($token, $preset->fresh()->api_token));
    }

    public function test_dns_points_to_gateway_and_only_manages_its_own_records(): void
    {
        [$pool] = $this->pool();
        $preset = BalancerDnsPreset::create(['name' => '测试域名', 'domain' => 'example.com', 'zone_id' => str_repeat('a', 32), 'api_token' => Str::random(40)]);
        $pool->update(['dns_preset_id' => $preset->id]);
        $remote = [];
        Http::preventStrayRequests();
        Http::fake(function ($request) use (&$remote) {
            if ($request->method() === 'GET') return Http::response(['success' => true, 'result' => $remote]);
            if ($request->method() === 'POST') {
                $this->assertSame('192.0.2.1', $request['content']);
                $this->assertFalse($request['proxied']);
                $this->assertSame(60, $request['ttl']);
                $remote[] = ['id' => 'owned', 'name' => $request['name'], 'type' => 'A', 'content' => $request['content'],
                    'comment' => $request['comment'], 'ttl' => 60, 'proxied' => false];
                return Http::response(['success' => true, 'result' => ['id' => 'owned']]);
            }
            if ($request->method() === 'DELETE') { $remote = []; return Http::response(['success' => true, 'result' => ['id' => 'owned']]); }
            return Http::response(['success' => false], 500);
        });
        $dns = app(BalancerDnsService::class);
        $this->assertTrue($dns->sync($pool));
        $this->assertTrue($dns->sync($pool));
        $this->assertSame(1, BalancerDnsRecord::count());
        $pool->update(['enabled' => false]);
        $this->assertTrue($dns->sync($pool));
        $this->assertSame(0, BalancerDnsRecord::count());
        $remote = [['id' => 'manual', 'name' => $pool->domain, 'type' => 'A', 'content' => '192.0.2.99']];
        $pool->update(['enabled' => true]);
        $this->assertFalse($dns->sync($pool));
        $this->assertSame('manual', $remote[0]['id']);
    }

    public function test_lost_dns_create_response_recovers_by_marker_and_can_cleanup(): void
    {
        [$pool] = $this->pool();
        $preset = BalancerDnsPreset::create(['name' => '测试域名', 'domain' => 'example.com',
            'zone_id' => str_repeat('a', 32), 'api_token' => Str::random(40)]);
        $pool->update(['dns_preset_id' => $preset->id]);
        $remote = []; $loseResponse = true; $creates = 0;
        Http::preventStrayRequests();
        Http::fake(function ($request) use (&$remote, &$loseResponse, &$creates) {
            if ($request->method() === 'GET') return Http::response(['success' => true, 'result' => array_values($remote)]);
            if ($request->method() === 'POST') {
                $creates++;
                $record = [...$request->data(), 'id' => 'owned'];
                $this->assertSame($record['comment'], BalancerDnsRecord::firstOrFail()->marker);
                $remote['owned'] = $record;
                if ($loseResponse) { $loseResponse = false; return Http::response(['success' => false], 503); }
                return Http::response(['success' => true, 'result' => $record]);
            }
            if ($request->method() === 'PUT') {
                $remote['owned'] = [...$request->data(), 'id' => 'owned'];
                return Http::response(['success' => true, 'result' => $remote['owned']]);
            }
            if ($request->method() === 'DELETE') {
                $remote = [];
                return Http::response(['success' => true, 'result' => ['id' => 'owned']]);
            }
            return Http::response(['success' => false], 500);
        });
        $dns = app(BalancerDnsService::class);
        $this->assertFalse($dns->sync($pool));
        $this->assertNull(BalancerDnsRecord::firstOrFail()->record_id);
        $this->assertCount(1, $remote);
        $this->assertTrue($dns->sync($pool));
        $this->assertSame(1, $creates);
        $this->assertSame('owned', BalancerDnsRecord::firstOrFail()->record_id);
        $pool->update(['gateway_address' => '2001:db8::1']);
        $this->assertTrue($dns->sync($pool));
        $this->assertSame('AAAA', $remote['owned']['type']);
        $this->assertSame(1, $creates);
        $pool->update(['enabled' => false]);
        $this->assertTrue($dns->sync($pool));
        $this->assertCount(0, $remote);
        $this->assertSame(0, BalancerDnsRecord::count());
    }

    public function test_pending_dns_intent_is_cleaned_after_response_loss_without_enabling_again(): void
    {
        [$pool] = $this->pool();
        $preset = BalancerDnsPreset::create(['name' => '测试域名', 'domain' => 'example.com',
            'zone_id' => str_repeat('a', 32), 'api_token' => Str::random(40)]);
        $pool->update(['dns_preset_id' => $preset->id]);
        $record = BalancerDnsRecord::create(['pool_id' => $pool->id, 'record_id' => null, 'zone_id' => $preset->zone_id,
            'name' => $pool->domain, 'type' => 'A', 'content' => $pool->gateway_address, 'marker' => 'YZPOOL:' . bin2hex(random_bytes(16))]);
        $remote = [['id' => 'pending', 'name' => $pool->domain, 'type' => 'A', 'content' => $pool->gateway_address,
            'comment' => $record->marker, 'ttl' => 60, 'proxied' => false]];
        Http::preventStrayRequests();
        Http::fake(function ($request) use (&$remote) {
            if ($request->method() === 'GET') return Http::response(['success' => true, 'result' => $remote]);
            $this->assertSame('DELETE', $request->method());
            $remote = [];
            return Http::response(['success' => true, 'result' => ['id' => 'pending']]);
        });
        $pool->update(['enabled' => false]);
        $this->assertTrue(app(BalancerDnsService::class)->sync($pool));
        $this->assertSame(0, BalancerDnsRecord::count());
        $this->assertCount(0, $remote);
    }

    public function test_existing_dns_ids_without_marker_remain_managed_but_foreign_marker_is_rejected(): void
    {
        [$pool] = $this->pool();
        $preset = BalancerDnsPreset::create(['name' => '测试域名', 'domain' => 'example.com',
            'zone_id' => str_repeat('a', 32), 'api_token' => Str::random(40)]);
        $pool->update(['dns_preset_id' => $preset->id]);
        $record = BalancerDnsRecord::create(['pool_id' => $pool->id, 'record_id' => 'legacy', 'zone_id' => $preset->zone_id,
            'name' => $pool->domain, 'type' => 'A', 'content' => $pool->gateway_address]);
        Http::preventStrayRequests();
        Http::fake(fn () => Http::response(['success' => true, 'result' => [['id' => 'legacy', 'name' => $pool->domain,
            'type' => 'A', 'content' => $pool->gateway_address, 'ttl' => 60, 'proxied' => false, 'comment' => 'legacy note']]]));
        $dns = app(BalancerDnsService::class);
        $this->assertTrue($dns->sync($pool));
        $record->update(['marker' => 'YZPOOL:' . bin2hex(random_bytes(16))]);
        $this->assertFalse($dns->sync($pool));
        $this->assertSame(1, BalancerDnsRecord::count());
        Http::assertSentCount(2);
    }

    public function test_invalid_mixed_protocol_pool_rolls_back_without_touching_existing_nodes(): void
    {
        $a = $this->node('入口 A', '192.0.2.10');
        $b = $this->node('入口 B', '192.0.2.11');
        $b->update(['type' => 'hysteria', 'protocol_settings' => ['version' => 2]]);
        try {
            app(LoadBalancerService::class)->save(['name' => '无效池', 'domain' => 'entry.example.com', 'gateway_machine_id' => $a->machine_id,
                'gateway_address' => '192.0.2.1', 'gateway_port' => 8443, 'enabled' => true,
                'members' => [['node_id' => $a->id, 'priority' => 1], ['node_id' => $b->id, 'priority' => 1]]]);
            $this->fail('接受了混合协议');
        } catch (ValidationException) {
            $this->assertSame(0, BalancerPool::count());
            $this->assertSame(2, Server::count());
        }
    }

    public function test_wireguard_pool_uses_distinct_front_keys_and_addresses(): void
    {
        [$pool] = $this->pool();
        $child = Server::create(['name' => 'WG 落地', 'type' => 'wireguard', 'kernel_type' => 'singbox',
            'host' => '192.0.2.30', 'port' => '23000', 'server_port' => 23000, 'enabled' => true, 'show' => true,
            'rate' => 1, 'group_ids' => [1], 'relay_pool_id' => $pool->id, 'protocol_settings' => ['mtu' => 1420]]);
        $landing = ServerService::buildNodeConfig($child)['relay']['wireguard'];
        $this->assertCount(2, $landing['peers']);
        $this->assertNotSame($landing['peers'][0]['public_key'], $landing['peers'][1]['public_key']);
        $this->assertNotSame($landing['peers'][0]['allowed_ips'], $landing['peers'][1]['allowed_ips']);
        $fronts = $pool->members->map(fn ($member) => ServerService::buildNodeConfig($member->runtimeNode)['relay']['children'][0]['wireguard']);
        $this->assertNotSame($fronts[0]['private_key'], $fronts[1]['private_key']);
        $this->assertSame($fronts[0]['peer_public_key'], $fronts[1]['peer_public_key']);
        $this->assertSame($landing['peers'][0]['allowed_ips'], $fronts[0]['address']);
    }

    public function test_pool_members_only_authorize_bound_landing_routes(): void
    {
        [$pool, $a] = $this->pool();
        $user = \App\Models\User::create(['email' => 'pool-test@example.com', 'password' => Str::random(24),
            'uuid' => (string) Str::uuid(), 'token' => Str::random(32), 'banned' => 0,
            'group_id' => 1, 'expired_at' => null, 'transfer_enable' => 1024 * 1024, 'u' => 0, 'd' => 0]);
        $runtime = $pool->members->first()->runtimeNode;
        $this->assertCount(1, ServerService::getAvailableUsers($a));
        $this->assertCount(0, ServerService::getAvailableUsers($runtime));
        $child = Server::create(['name' => '唯一落地', 'type' => 'shadowsocks', 'kernel_type' => 'xray',
            'host' => '192.0.2.30', 'port' => '23000', 'server_port' => 23000, 'enabled' => true, 'show' => true,
            'rate' => 1, 'group_ids' => [1], 'relay_pool_id' => $pool->id, 'protocol_settings' => ['cipher' => 'aes-128-gcm']]);
        $allowed = ServerService::getAvailableUsers($runtime);
        $this->assertCount(1, $allowed);
        $this->assertSame((int) $user->id, (int) $allowed[0]->id);
        $this->assertSame([ServerRelayService::ensureRouteId($child)], $allowed[0]->relay_routes);
        $child->update(['enabled' => false]);
        $this->assertCount(0, ServerService::getAvailableUsers($runtime));
    }

    public function test_pool_updates_keep_template_and_direct_node_changes_independent(): void
    {
        [$pool, $a, $b] = $this->pool();
        $original = $pool->template_config;
        $settings = $a->protocol_settings;
        $settings['reality_settings']['short_id'] = bin2hex(random_bytes(8));
        $a->update(['enabled' => false, 'protocol_settings' => $settings]);
        $data = $pool->only(['id', 'name', 'domain', 'dns_preset_id', 'gateway_machine_id', 'gateway_address', 'gateway_port', 'enabled']);
        $data['members'] = [['node_id' => $b->id, 'priority' => 1], ['node_id' => $a->id, 'priority' => 2]];
        $updated = app(LoadBalancerService::class)->save($data);
        $this->assertTrue($original === $updated->template_config);
        foreach ($updated->members as $member) {
            $this->assertTrue($original['protocol_settings'] === $member->runtimeNode->protocol_settings);
            Cache::put(CacheKey::get('SERVER_VLESS_LAST_CHECK_AT', $member->runtime_node_id), now()->timestamp);
        }
        $this->assertSame([true, true], array_column(ServerService::buildNodeConfig($updated->gateway)['balancer']['targets'], 'online'));
        $this->assertFalse($a->fresh()->enabled);
    }

    public function test_older_agent_does_not_receive_managed_pool_instances(): void
    {
        [$pool, $a] = $this->pool();
        $a->machine->update(['agent_runtime' => ['version' => 'v2.24.2']]);
        $nodes = ServerService::getMachineNodes($a->machine->fresh());
        $this->assertTrue($nodes->contains('id', $a->id));
        $this->assertFalse($nodes->contains('id', $pool->members->first()->runtime_node_id));
    }

    public function test_gateway_cannot_reuse_its_own_member_port(): void
    {
        [$pool, $a, $b] = $this->pool();
        $data = $pool->only(['id', 'name', 'domain', 'dns_preset_id', 'gateway_address', 'enabled']);
        $data['gateway_machine_id'] = $a->machine_id;
        $data['gateway_port'] = $pool->backend_port;
        $data['members'] = [['node_id' => $a->id, 'priority' => 1], ['node_id' => $b->id, 'priority' => 1]];
        try {
            app(LoadBalancerService::class)->save($data);
            $this->fail('接受了入口与成员监听冲突');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('gateway_port', $error->errors());
            $this->assertSame(8443, $pool->fresh()->gateway_port);
        }
    }

    public function test_pool_can_be_stopped_when_all_machines_are_inactive_or_downgraded(): void
    {
        [$pool, $a, $b] = $this->pool();
        foreach (ServerMachine::whereIn('id', [$pool->gateway_machine_id, $a->machine_id, $b->machine_id])->get() as $machine) {
            $machine->update(['is_active' => false, 'agent_runtime' => ['version' => 'v2.24.2']]);
        }
        $data = $pool->only(['id', 'name', 'domain', 'dns_preset_id', 'gateway_machine_id', 'gateway_address', 'gateway_port']);
        $data['enabled'] = false;
        $data['members'] = [['node_id' => $a->id, 'priority' => 1], ['node_id' => $b->id, 'priority' => 1]];
        $stopped = app(LoadBalancerService::class)->save($data);
        $this->assertFalse($stopped->enabled);
        $this->assertSame(0, Server::where('balancer_pool_id', $pool->id)->where('enabled', true)->count());
        $data['enabled'] = true;
        try {
            app(LoadBalancerService::class)->save($data);
            $this->fail('启用了没有兼容服务器的池');
        } catch (ValidationException) {
            $this->assertFalse($pool->fresh()->enabled);
        }
    }

    public function test_original_nodes_cannot_be_deleted_while_pool_references_them(): void
    {
        [$pool, $a] = $this->pool();
        try {
            $a->delete();
            $this->fail('删除了仍被引用的节点');
        } catch (ValidationException) {
            $this->assertNotNull($a->fresh());
            $this->assertSame(2, $pool->members()->count());
        }
    }

    public function test_zero_pool_selection_is_normalized_to_no_pool(): void
    {
        $group = new \App\Models\ServerGroup();
        $group->name = '测试权限组';
        $group->save();
        $node = $this->node('普通直连', '192.0.2.10');
        $node->update(['group_ids' => [$group->id]]);
        $data = $node->toArray();
        $data['relay_pool_id'] = '0';
        $request = \App\Http\Requests\Admin\ServerSave::create('/', 'POST', $data);
        $request->setContainer($this->app)->setRedirector($this->app['redirect']);
        $request->validateResolved();
        $this->assertNull($request->validated()['relay_pool_id']);
    }
}
