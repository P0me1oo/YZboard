<?php

namespace Tests\Feature\Server;

use App\Http\Controllers\V2\Admin\DdnsController;
use App\Http\Controllers\V2\Admin\Server\ManageController;
use App\Models\BalancerDnsPreset;
use App\Models\BalancerPool;
use App\Models\DdnsAlias;
use App\Models\DdnsBinding;
use App\Models\DdnsRecord;
use App\Models\Server;
use App\Models\User;
use App\Services\CloudflareDnsClient;
use App\Services\DdnsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DdnsTest extends TestCase
{
    use RefreshDatabase;

    private array $remote = [];
    private int $quota = 3500;
    private int $sequence = 1;
    private bool $failAfterWrite = false;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if (str_ends_with($path, '/dns_records/usage')) return Http::response(['success' => true,
                'result' => ['record_quota' => $this->quota, 'record_usage' => count($this->remote)]]);
            if ($request->method() === 'GET') return Http::response(['success' => true, 'result' => array_values($this->remote), 'result_info' => ['total_pages' => 1]]);
            $result = ['deletes' => [], 'puts' => [], 'posts' => []];
            foreach ($request['deletes'] ?? [] as $record) { unset($this->remote[$record['id']]); $result['deletes'][] = $record; }
            foreach (['puts', 'posts'] as $operation) {
                foreach ($request[$operation] ?? [] as $record) {
                    $record['id'] ??= str_pad((string) $this->sequence++, 32, '0', STR_PAD_LEFT);
                    $this->remote[$record['id']] = $record;
                    $result[$operation][] = $record;
                }
            }
            if ($this->failAfterWrite) { $this->failAfterWrite = false; return Http::response(['success' => false], 503); }
            return Http::response(['success' => true, 'result' => $result]);
        });
        Route::post('/_tests/ddns/node/save', [ManageController::class, 'save']);
    }

    private function node(string $ip = '192.0.2.10', array $extra = []): Server
    {
        return Server::create([...['name' => 'DDNS 测试节点', 'type' => 'vless', 'host' => $ip,
            'port' => '443', 'server_port' => 443, 'rate' => 1, 'group_ids' => [1], 'show' => true,
            'enabled' => true, 'protocol_settings' => ['tls' => 2, 'network' => 'tcp',
                'reality_settings' => ['server_name' => 'handshake.example.com']]], ...$extra]);
    }

    private function user(array $extra = []): User
    {
        return User::create([...['email' => Str::random(12) . '@ddns.example.test', 'password' => Str::random(24),
            'uuid' => Str::uuid()->toString(), 'token' => Str::random(32), 'group_id' => 1,
            'expired_at' => time() + 3600, 'banned' => false, 'transfer_enable' => 1024, 'u' => 0, 'd' => 0], ...$extra]);
    }

    private function configure(Server|BalancerPool $owner, string $prefix = 'hk01'): DdnsBinding
    {
        $preset = BalancerDnsPreset::first() ?? BalancerDnsPreset::create(['name' => '临时测试域名', 'domain' => 'ddns.example.com',
            'zone_id' => str_repeat('a', 32), 'api_token' => Str::random(40)]);
        $type = $owner instanceof Server ? 'node' : 'pool';
        app(DdnsService::class)->configure($type, $owner, ['enabled' => true, 'preset_id' => $preset->id, 'prefix' => $prefix]);
        return DdnsService::current($type, $owner->id);
    }

    private function sync(): bool
    {
        return app(DdnsService::class)->synchronize();
    }

    private function projected(Server $node, User $user): array
    {
        return app(DdnsService::class)->project([$node->fresh()->toArray()], $user->fresh())[0];
    }

    public function test_manual_node_ip_updates_base_only_and_preserves_user_domains_and_tls(): void
    {
        $node = $this->node(); $a = $this->user(); $b = $this->user();
        $binding = $this->configure($node);
        $this->assertTrue($this->sync());
        $this->assertCount(3, $this->remote);
        $first = $this->projected($node, $a);
        $this->assertNotSame($first['host'], $this->projected($node, $b)['host']);
        $this->assertStringEndsWith('.' . $binding->domain, $first['host']);
        $this->assertSame($node->protocol_settings, $first['protocol_settings']);
        $aliases = DdnsRecord::where('type', 'CNAME')->pluck('record_id', 'name')->all();
        $node->update(['host' => '192.0.2.99']);
        $this->assertTrue($this->sync());
        $this->assertSame($first['host'], $this->projected($node, $a)['host']);
        $this->assertSame($aliases, DdnsRecord::where('type', 'CNAME')->pluck('record_id', 'name')->all());
        $this->assertSame('192.0.2.99', DdnsRecord::where('type', 'A')->value('content'));
        $this->assertSame('192.0.2.99', $node->fresh()->host);
        $before = count(Http::recorded());
        $this->assertTrue($this->sync());
        $this->assertSame($before, count(Http::recorded()));
        foreach ($this->remote as $record) { $this->assertFalse($record['proxied']); $this->assertSame(60, $record['ttl']); }
    }

    public function test_expiry_without_model_event_revokes_alias_and_renewal_restores_same_name(): void
    {
        $node = $this->node(); $user = $this->user(); $this->configure($node); $this->sync();
        $name = $this->projected($node, $user)['host'];
        DB::table('v2_user')->where('id', $user->id)->update(['expired_at' => time() - 1]);
        $this->assertTrue($this->sync());
        $this->assertSame(0, DdnsRecord::where('type', 'CNAME')->count());
        $this->assertCount(1, $this->remote);
        $this->assertSame(1, DdnsAlias::count());
        $user->refresh()->update(['expired_at' => time() + 3600]);
        $this->assertTrue($this->sync());
        $this->assertSame($name, $this->projected($node, $user)['host']);
    }

    public function test_ban_traffic_exhaustion_and_permission_changes_revoke_only_affected_alias(): void
    {
        $node = $this->node(); $a = $this->user(); $b = $this->user(); $this->configure($node); $this->sync();
        $bName = $this->projected($node, $b)['host'];
        foreach ([['banned' => true], ['banned' => false, 'u' => 1024], ['u' => 0, 'group_id' => 2]] as $changes) {
            $a->update($changes); $this->assertTrue($this->sync());
            $this->assertSame(1, DdnsRecord::where('type', 'CNAME')->count());
            $this->assertSame($bName, $this->projected($node, $b)['host']);
            $a->update(['banned' => false, 'u' => 0, 'group_id' => 1]); $this->assertTrue($this->sync());
        }
    }

    public function test_copy_is_off_and_never_reuses_dns_ownership_or_deletes_source_records(): void
    {
        $node = $this->node(); $user = $this->user(); $this->configure($node); $this->sync();
        $name = $this->projected($node, $user)['host'];
        (new ManageController())->copy(Request::create('/', 'POST', ['id' => $node->id]));
        $copy = Server::latest('id')->first();
        $this->assertFalse($copy->enabled); $this->assertFalse($copy->show);
        $this->assertSame($node->server_port, $copy->server_port);
        $this->assertSame($node->machine_id, $copy->machine_id);
        $this->assertFalse(DdnsService::view('node', $copy->id)['enabled']);
        $this->assertSame(1, DdnsBinding::count());
        $copy->update(['host' => '192.0.2.20', 'enabled' => true]);
        $this->configure($copy, 'hk02'); $this->assertTrue($this->sync());
        $this->assertSame(2, DdnsBinding::count());
        $copy->delete(); $this->assertTrue($this->sync());
        $this->assertSame(1, DdnsBinding::count());
        $this->assertSame($name, $this->projected($node, $user)['host']);
        $this->assertCount(2, $this->remote);
    }

    public function test_quota_failure_does_not_issue_unprepared_domain_and_recovers(): void
    {
        $node = $this->node(); $user = $this->user(); $binding = $this->configure($node); $this->quota = 1;
        $this->assertFalse($this->sync());
        $this->assertCount(0, $this->remote);
        $this->assertStringContainsString('额度不足', $binding->fresh()->error);
        try { $this->projected($node, $user); $this->fail('不能下发未准备好的域名'); }
        catch (HttpResponseException $error) { $this->assertSame(503, $error->getResponse()->getStatusCode()); }
        $this->quota = 200; $this->assertTrue($this->sync());
        $this->assertStringEndsWith('.hk01.ddns.example.com', $this->projected($node, $user)['host']);
    }

    public function test_uncertain_batch_result_recovers_ownership_without_duplicate_records(): void
    {
        $node = $this->node(); $user = $this->user(); $this->configure($node); $this->failAfterWrite = true;
        $this->assertFalse($this->sync()); $this->assertCount(2, $this->remote);
        $this->assertTrue($this->sync()); $this->assertCount(2, $this->remote);
        $this->assertSame(2, DdnsRecord::whereNotNull('record_id')->count());
        $this->assertStringEndsWith('.hk01.ddns.example.com', $this->projected($node, $user)['host']);
    }

    public function test_foreign_records_and_wildcards_are_not_taken_over(): void
    {
        $node = $this->node(); $this->user(); $binding = $this->configure($node);
        foreach ([$binding->domain, '*.' . $binding->domain] as $name) {
            $this->remote = ['foreign' => ['id' => 'foreign', 'name' => $name, 'type' => 'A', 'content' => '192.0.2.55', 'ttl' => 300, 'proxied' => false]];
            $this->assertFalse($this->sync());
            $this->assertSame('192.0.2.55', $this->remote['foreign']['content']);
            $this->assertNotNull($binding->fresh()->error);
        }
    }

    public function test_disable_retains_old_names_and_migration_keeps_them_until_explicit_cleanup(): void
    {
        $node = $this->node(); $user = $this->user(); $old = $this->configure($node); $this->sync();
        app(DdnsService::class)->configure('node', $node, ['enabled' => false]);
        $node->update(['host' => '192.0.2.33']); $this->assertTrue($this->sync());
        $this->assertCount(2, $this->remote);
        $this->assertSame('192.0.2.33', $this->projected($node, $user)['host']);
        $new = $this->configure($node, 'hk-new'); $this->assertTrue($this->sync());
        $this->assertTrue($old->fresh()->retired); $this->assertCount(4, $this->remote);
        (new DdnsController())->cleanup(Request::create('/', 'POST', ['id' => $old->id]));
        $this->assertTrue($this->sync()); $this->assertCount(2, $this->remote);
        $this->assertNull($old->fresh()); $this->assertSame($new->id, DdnsBinding::first()->id);
    }

    public function test_relay_uses_front_address_and_retains_alias_while_any_route_is_authorized(): void
    {
        $entry = $this->node('192.0.2.10', ['group_ids' => []]);
        $a = $this->node('192.0.2.20', ['relay_entry_id' => $entry->id]);
        $b = $this->node('192.0.2.21', ['relay_entry_id' => $entry->id]);
        $user = $this->user(); $this->configure($entry); $this->assertTrue($this->sync());
        $host = $this->projected($a, $user)['host'];
        $this->assertSame($host, $this->projected($b, $user)['host']);
        $this->assertSame('192.0.2.10', DdnsRecord::where('type', 'A')->value('content'));
        $a->update(['group_ids' => []]); $this->assertTrue($this->sync());
        $this->assertSame($host, $this->projected($b, $user)['host']);
        $b->update(['group_ids' => []]); $this->assertTrue($this->sync());
        $this->assertSame(0, DdnsRecord::where('type', 'CNAME')->count());
    }

    public function test_pool_alias_uses_manual_gateway_address_without_changing_members(): void
    {
        $template = $this->node();
        $pool = BalancerPool::create(['name' => 'DDNS 测试池', 'domain' => 'original.example.com', 'gateway_machine_id' => 1,
            'gateway_address' => '192.0.2.90', 'gateway_port' => 8443, 'backend_port' => 20000, 'protocol' => 'vless',
            'template_node_id' => $template->id, 'template_config' => $template->toArray(), 'enabled' => true]);
        $route = $this->node('192.0.2.70', ['relay_pool_id' => $pool->id]);
        $user = $this->user(); $this->configure($pool, 'pool01'); $this->assertTrue($this->sync());
        $host = $this->projected($route, $user)['host'];
        $this->assertStringEndsWith('.pool01.ddns.example.com', $host);
        $this->assertSame('192.0.2.90', DdnsRecord::where('type', 'A')->value('content'));
        $pool->update(['gateway_address' => '2001:db8::90']); $this->assertTrue($this->sync());
        $this->assertSame('2001:db8::90', DdnsRecord::where('type', 'AAAA')->value('content'));
        $this->assertSame($host, $this->projected($route, $user)['host']);
        $this->assertSame('192.0.2.70', $route->fresh()->host);
    }

    public function test_prefix_uniqueness_and_tls_validation_do_not_affect_existing_binding(): void
    {
        $node = $this->node(); $binding = $this->configure($node);
        $copy = $this->node();
        try { $this->configure($copy); $this->fail('重复前缀应拒绝'); }
        catch (ValidationException $error) { $this->assertArrayHasKey('ddns.prefix', $error->errors()); }
        $this->assertSame(1, DdnsBinding::count());
        $copy->update(['protocol_settings' => ['tls' => 1]]);
        try { $this->configure($copy, 'tls'); $this->fail('必须有明确证书域名'); }
        catch (ValidationException $error) { $this->assertArrayHasKey('ddns.enabled', $error->errors()); }
        $this->assertSame($binding->id, DdnsBinding::first()->id);
    }

    public function test_node_form_saves_binding_separately_and_rejects_duplicate_prefix_atomically(): void
    {
        $node = $this->node(); $binding = $this->configure($node);
        $body = $node->only(['id', 'name', 'type', 'host', 'port', 'server_port', 'rate', 'group_ids', 'protocol_settings']);
        $body['host'] = '192.0.2.88';
        $body['ddns'] = ['enabled' => true, 'preset_id' => $binding->preset_id, 'prefix' => 'hk01'];
        $this->postJson('/_tests/ddns/node/save', $body)->assertOk()->assertJsonPath('data', true);
        $this->assertSame('192.0.2.88', $node->fresh()->host);
        $other = $this->node(); $body['id'] = $other->id; $body['host'] = '192.0.2.77';
        $this->postJson('/_tests/ddns/node/save', $body)->assertStatus(422);
        $this->assertSame('192.0.2.10', $other->fresh()->host);
        $this->assertSame(1, DdnsBinding::count());
    }

    public function test_all_subscription_formats_use_same_personal_domain_without_changing_stored_ip(): void
    {
        // 使用这些客户端均支持的 SS，避免把 Surge 本身不支持 VLESS 误判为 DDNS 失败。
        $node = $this->node('192.0.2.10', ['type' => 'shadowsocks', 'protocol_settings' => ['cipher' => 'aes-128-gcm']]);
        $user = $this->user(); $this->configure($node); $this->sync();
        $name = $this->projected($node, $user)['host'];
        foreach (['clashmeta', 'sing-box', 'surge', 'shadowrocket'] as $format) {
            $response = app(\App\Http\Controllers\V1\Client\ClientController::class)->doSubscribe(
                Request::create('/', 'GET', ['flag' => $format]), $user);
            $text = $response->getContent();
            $this->assertTrue(str_contains($text, $name) || str_contains((string) base64_decode($text, true), $name), $format);
        }
        $this->assertSame('192.0.2.10', $node->fresh()->host);
    }

    public function test_empty_explicit_permission_list_does_not_fall_back_to_old_primary_group(): void
    {
        $node = $this->node(); $this->user(['group_ids' => []]); $this->configure($node);
        $this->assertTrue($this->sync());
        $this->assertSame(0, DdnsAlias::count());
        $this->assertCount(1, $this->remote);
    }

    public function test_reediting_ready_binding_keeps_working_domain_and_latest_ip_wins(): void
    {
        $node = $this->node(); $user = $this->user(); $this->configure($node); $this->sync();
        $name = $this->projected($node, $user)['host'];
        $node->update(['host' => '192.0.2.31']); $this->configure($node);
        $this->assertSame($name, $this->projected($node, $user)['host']);
        $node->update(['host' => '192.0.2.32']); $this->configure($node);
        $this->assertTrue($this->sync());
        $this->assertSame('192.0.2.32', DdnsRecord::where('type', 'A')->value('content'));
        $this->assertSame($name, $this->projected($node, $user)['host']);
    }

    public function test_external_errors_and_rate_limits_never_expose_credentials(): void
    {
        $node = $this->node(); $this->user(); $binding = $this->configure($node);
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::preventStrayRequests();
        Http::fake(fn () => Http::response(['success' => false, 'errors' => [['message' => $binding->preset->api_token]]], 429, ['Retry-After' => '30']));
        $this->assertFalse($this->sync());
        $this->assertStringContainsString('限流', $binding->fresh()->error);
        $this->assertStringNotContainsString($binding->preset->api_token, $binding->fresh()->error);
        $count = count(Http::recorded());
        $this->assertFalse($this->sync());
        $this->assertSame($count, count(Http::recorded()));
    }

    public function test_pool_ddns_requires_explicit_ip_even_when_called_without_controller(): void
    {
        try {
            app(\App\Services\LoadBalancerService::class)->save(['name' => '测试池', 'ddns' => ['enabled' => true]]);
            $this->fail('不能自动改用机器上报地址');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('gateway_address', $error->errors());
        }
    }

    public function test_machine_reported_ip_does_not_change_manual_node_dns(): void
    {
        $machine = \App\Models\ServerMachine::create(['name' => '隔离测试服务器', 'token' => Str::random(32), 'is_active' => true,
            'agent_runtime' => ['public_ipv4' => '192.0.2.200']]);
        $node = $this->node('192.0.2.10', ['machine_id' => $machine->id]); $this->user(); $this->configure($node); $this->sync();
        $machine->update(['agent_runtime' => ['public_ipv4' => '192.0.2.201']]);
        $this->assertTrue($this->sync());
        $this->assertSame('192.0.2.10', DdnsRecord::where('type', 'A')->value('content'));
    }

    public function test_failed_preset_is_requested_once_per_round(): void
    {
        $first = $this->node(); $second = $this->node(); $this->user();
        $this->configure($first, 'first'); $this->configure($second, 'second');
        Http::swap(new \Illuminate\Http\Client\Factory()); Http::preventStrayRequests();
        Http::fake(fn () => Http::response(['success' => false], 503));
        $this->assertFalse($this->sync());
        Http::assertSentCount(1);
        $this->assertSame(2, DdnsBinding::whereNotNull('error')->count());
    }

    public function test_switching_to_relay_does_not_publish_internal_landing_ip(): void
    {
        $node = $this->node(); $this->user(); $binding = $this->configure($node); $this->sync();
        $entry = $this->node('192.0.2.50');
        $node->update(['host' => '10.0.0.20', 'relay_entry_id' => $entry->id]);
        app(DdnsService::class)->configure('node', $node, ['enabled' => false]);
        $this->assertTrue($this->sync());
        $this->assertCount(0, $this->remote);
        $this->assertSame(0, app(DdnsService::class)->plannedRecords($binding->fresh()));
    }

    public function test_preset_credentials_are_encrypted_hidden_and_references_prevent_deletion(): void
    {
        $node = $this->node(); $binding = $this->configure($node); $preset = $binding->preset;
        $this->assertNotSame($preset->api_token, DB::table('v2_balancer_dns_preset')->value('api_token'));
        $json = (new DdnsController())->fetch()->getData(true);
        $this->assertArrayNotHasKey('api_token', $json['data']['presets'][0]);
        $this->assertSame(['quota' => 3500, 'usage' => 0], app(CloudflareDnsClient::class)->usage($preset));
        $this->expectException(ValidationException::class);
        (new DdnsController())->deletePreset(Request::create('/', 'POST', ['id' => $preset->id]));
    }

    public function test_tls_object_protocols_save_ddns_and_keep_certificate_name_in_subscription(): void
    {
        foreach (['hysteria', 'tuic', 'anytls'] as $type) {
            $node = $this->node('192.0.2.10', ['type' => $type, 'protocol_settings' => [
                'version' => $type === 'hysteria' ? 2 : 5,
                'tls' => ['server_name' => 'certificate.example.com', 'allow_insecure' => false],
            ]]);
            $user = $this->user();
            $binding = $this->configure($node, $type);
            $body = $node->only(['id', 'name', 'type', 'host', 'port', 'server_port', 'rate', 'group_ids', 'protocol_settings']);
            $body['ddns'] = ['enabled' => true, 'preset_id' => $binding->preset_id, 'prefix' => $type];
            $this->postJson('/_tests/ddns/node/save', $body)->assertOk();
            $this->assertTrue($this->sync());
            $server = $this->projected($node, $user);
            $response = app(\App\Http\Controllers\V1\Client\ClientController::class)->doSubscribe(
                Request::create('/', 'GET', ['flag' => 'sing-box']), $user);
            $outbound = collect(json_decode($response->getContent(), true)['outbounds'])->firstWhere('server', $server['host']);
            $this->assertSame('certificate.example.com', $outbound['tls']['server_name']);
            $this->assertFalse($outbound['tls']['insecure']);
            $body['protocol_settings']['tls']['server_name'] = '';
            $this->postJson('/_tests/ddns/node/save', $body)->assertStatus(422);
        }
    }

    public function test_quota_failure_does_not_block_existing_ip_update(): void
    {
        $node = $this->node(); $user = $this->user(); $binding = $this->configure($node);
        $this->assertTrue($this->sync());
        $name = $this->projected($node, $user)['host'];
        $this->quota = 2;
        $newUser = $this->user();
        $node->update(['host' => '192.0.2.99']);
        $this->assertFalse($this->sync());
        $this->assertSame('192.0.2.99', collect($this->remote)->firstWhere('name', $binding->domain)['content']);
        $this->assertSame($name, $this->projected($node, $user)['host']);
        try { $this->projected($node, $newUser); $this->fail('新增用户必须等待解析准备完成'); }
        catch (HttpResponseException $error) { $this->assertSame(503, $error->getResponse()->getStatusCode()); }
        $this->quota = 3;
        $this->assertTrue($this->sync());
        $this->assertStringEndsWith('.' . $binding->domain, $this->projected($node, $newUser)['host']);
    }

    public function test_missing_alias_or_base_is_not_issued_when_repair_fails(): void
    {
        $node = $this->node(); $user = $this->user(); $binding = $this->configure($node);
        $this->assertTrue($this->sync());
        foreach (['CNAME', 'A'] as $type) {
            $record = DdnsRecord::where('type', $type)->firstOrFail();
            unset($this->remote[$record->record_id]);
            $this->quota = 1;
            $binding->fresh()->update(['fingerprint' => null]);
            $this->assertFalse($this->sync());
            $this->assertNull($record->fresh()->record_id);
            try { $this->projected($node, $user); $this->fail('不能下发缺少个人或基础解析的域名'); }
            catch (HttpResponseException $error) { $this->assertSame(503, $error->getResponse()->getStatusCode()); }
            $this->quota = 2;
            $this->assertTrue($this->sync());
            $this->assertStringEndsWith('.' . $binding->domain, $this->projected($node, $user)['host']);
        }
    }

    public function test_retained_names_revoke_ineligible_user_even_after_address_becomes_domain(): void
    {
        $node = $this->node(); $revoked = $this->user(); $active = $this->user(); $binding = $this->configure($node);
        $this->assertTrue($this->sync());
        $oldName = $this->projected($node, $revoked)['host'];
        $activeName = $this->projected($node, $active)['host'];
        app(DdnsService::class)->configure('node', $node, ['enabled' => false]);
        $body = $node->only(['id', 'name', 'type', 'host', 'port', 'server_port', 'rate', 'group_ids', 'protocol_settings']);
        $body['host'] = 'new-entry.example.com';
        $this->postJson('/_tests/ddns/node/save', $body)->assertOk();
        $revoked->update(['banned' => true]);
        $this->assertFalse($this->sync());
        $this->assertFalse(collect($this->remote)->contains('name', $oldName));
        $this->assertTrue(collect($this->remote)->contains('name', $activeName));
        $this->assertSame(2, DdnsAlias::count());
        $node->refresh()->update(['host' => '192.0.2.90']);
        $revoked->update(['banned' => false]);
        $this->assertTrue($this->sync());
        $this->assertTrue(collect($this->remote)->contains('name', $oldName));
        (new DdnsController())->cleanup(Request::create('/', 'POST', ['id' => $binding->id]));
        $this->assertTrue($this->sync());
        $this->assertCount(0, $this->remote);
    }

    public function test_batch_deadline_keeps_progress_and_gives_next_binding_a_turn(): void
    {
        $node = $this->node(); $next = $this->node('192.0.2.20', ['group_ids' => []]);
        for ($i = 0; $i < 120; $i++) $this->user();
        $first = $this->configure($node, 'first'); $second = $this->configure($next, 'second');
        $client = new class extends CloudflareDnsClient {
            public bool $interrupt = true;
            public function request(BalancerDnsPreset $preset, string $method, string $path, array $data = []): array
            {
                $result = parent::request($preset, $method, $path, $data);
                if ($this->interrupt && $path === '/dns_records/batch') {
                    $this->interrupt = false;
                    $this->setDeadline(microtime(true) - 1);
                }
                return $result;
            }
        };
        $service = new DdnsService($client);
        $this->assertFalse($service->synchronize(20));
        $this->assertCount(100, $this->remote);
        $this->assertNull($second->fresh()->synced_at);
        $this->assertSame($first->id, \Illuminate\Support\Facades\Cache::get('ddns:sync:cursor'));
        $offset = count(Http::recorded());
        $this->assertTrue($service->synchronize(20));
        $writes = Http::recorded()->slice($offset)->filter(fn ($pair) => $pair[0]->method() === 'POST')->values();
        $this->assertSame($second->domain, $writes[0][0]['posts'][0]['name']);
        $this->assertCount(122, $this->remote);
        $this->assertSame(122, DdnsRecord::whereNotNull('record_id')->count());
        $this->assertNotNull($first->fresh()->synced_at);
    }

    public function test_remote_pagination_stops_at_deadline_without_caching_partial_snapshot(): void
    {
        $this->configure($this->node()); $preset = BalancerDnsPreset::firstOrFail();
        Http::swap(new \Illuminate\Http\Client\Factory()); Http::preventStrayRequests();
        Http::fake(fn () => Http::response(['success' => true, 'result' => [], 'result_info' => ['total_pages' => 2]]));
        $client = new class extends CloudflareDnsClient {
            public bool $interrupt = true;
            public function request(BalancerDnsPreset $preset, string $method, string $path, array $data = []): array
            {
                $result = parent::request($preset, $method, $path, $data);
                if ($this->interrupt) { $this->interrupt = false; $this->setDeadline(microtime(true) - 1); }
                return $result;
            }
        };
        try { $client->records($preset); $this->fail('时间耗尽后不能继续读取下一页'); }
        catch (\App\Exceptions\DdnsSyncDeferred) { Http::assertSentCount(1); }
        $client->setDeadline(null);
        $this->assertSame([], $client->records($preset));
        Http::assertSentCount(3);
    }

    public function test_unchanged_sync_does_not_query_aliases_per_user(): void
    {
        $node = $this->node();
        for ($i = 0; $i < 50; $i++) $this->user();
        $this->configure($node); $this->assertTrue($this->sync());
        DB::enableQueryLog(); DB::flushQueryLog();
        try {
            $this->assertTrue($this->sync());
            $aliasQueries = collect(DB::getQueryLog())->filter(fn ($query) => str_contains($query['query'], 'v2_ddns_alias'))->count();
            $this->assertLessThanOrEqual(2, $aliasQueries);
        } finally { DB::disableQueryLog(); DB::flushQueryLog(); }
    }

    public function test_http_timeouts_are_limited_by_remaining_sync_budget(): void
    {
        $this->configure($this->node()); $preset = BalancerDnsPreset::firstOrFail();
        $observed = false;
        Http::swap(new \Illuminate\Http\Client\Factory()); Http::preventStrayRequests();
        Http::fake(function ($request, $options) use (&$observed) {
            $observed = true;
            $this->assertGreaterThan(0, $options['timeout']);
            $this->assertLessThanOrEqual(1, $options['timeout']);
            $this->assertLessThanOrEqual($options['timeout'], $options['connect_timeout']);
            return Http::response(['success' => true, 'result' => ['record_quota' => 3500, 'record_usage' => 0]]);
        });
        $client = app(CloudflareDnsClient::class);
        $client->setDeadline(microtime(true) + 1);
        $client->usage($preset);
        $this->assertTrue($observed);
    }

    public function test_ownership_change_is_not_published_or_deleted(): void
    {
        $node = $this->node(); $user = $this->user(); $binding = $this->configure($node);
        $this->assertTrue($this->sync());
        $record = DdnsRecord::where('type', 'CNAME')->firstOrFail();
        $id = $record->record_id;
        $this->remote[$id]['comment'] = 'external owner';
        $binding->fresh()->update(['fingerprint' => null]);
        $this->assertFalse($this->sync());
        $this->assertSame($id, $record->fresh()->record_id);
        $this->assertNull($binding->fresh()->synced_at);
        try { $this->projected($node, $user); $this->fail('归属变更后不能继续下发'); }
        catch (HttpResponseException $error) { $this->assertSame(503, $error->getResponse()->getStatusCode()); }
        app(DdnsService::class)->configure('node', $node, ['enabled' => false]);
        (new DdnsController())->cleanup(Request::create('/', 'POST', ['id' => $binding->id]));
        $this->assertFalse($this->sync());
        $this->assertArrayHasKey($id, $this->remote);
        $this->assertNotNull($binding->fresh());
    }
}
