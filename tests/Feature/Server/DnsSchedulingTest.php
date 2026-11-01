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
use App\Services\FrontendHealthChecker;
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
    private array $reachable = ['192.0.2.10', '192.0.2.11', '2001:db8::10', '192.0.2.99'];
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
        $this->mock(FrontendHealthChecker::class)->shouldReceive('reachable')->andReturnUsing(fn ($addresses) => array_values(array_intersect($addresses, $this->reachable)));
    }

    private function preset(): BalancerDnsPreset
    {
        return BalancerDnsPreset::create(['name' => '自建 DNS', 'provider' => 'powerdns', 'domain' => 'pool.example.com',
            'zone_id' => md5('pool.example.com'), 'api_url' => 'https://dns-api.example.test', 'server_id' => 'localhost', 'api_token' => Str::random(40)]);
    }

    private function node(string $ip): Server
    {
        $machine = ServerMachine::create(['name' => '前置', 'token' => Str::random(32), 'is_active' => true, 'agent_runtime' => ['version' => 'v2.25.0']]);
        return Server::create(['name' => '前置', 'machine_id' => $machine->id, 'host' => $ip, 'type' => 'vless', 'kernel_type' => 'xray',
            'port' => '443', 'server_port' => 443, 'enabled' => true, 'show' => true, 'rate' => 1, 'group_ids' => [1],
            'protocol_settings' => ['network' => 'tcp', 'tls' => 2, 'flow' => 'xtls-rprx-vision', 'reality_settings' => [
                'server_name' => 'handshake.example.com', 'server_port' => 443, 'short_id' => bin2hex(random_bytes(8)),
                'private_key' => rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='),
                'public_key' => rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=')]]]);
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
        $this->assertFalse($pool->personal_domain_enabled);
        $this->assertFalse($pool->follow_user_status);
        $this->assertNull($pool->gateway_node_id);
        $this->assertSame(0, Server::where('balancer_role', 'gateway')->count());
        $this->assertSame($pool->backend_port, $pool->gateway_port);
        foreach ($pool->members as $member) {
            $config = ServerService::buildNodeConfig($member->runtimeNode);
            $this->assertArrayNotHasKey('balancer_receiver', $config);
            $this->assertFalse($config['accept_proxy_protocol']);
            $this->assertSame(443, $member->sourceNode->server_port);
        }
        $this->assertSame($pool->domain, LoadBalancerService::entryForPool($pool->id)->host);
    }

    public function test_failover_uses_heartbeat_and_port_and_recovers_with_ddns_off(): void
    {
        $pool = $this->pool(['dns_ttl' => 120]);
        $this->assertTrue($this->sync($pool));
        $this->assertSame(['192.0.2.10'], $this->addresses());
        $this->assertSame(120, $this->remote['pool.example.com.|A']['ttl']);
        $this->heartbeat($pool->members[0]->runtime_node_id, time() - 181);
        // 同步时间很新也必须重新检查心跳，不等待十分钟的远端复核周期。
        $this->artisan('balancer:sync')->assertSuccessful();
        $this->assertSame(['192.0.2.11'], $this->addresses());
        $this->heartbeat($pool->members[0]->runtime_node_id, time());
        $this->reachable = ['192.0.2.11'];
        $this->assertTrue($this->sync($pool));
        $this->assertSame(['192.0.2.11'], $this->addresses());
        $this->reachable = [];
        $this->assertTrue($this->sync($pool));
        $this->assertSame([], $this->addresses());
        $this->reachable = ['192.0.2.10', '192.0.2.11'];
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
        // 保持实例心跳和端口正常，只让计量报告过期。
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
        $this->assertFalse($pool->fresh()->personal_domain_enabled);
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
        $this->assertSame('pool.example.com.', $this->remote[$name . '.|CNAME']['records'][0]['content']);
        $user->update(['banned' => true]);
        $this->assertTrue($this->sync($pool));
        $this->assertArrayHasKey($name . '.|CNAME', $this->remote);
        $pool->update(['follow_user_status' => true]);
        $this->assertTrue($this->sync($pool));
        $this->assertArrayNotHasKey($name . '.|CNAME', $this->remote);
        $user->update(['banned' => false]);
        $this->assertTrue($this->sync($pool));
        $this->assertSame($name, $project());
        $pool->update(['personal_domain_enabled' => false]);
        $this->assertTrue($this->sync($pool));
        $this->assertSame($pool->domain, $project());
        $this->assertArrayNotHasKey($name . '.|CNAME', $this->remote);
        $this->assertSame(1, PoolDnsAlias::count());
        $pool->update(['personal_domain_enabled' => true]);
        $this->assertTrue($this->sync($pool));
        $this->assertSame($name, $project());
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
        $this->assertSame(1, PowerDnsRecord::count());
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
        $this->assertFalse(PowerDnsRecord::first()->confirmed);
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

    public function test_legacy_pool_conversion_requires_cleanup_then_removes_gateway(): void
    {
        $source = $this->node('192.0.2.10');
        $pool = app(LoadBalancerService::class)->save(['name' => '旧池', 'domain' => 'pool.example.com', 'enabled' => true,
            'gateway_machine_id' => $source->machine_id, 'gateway_address' => '192.0.2.20', 'gateway_port' => 8443,
            'members' => [['node_id' => $source->id, 'priority' => 1]]]);
        $gatewayId = $pool->gateway_node_id;
        $payload = ['id' => $pool->id, 'name' => $pool->name, 'domain' => $pool->domain, 'enabled' => false, 'mode' => 'dns',
            'dns_preset_id' => $this->preset()->id, 'members' => [['node_id' => $source->id, 'priority' => 1]]];
        try { app(LoadBalancerService::class)->save($payload); $this->fail('启用中的旧池不应直接转换'); }
        catch (ValidationException $e) { $this->assertArrayHasKey('mode', $e->errors()); }
        $pool->update(['enabled' => false]);
        $converted = app(LoadBalancerService::class)->save($payload);
        $this->assertNull(Server::find($gatewayId));
        $this->assertNull($converted->gateway_node_id);
        $this->assertSame($pool->backend_port, $converted->gateway_port);
        $this->assertSame($pool->members[0]->runtime_node_id, $converted->members[0]->runtime_node_id);
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
}
