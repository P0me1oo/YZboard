<?php

namespace Tests\Feature\Server;

use App\Http\Controllers\V2\Admin\Server\LoadBalancerController;
use App\Models\BalancerDnsPreset;
use App\Models\BalancerPool;
use App\Models\PoolDnsAlias;
use App\Models\PowerDnsRecord;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\User;
use App\Services\BalancerDnsService;
use App\Services\DdnsService;
use App\Services\LoadBalancerService;
use App\Services\MachineTrafficService;
use App\Services\PowerDnsService;
use App\Services\ServerService;
use App\Utils\CacheKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DnsSchedulingTest extends TestCase
{
    use RefreshDatabase;

    private array $remote = [];
    private ?array $reality = null;
    private bool $loseResponse = false;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            $this->assertStringStartsWith('https://dns-api.example.test/api/v1/servers/localhost/zones/', $request->url());
            $this->assertNotEmpty($request->header('X-API-Key'));
            if ($request->method() === 'GET') return Http::response(['name' => 'pool.example.com.', 'rrsets' => array_values($this->remote)]);
            $this->assertSame('PATCH', $request->method());
            foreach ($request['rrsets'] as $rrset) {
                $key = $rrset['name'] . '|' . $rrset['type'];
                if ($rrset['changetype'] === 'DELETE') unset($this->remote[$key]);
                else $this->remote[$key] = $rrset;
            }
            if ($this->loseResponse) { $this->loseResponse = false; return Http::response([], 503); }
            return Http::response('', 204);
        });
        $this->app->instance('App\\Services\\FrontendHealthChecker', new class {
            public function reachable(): never { throw new \RuntimeException('DNS 同步不能执行端口探测'); }
        });
    }

    private function preset(): BalancerDnsPreset
    {
        return BalancerDnsPreset::create(['name' => '自建 DNS', 'provider' => 'powerdns', 'domain' => 'pool.example.com',
            'zone_id' => md5('pool.example.com'), 'api_url' => 'https://dns-api.example.test', 'server_id' => 'localhost', 'api_token' => Str::random(40)]);
    }

    private function node(string $ip): Server
    {
        $machine = ServerMachine::create(['name' => '前置', 'token' => Str::random(32), 'is_active' => true, 'agent_runtime' => ['version' => 'v2.25.0']]);
        $this->reality ??= ['server_name' => 'handshake.example.com', 'server_port' => 443, 'short_id' => '0123456789abcdef',
            'private_key' => rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='),
            'public_key' => rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=')];
        return Server::create(['name' => '前置', 'machine_id' => $machine->id, 'host' => $ip, 'type' => 'vless', 'kernel_type' => 'xray',
            'port' => '443', 'server_port' => 443, 'enabled' => true, 'show' => true, 'rate' => 1, 'group_ids' => [1],
            'protocol_settings' => ['network' => 'tcp', 'tls' => 2, 'flow' => 'xtls-rprx-vision', 'reality_settings' => $this->reality]]);
    }

    private function pool(array $extra = []): BalancerPool
    {
        $a = $this->node('192.0.2.10'); $b = $this->node('192.0.2.11');
        $preset = $this->preset();
        $pool = app(LoadBalancerService::class)->save([...['name' => 'DNS 前置池', 'domain' => $preset->domain, 'dns_preset_id' => $preset->id,
            'enabled' => true, 'members' => [['node_id' => $a->id, 'priority' => 1], ['node_id' => $b->id, 'priority' => 2]]], ...$extra]);
        foreach ($pool->members as $member) $this->heartbeat($member->runtime_node_id, time());
        return $pool;
    }

    private function heartbeat(int $id, int $time): void
    {
        Cache::put(CacheKey::get('SERVER_VLESS_LAST_CHECK_AT', $id), $time, 3600);
    }

    private function sync(BalancerPool $pool): bool
    {
        return app(BalancerDnsService::class)->sync($pool->fresh());
    }

    private function addresses(): array
    {
        return array_column($this->remote['pool.example.com.|A']['records'] ?? [], 'content');
    }

    public function test_new_pool_defaults_to_dns_without_gateway_or_proxy_receiver(): void
    {
        $pool = $this->pool();
        $this->assertSame('dns', $pool->mode);
        $this->assertFalse($pool->ddns_enabled);
        $this->assertTrue($pool->personal_domain_enabled);
        $this->assertFalse($pool->follow_user_status);
        $this->assertNull($pool->gateway_node_id);
        $this->assertSame(0, Server::whereNotNull('balancer_role')->count());
        $this->assertSame(2, Server::count());
        $this->assertSame(443, $pool->gateway_port);
        $this->assertSame($pool->backend_port, $pool->gateway_port);
        foreach ($pool->members as $member) {
            $this->assertSame($member->source_node_id, $member->runtime_node_id);
            $config = ServerService::buildNodeConfig($member->runtimeNode);
            $this->assertArrayNotHasKey('balancer_receiver', $config);
            $this->assertFalse($config['accept_proxy_protocol']);
            $this->assertSame(443, $member->sourceNode->server_port);
        }
        $this->assertSame($pool->domain, LoadBalancerService::entryForPool($pool->id)->host);
    }

    public function test_nat_reuses_original_services_and_first_public_port_even_when_member_order_changes(): void
    {
        $a = $this->node('192.0.2.10'); $b = $this->node('192.0.2.11');
        $a->update(['port' => '30443', 'server_port' => 443]);
        $b->update(['port' => '30443', 'server_port' => 8443]);
        $before = [$a->fresh()->getAttributes(), $b->fresh()->getAttributes()];
        $preset = $this->preset();
        $payload = ['mode' => 'dns', 'name' => 'NAT 池', 'domain' => $preset->domain, 'dns_preset_id' => $preset->id,
            'enabled' => true, 'members' => [['node_id' => $b->id, 'priority' => 2], ['node_id' => $a->id, 'priority' => 1]]];
        $pool = app(LoadBalancerService::class)->save($payload);
        $this->assertSame(30443, $pool->gateway_port);
        $this->assertSame($b->id, $pool->template_node_id);
        $this->assertSame([$b->id, $a->id], $pool->members->pluck('runtime_node_id')->all());
        $this->assertSame($before, [$a->fresh()->getAttributes(), $b->fresh()->getAttributes()]);
        $this->assertSame(2, Server::count());
        $entry = LoadBalancerService::entryForPool($pool->id);
        $this->assertSame('30443', $entry->port);
        $this->assertSame($b->protocol_settings, $entry->protocol_settings);
        $this->assertSame(8443, ServerService::buildNodeConfig($b->fresh())['server_port']);
        foreach ([$a, $b] as $node) $node->update(['port' => '15432']);
        $pool = app(LoadBalancerService::class)->save([...$payload, 'id' => $pool->id,
            'members' => array_reverse($payload['members'])]);
        $this->assertSame(15432, $pool->gateway_port);
        $this->assertSame($a->id, $pool->template_node_id);
        $this->assertSame(2, Server::count());
    }

    public function test_incompatible_connections_are_rejected_with_reasons_without_mutating_nodes(): void
    {
        $a = $this->node('192.0.2.10'); $b = $this->node('192.0.2.11');
        $preset = $this->preset();
        $payload = ['mode' => 'dns', 'name' => '兼容性池', 'domain' => $preset->domain, 'dns_preset_id' => $preset->id,
            'enabled' => true, 'members' => [['node_id' => $a->id, 'priority' => 1], ['node_id' => $b->id, 'priority' => 1]]];
        $settings = $b->protocol_settings;
        $cases = [
            [['port' => '8443'], '公网端口'],
            [['port' => '443,8443'], '单一端口'],
            [['type' => 'trojan'], '协议'],
            [['protocol_settings' => [...$settings, 'network' => 'ws']], '传输协议'],
            [['protocol_settings' => [...$settings, 'flow' => '']], '流控'],
            [['protocol_settings' => [...$settings, 'reality_settings' => [...$this->reality, 'public_key' => 'different-test-key']]], '公钥'],
            [['protocol_settings' => [...$settings, 'reality_settings' => [...$this->reality, 'short_id' => 'abcd']]], '短 ID'],
            [['protocol_settings' => [...$settings, 'reality_settings' => [...$this->reality, 'server_name' => 'different.example.test']]], '握手域名'],
            [['protocol_settings' => [...$settings, 'reality_settings' => [...$this->reality, 'server_port' => 8443]]], '握手端口'],
            [['accept_proxy_protocol' => true], 'PROXY'],
        ];
        foreach ($cases as [$changes, $reason]) {
            $b->update(['type' => 'vless', 'port' => '443', 'protocol_settings' => $settings, 'accept_proxy_protocol' => false, ...$changes]);
            $original = $b->fresh()->getAttributes();
            try { app(LoadBalancerService::class)->save($payload); $this->fail('不兼容成员不能保存'); }
            catch (ValidationException $error) { $this->assertStringContainsString($reason, implode('；', $error->errors()['members'])); }
            $this->assertSame($original, $b->fresh()->getAttributes());
            $this->assertSame(0, BalancerPool::count());
            $this->assertSame(2, Server::count());
        }
    }

    public function test_dns_and_list_use_panel_status_without_a_separate_pool_heartbeat_threshold(): void
    {
        $pool = $this->pool();
        $this->heartbeat($pool->members[0]->source_node_id, time() - 200);
        admin_setting(['load_balance_offline_threshold' => 30]);
        $this->assertTrue($this->sync($pool));
        $this->assertSame(['192.0.2.10'], $this->addresses());
        // 旧健康结果和检查时间不能影响列表中的原节点在线状态。
        $pool->members[0]->update(['healthy_addresses' => [], 'checked_at' => now()->subHour()]);
        $view = (new LoadBalancerController())->fetch()->getData(true)['data']['pools'][0];
        $this->assertTrue($view['members'][0]['online']);
        $this->heartbeat($pool->members[0]->source_node_id, time() - 301);
        $this->assertTrue($this->sync($pool));
        $this->assertSame(['192.0.2.11'], $this->addresses());
        $this->heartbeat($pool->members[0]->source_node_id, time());
        $this->assertTrue($this->sync($pool));
        $this->assertSame(['192.0.2.10'], $this->addresses());
        Cache::put('realtime:v1:node:' . $pool->members[0]->source_node_id, ['received_at' => now()->subMinutes(10)->getTimestampMs(), 'data' => []]);
        // 展示采样缓存不另设 DNS 离线条件，判定与节点列表的可用状态一致。
        $this->assertTrue($this->sync($pool));
        $this->assertSame(['192.0.2.10'], $this->addresses());
    }

    public function test_source_setting_changes_remove_dns_member_and_pool_can_still_be_disabled(): void
    {
        $pool = $this->pool();
        $pool->members[0]->sourceNode->update(['port' => '8443']);
        $this->assertTrue($this->sync($pool));
        $this->assertSame(['192.0.2.11'], $this->addresses());
        $view = (new LoadBalancerController())->fetch()->getData(true)['data']['pools'][0];
        $this->assertStringContainsString('公网端口', implode('；', $view['members'][0]['compatibility_reasons']));
        $disabled = app(LoadBalancerService::class)->save(['id' => $pool->id, 'mode' => 'dns', 'name' => $pool->name,
            'domain' => $pool->domain, 'dns_preset_id' => $pool->dns_preset_id, 'enabled' => false,
            'members' => $pool->members->map(fn ($member) => ['node_id' => $member->source_node_id, 'priority' => $member->priority])->all()]);
        $this->assertFalse($disabled->enabled);
        $this->assertTrue($this->sync($disabled));
        $this->assertSame([], $this->addresses());
        $this->assertTrue($pool->members[0]->sourceNode->fresh()->enabled);
    }

    public function test_reused_entry_serves_pool_routes_and_direct_users_and_removal_preserves_service(): void
    {
        $pool = $this->pool();
        $landing = $this->node('192.0.2.30');
        $landing->update(['relay_pool_id' => $pool->id, 'group_ids' => [2]]);
        $entry = $pool->members[0]->sourceNode;
        $config = ServerService::buildNodeConfig($entry);
        $this->assertSame($landing->id, $config['relay']['children'][0]['node_id']);
        $this->assertSame([['server_id' => $landing->id, 'server_type' => 'vless', 'u' => 10, 'd' => 20]],
            ServerService::normalizeRelayTraffic($entry, [$landing->id => [10, 20]]));
        foreach ([1, 2] as $group) User::create(['email' => Str::random(10) . '@example.test', 'password' => Str::random(24),
            'uuid' => Str::uuid()->toString(), 'token' => Str::random(32), 'group_id' => $group, 'banned' => false,
            'expired_at' => time() + 3600, 'transfer_enable' => 1024, 'u' => 0, 'd' => 0]);
        $users = ServerService::getAvailableUsers($entry);
        $this->assertCount(2, $users);
        $this->assertSame([[$entry->vless_route], [$landing->vless_route]], $users->pluck('relay_routes')->all());
        $pool->update(['enabled' => false]);
        $config = ServerService::buildNodeConfig($entry->fresh());
        $this->assertSame([], $config['relay']['children']);
        $this->assertContains($landing->vless_route, $config['relay']['blocked_route_ids']);
        $landing->update(['relay_pool_id' => null]);
        (new LoadBalancerController())->delete(Request::create('/', 'POST', ['id' => $pool->id]));
        $this->assertNotNull($entry->fresh());
        $this->assertTrue($entry->fresh()->enabled);
        $this->assertSame(3, Server::count());
    }

    public function test_dns_wireguard_pool_and_member_removal_keep_original_nodes(): void
    {
        $pool = $this->pool();
        $child = Server::create(['name' => 'WG 落地', 'type' => 'wireguard', 'kernel_type' => 'singbox',
            'host' => '192.0.2.30', 'port' => '23000', 'server_port' => 23000, 'enabled' => true, 'show' => true,
            'rate' => 1, 'group_ids' => [1], 'relay_pool_id' => $pool->id, 'protocol_settings' => ['mtu' => 1420]]);
        $landing = ServerService::buildNodeConfig($child)['relay']['wireguard'];
        $this->assertCount(2, $landing['peers']);
        $fronts = $pool->members->map(fn ($member) => ServerService::buildNodeConfig($member->sourceNode)['relay']['children'][0]['wireguard']);
        $this->assertNotSame($fronts[0]['private_key'], $fronts[1]['private_key']);
        $this->assertSame($landing['peers'][0]['allowed_ips'], $fronts[0]['address']);
        $removed = $pool->members[1]->sourceNode;
        $attributes = $removed->getAttributes();
        $pool = app(LoadBalancerService::class)->save(['id' => $pool->id, 'mode' => 'dns', 'name' => $pool->name,
            'domain' => $pool->domain, 'dns_preset_id' => $pool->dns_preset_id, 'enabled' => true,
            'members' => [['node_id' => $pool->members[0]->source_node_id, 'priority' => 1]]]);
        $this->assertCount(1, $pool->members);
        $this->assertSame($attributes, $removed->fresh()->getAttributes());
        $this->assertArrayNotHasKey('relay', ServerService::buildNodeConfig($removed->fresh()));
        $this->assertCount(1, ServerService::buildNodeConfig($child->fresh())['relay']['wireguard']['peers']);
    }

    public function test_failover_uses_panel_status_and_recovers_with_ddns_off(): void
    {
        $pool = $this->pool(['dns_ttl' => 120]);
        $this->assertTrue($this->sync($pool));
        $this->assertSame(['192.0.2.10'], $this->addresses());
        $this->assertSame(120, $this->remote['pool.example.com.|A']['ttl']);
        $this->heartbeat($pool->members[0]->runtime_node_id, time() - 301);
        // 同步时间很新也必须重新检查心跳，不等待十分钟的远端复核周期。
        $this->artisan('balancer:sync')->assertSuccessful();
        $this->assertSame(['192.0.2.11'], $this->addresses());
        $this->heartbeat($pool->members[0]->runtime_node_id, time());
        $pool->members[0]->sourceNode->machine->update(['is_active' => false]);
        $this->assertTrue($this->sync($pool));
        $this->assertSame(['192.0.2.11'], $this->addresses());
        $pool->members[1]->sourceNode->machine->update(['is_active' => false]);
        $this->assertTrue($this->sync($pool));
        $this->assertSame([], $this->addresses());
        foreach ($pool->members as $member) $member->sourceNode->machine->update(['is_active' => true]);
        $this->assertTrue($this->sync($pool));
        $this->assertSame(['192.0.2.10'], $this->addresses());
        $pool->members[1]->update(['priority' => 1]);
        $this->assertTrue($this->sync($pool));
        $this->assertSame(['192.0.2.10', '192.0.2.11'], $this->addresses());
    }

    public function test_traffic_report_timeout_removes_member_after_180_seconds_and_recovers(): void
    {
        $this->freezeTime();
        $pool = $this->pool();
        $machine = $pool->members[0]->runtimeNode->machine;
        MachineTrafficService::configure($machine, ['traffic_limit_gb' => 1]);
        $machine->saveQuietly();
        $policy = MachineTrafficService::view($machine);
        $report = ['epoch' => $policy['epoch'], 'period_start' => $policy['period_start'],
            'sequence' => 1, 'rx' => 0, 'tx' => 0, 'initialized' => true];
        $this->assertFalse(MachineTrafficService::exchange($machine->id, $report)['blocked']);

        foreach ([31, 148] as $seconds) {
            $this->travel($seconds)->seconds();
            $this->assertTrue($this->sync($pool));
            $this->assertSame(['192.0.2.10'], $this->addresses());
        }
        $this->travel(1)->seconds();
        // 保持实例心跳正常，只让计量报告过期。
        foreach ($pool->members as $member) $this->heartbeat($member->runtime_node_id, now()->timestamp);
        $this->assertTrue($this->sync($pool));
        $this->assertSame(['192.0.2.11'], $this->addresses());
        $report['sequence']++;
        $this->assertFalse(MachineTrafficService::exchange($machine->id, $report)['blocked']);
        $this->assertTrue($this->sync($pool));
        $this->assertSame(['192.0.2.10'], $this->addresses());
    }

    public function test_ddns_only_controls_address_changes_and_preserves_other_switches(): void
    {
        $pool = $this->pool();
        $pool->members[0]->sourceNode->machine->update(['agent_runtime' => ['version' => 'v2.25.0', 'public_ipv4' => '192.0.2.99', 'public_ipv6' => '2001:db8::10']]);
        $this->assertTrue($this->sync($pool));
        $this->assertSame(['192.0.2.10'], $this->addresses());
        $pool->update(['ddns_enabled' => true]);
        $this->assertTrue($this->sync($pool));
        $this->assertSame(['192.0.2.99'], $this->addresses());
        $this->assertSame('2001:db8::10', $this->remote['pool.example.com.|AAAA']['records'][0]['content']);
        $this->assertTrue($pool->fresh()->personal_domain_enabled);
        $this->assertFalse($pool->fresh()->follow_user_status);
        $pool->update(['ddns_enabled' => false]);
        $pool->members[0]->sourceNode->machine->update(['agent_runtime' => ['version' => 'v2.25.0', 'public_ipv4' => '192.0.2.10']]);
        $this->assertTrue($this->sync($pool));
        $this->assertSame(['192.0.2.99'], $this->addresses());
    }

    public function test_fixed_fourth_level_alias_and_status_follow_are_independent_of_ddns(): void
    {
        $pool = $this->pool(['personal_domain_enabled' => true]);
        $route = $this->node('192.0.2.30'); $route->update(['relay_pool_id' => $pool->id]);
        $user = User::create(['email' => Str::random(10) . '@example.test', 'password' => Str::random(24), 'uuid' => Str::uuid()->toString(),
            'token' => Str::random(32), 'group_id' => 1, 'banned' => false, 'expired_at' => time() + 3600, 'transfer_enable' => 1024, 'u' => 0, 'd' => 0]);
        $project = fn () => app(DdnsService::class)->project([$route->toArray()], $user->fresh())[0]['host'];
        $this->assertTrue($this->sync($pool));
        $name = $project();
        $this->assertSame(4, count(explode('.', $name)));
        $this->assertStringStartsWith('entry-', $this->remote[$name . '.|CNAME']['records'][0]['content']);
        $user->update(['banned' => true]);
        $this->assertTrue($this->sync($pool));
        $this->assertArrayHasKey($name . '.|CNAME', $this->remote);
        $pool->update(['follow_user_status' => true]);
        $this->assertTrue($this->sync($pool));
        $this->assertArrayNotHasKey($name . '.|CNAME', $this->remote);
        $user->update(['banned' => false]);
        $this->assertTrue($this->sync($pool));
        $this->assertSame($name, $project());
        $this->assertSame($name, app(\App\Services\DnsPoolService::class)->project($pool->fresh(), $user));
    }

    public function test_apex_ns_survives_and_lost_response_is_reconciled_without_duplicates(): void
    {
        $pool = $this->pool();
        $ns = ['name' => 'pool.example.com.', 'type' => 'NS', 'ttl' => 3600, 'records' => [['content' => 'ns.example.com.', 'disabled' => false]], 'comments' => []];
        $this->remote['pool.example.com.|NS'] = $ns;
        $this->loseResponse = true;
        $this->assertFalse($this->sync($pool));
        $this->assertFalse(PowerDnsRecord::first()->confirmed);
        $this->assertTrue($this->sync($pool));
        $this->assertSame(2, PowerDnsRecord::count());
        $this->assertSame($ns, $this->remote['pool.example.com.|NS']);
        $pool->update(['enabled' => false]);
        $this->assertTrue($this->sync($pool));
        $this->assertSame(['pool.example.com.|NS' => $ns], $this->remote);
        $this->assertSame(0, PowerDnsRecord::count());
    }

    public function test_foreign_record_is_never_overwritten_or_deleted(): void
    {
        $pool = $this->pool();
        $this->assertTrue($this->sync($pool));
        $this->remote['pool.example.com.|A']['comments'] = [['content' => 'foreign owner']];
        $before = $this->remote;
        $this->assertFalse($this->sync($pool));
        $this->assertFalse(PowerDnsRecord::where('name', $pool->domain)->first()->confirmed);
        $pool->update(['enabled' => false]);
        $this->assertFalse($this->sync($pool));
        $this->assertSame($before, $this->remote);
    }

    public function test_wildcard_cannot_bypass_personal_record_revocation(): void
    {
        $pool = $this->pool();
        $wildcard = ['name' => '*.pool.example.com.', 'type' => 'A', 'ttl' => 60,
            'records' => [['content' => '192.0.2.10', 'disabled' => false]], 'comments' => []];
        $this->remote['*.pool.example.com.|A'] = $wildcard;
        $this->assertFalse($this->sync($pool));
        $this->assertSame(['*.pool.example.com.|A' => $wildcard], $this->remote);
    }

    public function test_unmanaged_other_address_family_is_not_mixed_into_pool_answers(): void
    {
        $pool = $this->pool();
        $record = ['name' => 'pool.example.com.', 'type' => 'AAAA', 'ttl' => 60,
            'records' => [['content' => '2001:db8::99', 'disabled' => false]], 'comments' => []];
        $this->remote['pool.example.com.|AAAA'] = $record;
        $this->assertFalse($this->sync($pool));
        $this->assertSame(['pool.example.com.|AAAA' => $record], $this->remote);
    }

    public function test_node_personal_domains_use_powerdns_without_cloudflare_quota_and_wait_for_confirmation(): void
    {
        $node = $this->node('192.0.2.10');
        $preset = $this->preset();
        $user = User::create(['email' => Str::random(10) . '@example.test', 'password' => Str::random(24), 'uuid' => Str::uuid()->toString(),
            'token' => Str::random(32), 'group_id' => 1, 'banned' => false, 'expired_at' => time() + 3600, 'transfer_enable' => 1024, 'u' => 0, 'd' => 0]);
        $service = app(DdnsService::class);
        $service->configure('node', $node, ['enabled' => false, 'personal_enabled' => true, 'preset_id' => $preset->id, 'prefix' => 'node', 'ttl' => 120]);
        $this->loseResponse = true;
        $this->assertFalse($service->synchronize());
        try { $service->project([$node->toArray()], $user); $this->fail('不能下发尚未确认的解析'); }
        catch (\Illuminate\Http\Exceptions\HttpResponseException $e) { $this->assertSame(503, $e->getResponse()->getStatusCode()); }
        $this->assertTrue($service->synchronize());
        $name = $service->project([$node->toArray()], $user)[0]['host'];
        $this->assertStringEndsWith('.node.pool.example.com', $name);
        $this->assertCount(2, $this->remote);
        $this->assertSame(120, $this->remote[$name . '.|CNAME']['ttl']);
    }


    public function test_powerdns_preset_validation_hides_credentials_and_rejects_redirect_url_components(): void
    {
        $controller = new LoadBalancerController();
        $data = ['name' => '自建 DNS', 'domain' => 'pool.example.com', 'provider' => 'powerdns', 'api_url' => 'https://dns-api.example.test', 'api_token' => Str::random(40)];
        $controller->savePreset(Request::create('/', 'POST', $data));
        $preset = BalancerDnsPreset::firstOrFail();
        $this->assertArrayNotHasKey('api_token', $preset->toArray());
        $this->assertNotSame($data['api_token'], $preset->getRawOriginal('api_token'));
        $controller->savePreset(Request::create('/', 'POST', [...$data, 'id' => $preset->id, 'api_token' => '']));
        $this->assertSame($data['api_token'], $preset->fresh()->api_token);
        $this->expectException(ValidationException::class);
        $controller->savePreset(Request::create('/', 'POST', [...$data, 'api_url' => 'https://dns-api.example.test/?token=example']));
    }

    private function usersForPool(BalancerPool $pool): array
    {
        $route = $this->node('192.0.2.30');
        $route->update(['relay_pool_id' => $pool->id]);
        $users = [];
        for ($i = 0; $i < 40; $i++) {
            $user = User::create(['email' => 'assignment-' . $i . '@example.test', 'password' => Str::random(24),
                'uuid' => Str::uuid()->toString(), 'token' => Str::random(32), 'group_id' => 1, 'banned' => false,
                'expired_at' => time() + 3600, 'transfer_enable' => 1024, 'u' => 0, 'd' => 0]);
            PoolDnsAlias::create(['pool_id' => $pool->id, 'user_id' => $user->id, 'label' => substr(hash('sha256', 'user-test-' . $i), 0, 32)]);
            $users[] = $user;
        }
        return $users;
    }

    public function test_personal_dns_uses_member_addresses_and_only_failed_member_users_move(): void
    {
        $pool = $this->pool(); $pool->members[1]->update(['priority' => 1]);
        $users = $this->usersForPool($pool);
        $this->assertTrue($this->sync($pool));
        $before = PoolDnsAlias::where('pool_id', $pool->id)->pluck('member_id', 'label')->all();
        $this->assertCount(2, array_unique($before));
        foreach ($users as $user) {
            $name = app(\App\Services\DnsPoolService::class)->project($pool->fresh(), $user);
            $target = $this->remote[$name . '.|CNAME']['records'][0]['content'];
            $this->assertStringStartsWith('entry-', $target);
            $this->assertArrayHasKey($target . '|A', $this->remote);
            $this->assertCount(1, $this->remote[$target . '|A']['records']);
        }
        $pool->members[0]->runtimeNode->update(['enabled' => false]);
        $this->assertTrue($this->sync($pool));
        $after = PoolDnsAlias::where('pool_id', $pool->id)->pluck('member_id', 'label')->all();
        foreach ($before as $label => $id) $this->assertSame($pool->members[1]->id, $after[$label]);
        $pool->members[0]->runtimeNode->update(['enabled' => true]);
        $this->assertTrue($this->sync($pool));
        $this->assertSame($before, PoolDnsAlias::where('pool_id', $pool->id)->pluck('member_id', 'label')->all());
        $view = (new LoadBalancerController())->fetch()->getData(true)['data']['pools'][0];
        $this->assertSame(40, array_sum(array_column($view['members'], 'assigned_users')));
        $this->assertArrayNotHasKey('gateway_machine_id', $view);
    }

    public function test_assignment_changes_are_only_saved_after_confirmed_dns_write(): void
    {
        $pool = $this->pool(); $pool->members[1]->update(['priority' => 1]);
        $users = $this->usersForPool($pool);
        $this->assertTrue($this->sync($pool));
        $before = PoolDnsAlias::where('pool_id', $pool->id)->pluck('member_id', 'label')->all();
        $pool->members[0]->runtimeNode->update(['enabled' => false]);
        $this->loseResponse = true;
        $this->assertFalse($this->sync($pool));
        $this->assertSame($before, PoolDnsAlias::where('pool_id', $pool->id)->pluck('member_id', 'label')->all());
        $this->assertTrue($this->sync($pool));
        $this->assertTrue($this->sync($pool));
        $this->assertSame(40, PoolDnsAlias::where('pool_id', $pool->id)->where('member_id', $pool->members[1]->id)->count());
        $this->assertSame(42, PowerDnsRecord::where('owner_type', 'pool')->count());
        foreach ($users as $user) $this->assertNotEmpty(app(\App\Services\DnsPoolService::class)->project($pool->fresh(), $user));
        $pool->update(['enabled' => false]);
        $this->assertTrue($this->sync($pool));
        $this->assertSame(0, PowerDnsRecord::where('owner_type', 'pool')->count());
        $this->assertSame(0, PoolDnsAlias::where('pool_id', $pool->id)->whereNotNull('member_id')->count());
        $this->assertSame(40, PoolDnsAlias::where('pool_id', $pool->id)->count());
    }

    public function test_old_transfer_api_is_rejected_and_migration_preserves_unconverted_data(): void
    {
        $pool = $this->pool();
        $controller = new LoadBalancerController();
        $payload = ['id' => $pool->id, 'name' => $pool->name, 'domain' => $pool->domain, 'enabled' => true,
            'dns_preset_id' => $pool->dns_preset_id, 'members' => [['node_id' => $pool->members[0]->source_node_id, 'priority' => 1]]];
        foreach ([['mode' => 'legacy'], ['gateway_machine_id' => $pool->members[0]->sourceNode->machine_id], ['members' => [['node_id' => $pool->members[0]->source_node_id, 'priority' => 1, 'weight' => 0]]]] as $extra) {
            try { $controller->save(Request::create('/', 'POST', [...$payload, ...$extra]), app(LoadBalancerService::class)); $this->fail('无效配置应被拒绝'); }
            catch (ValidationException) { $this->assertSame(2, $pool->members()->count()); }
        }
        \Illuminate\Support\Facades\DB::table('v2_balancer_pool')->where('id', $pool->id)->update(['mode' => 'legacy']);
        $before = $pool->fresh()->getAttributes();
        $migration = require database_path('migrations/2026_11_03_000001_assign_frontends_to_users.php');
        try { $migration->up(); $this->fail('旧池未转换时不能继续升级'); }
        catch (\RuntimeException $error) { $this->assertStringContainsString('旧版本', $error->getMessage()); }
        $this->assertSame($before, $pool->fresh()->getAttributes());
        $this->assertSame(2, Server::count());
        $this->assertSame(2, $pool->members()->count());
    }

    public function test_disabling_with_normalized_default_policy_does_not_validate_changed_reality_keys(): void
    {
        $pool = $this->pool();
        $pool->update(['scheduling_config' => null]);
        $node = $pool->members[1]->sourceNode;
        $settings = $node->protocol_settings;
        $settings['reality_settings']['public_key'] = 'changed-test-public-key';
        $settings['reality_settings']['short_id'] = 'abcd';
        $node->update(['protocol_settings' => $settings]);
        $this->assertNull($pool->scheduling_config);
        (new LoadBalancerController())->save(Request::create('/', 'POST', ['id' => $pool->id, 'mode' => 'dns',
            'name' => $pool->name, 'domain' => $pool->domain, 'dns_preset_id' => $pool->dns_preset_id, 'enabled' => false,
            'scheduling_config' => \App\Services\FrontPoolScheduler::DEFAULTS,
            'members' => $pool->members->map(fn ($m) => ['node_id' => $m->source_node_id, 'priority' => $m->priority, 'weight' => 1])->all()]), app(LoadBalancerService::class));
        $this->assertFalse($pool->fresh()->enabled);
        $this->assertSame($settings, $node->fresh()->protocol_settings);
    }

    public function test_delete_automatically_stops_and_cleans_dns_before_removing_only_pool_configuration(): void
    {
        $pool = $this->pool();
        $this->assertTrue($this->sync($pool));
        $node = $pool->members[1]->sourceNode;
        $settings = $node->protocol_settings;
        $settings['reality_settings']['public_key'] = 'different-test-key';
        $node->update(['protocol_settings' => $settings]);
        (new LoadBalancerController())->delete(Request::create('/', 'POST', ['id' => $pool->id]));
        $this->assertNull($pool->fresh());
        $this->assertSame([], $this->remote);
        $this->assertSame(0, PowerDnsRecord::where('owner_type', 'pool')->count());
        $this->assertSame(2, Server::count());
        $this->assertSame($settings, $node->fresh()->protocol_settings);
    }

    public function test_delete_dns_failure_keeps_disabled_pool_and_retry_recovers_owned_records(): void
    {
        $pool = $this->pool(); $this->assertTrue($this->sync($pool));
        $this->loseResponse = true;
        try { (new LoadBalancerController())->delete(Request::create('/', 'POST', ['id' => $pool->id])); $this->fail('DNS 清理未确认时不能删除配置'); }
        catch (ValidationException $error) { $this->assertArrayHasKey('dns', $error->errors()); }
        $this->assertFalse($pool->fresh()->enabled);
        $this->assertSame(2, $pool->members()->count());
        $this->assertGreaterThan(0, PowerDnsRecord::where('owner_type', 'pool')->count());
        (new LoadBalancerController())->delete(Request::create('/', 'POST', ['id' => $pool->id]));
        $this->assertNull($pool->fresh());
        $this->assertSame([], $this->remote);
        $this->assertSame(2, Server::count());
    }

    public function test_delete_referenced_pool_preserves_enabled_state_and_dns(): void
    {
        $pool = $this->pool();
        $route = $this->node('192.0.2.30'); $route->update(['relay_pool_id' => $pool->id]);
        $this->assertTrue($this->sync($pool)); $before = $this->remote;
        try { (new LoadBalancerController())->delete(Request::create('/', 'POST', ['id' => $pool->id])); $this->fail('不能删除仍被落地引用的池'); }
        catch (ValidationException $error) { $this->assertArrayHasKey('id', $error->errors()); }
        $this->assertTrue($pool->fresh()->enabled);
        $this->assertSame($before, $this->remote);
        $this->assertSame(3, Server::count());
    }
}
