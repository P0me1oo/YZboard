<?php

namespace Tests\Feature\Server;

use App\Http\Controllers\V2\Admin\Server\LoadBalancerController;
use App\Jobs\ProcessNodeReportBatch;
use App\Models\BalancerDnsPreset;
use App\Models\BalancerPool;
use App\Models\NodeReportBatch;
use App\Models\PoolDnsAlias;
use App\Models\Server;
use App\Models\ServerGroup;
use App\Models\ServerMachine;
use App\Models\User;
use App\Services\BalancerDnsService;
use App\Services\DdnsService;
use App\Services\DirectPoolService;
use App\Services\FrontPortProbe;
use App\Services\LoadBalancerService;
use App\Services\NodeReportService;
use App\Services\NodeSyncService;
use App\Services\ServerRelayService;
use App\Services\ServerService;
use App\Services\UserRouteTraffic;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class DirectPoolTest extends TestCase
{
    use RefreshDatabase;

    private array $remote = [];
    private bool $dnsFailed = false;
    private array $payload;
    private Server $a;
    private Server $b;
    private User $user;
    private int $originalGroup;
    private int $poolGroup;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Redis::shouldReceive('publish')->andReturn(0);
        Redis::shouldReceive('sadd')->andReturn(1);
        \Illuminate\Support\Sleep::fake();
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            if ($this->dnsFailed) return Http::response([], 503);
            if ($request->method() === 'GET') return Http::response(['name' => 'example.test.', 'rrsets' => array_values($this->remote)]);
            foreach ($request['rrsets'] as $record) {
                $key = $record['name'] . '|' . $record['type'];
                if ($record['changetype'] === 'DELETE') unset($this->remote[$key]);
                else $this->remote[$key] = $record;
            }
            return Http::response('', 204);
        });
        Route::post('/_tests/direct-pool/save', [LoadBalancerController::class, 'save']);
        $group = new ServerGroup(); $group->name = '原权限组'; $group->save(); $this->originalGroup = $group->id;
        $group = new ServerGroup(); $group->name = '池权限组'; $group->save(); $this->poolGroup = $group->id;
        $key = random_bytes(32);
        $encode = fn ($value) => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
        $settings = ['network' => 'tcp', 'tls' => 2, 'flow' => 'xtls-rprx-vision', 'reality_settings' => [
            'private_key' => $encode($key), 'public_key' => $encode(sodium_crypto_scalarmult_base($key)),
            'short_id' => bin2hex(random_bytes(8)), 'server_name' => 'handshake.example.test', 'server_port' => 443]];
        foreach (['a' => '192.0.2.10', 'b' => '192.0.2.11'] as $property => $ip) {
            $machine = ServerMachine::create(['name' => $property, 'token' => Str::random(32), 'is_active' => true,
                'agent_runtime' => ['version' => 'v2.25.0']]);
            $this->{$property} = Server::create(['name' => $property, 'type' => 'vless', 'host' => $ip, 'machine_id' => $machine->id,
                'kernel_type' => $property === 'a' ? 'xray' : 'sing-box', 'enabled' => true, 'show' => true,
                'port' => '30443', 'server_port' => $property === 'a' ? 443 : 8443, 'group_ids' => [$this->originalGroup],
                'rate' => 3, 'protocol_settings' => $settings]);
            Cache::put(CacheKey::get('SERVER_VLESS_LAST_CHECK_AT', $this->{$property}->id), time(), 3600);
        }
        $preset = BalancerDnsPreset::create(['name' => '测试 DNS', 'provider' => 'powerdns', 'domain' => 'example.test',
            'zone_id' => md5('example.test'), 'api_url' => 'https://dns-api.example.test', 'server_id' => 'localhost', 'api_token' => Str::random(40)]);
        $this->payload = ['mode' => 'direct', 'name' => '美国直出', 'domain' => 'direct.example.test', 'dns_preset_id' => $preset->id,
            'enabled' => true, 'reality_sync_enabled' => false, 'direct_settings' => ['group_ids' => [$this->poolGroup], 'rate' => 2, 'show' => true, 'sort' => 9],
            'members' => [['node_id' => $this->a->id, 'priority' => 1, 'weight' => 1], ['node_id' => $this->b->id, 'priority' => 1, 'weight' => 1]]];
        $this->user = User::create(['email' => 'direct-test@example.invalid', 'password' => Str::random(32), 'uuid' => Str::uuid(),
            'token' => Str::random(32), 'group_id' => $this->poolGroup, 'transfer_enable' => 100000, 'expired_at' => time() + 3600]);
    }

    private function pool(array $extra = []): BalancerPool
    {
        $response = $this->postJson('/_tests/direct-pool/save', array_replace($this->payload, $extra))->assertOk();
        $this->a->refresh(); $this->b->refresh();
        return BalancerPool::findOrFail($response->json('data.id'));
    }

    private function sync(BalancerPool $pool): void
    {
        $this->assertTrue(app(BalancerDnsService::class)->sync($pool->fresh()));
    }

    private function landing(Server $entry, array $extra = []): Server
    {
        return Server::create(array_replace(['name' => '落地 ' . $entry->name, 'type' => 'shadowsocks',
            'host' => '203.0.113.10', 'port' => '10080', 'server_port' => 10080, 'kernel_type' => 'xray',
            'relay_entry_id' => $entry->id, 'enabled' => true, 'show' => true,
            'group_ids' => [$this->originalGroup], 'rate' => 7,
            'protocol_settings' => ['cipher' => 'aes-128-gcm']], $extra));
    }

    private function relayUser(): User
    {
        $user = $this->user->replicate();
        $user->fill(['email' => 'relay-test-' . Str::random(8) . '@example.invalid',
            'uuid' => Str::uuid(), 'token' => Str::random(32), 'group_id' => $this->originalGroup]);
        $user->save();
        return $user;
    }

    public function test_mixed_members_keep_existing_landings_and_share_one_direct_route(): void
    {
        $landings = [$this->landing($this->a), $this->landing($this->b)];
        $pool = $this->pool();
        $this->sync($pool);
        $direct = DirectPoolService::node($pool);
        $route = ServerRelayService::ensureRouteId($direct);
        $relayUser = $this->relayUser();
        foreach ([$this->a, $this->b] as $index => $node) {
            $child = $landings[$index];
            $this->assertTrue(ServerRelayService::isSupportedEntry($node));
            $this->assertNull(ServerRelayService::validateEntry($child->id, $node->id, $child->type,
                $child->protocol_settings, $child->host, $child->kernel_type));
            $config = ServerService::buildNodeConfig($node);
            $this->assertSame($route, $config['relay']['route_id']);
            $this->assertSame([$child->id], array_column($config['relay']['children'], 'node_id'));
            $this->assertEquals(3, $child->getEffectiveRate());
        }
        $directNodes = ServerService::getAvailableServers($this->user);
        $this->assertCount(1, $directNodes);
        $this->assertSame(Helper::applyVlessRoute($this->user->uuid, $route), $directNodes[0]['password']);
        $relayNodes = collect(ServerService::getAvailableServers($relayUser))->keyBy('id');
        $this->assertCount(2, $relayNodes);
        foreach ($landings as $index => $child) {
            $this->assertSame(($index === 0 ? $this->a : $this->b)->host, $relayNodes[$child->id]['host']);
            $this->assertSame(Helper::applyVlessRoute($relayUser->uuid, ServerRelayService::ensureRouteId($child)), $relayNodes[$child->id]['password']);
            $this->assertEquals(3, $relayNodes[$child->id]['rate']);
        }
    }

    public function test_mixed_members_authorize_direct_and_transit_independently(): void
    {
        $pool = $this->pool();
        foreach ([$this->a, $this->b] as $node) {
            $child = $this->landing($node);
            $relayUser = $this->relayUser();
            $both = $this->relayUser();
            $both->update(['group_ids' => [$this->originalGroup, $this->poolGroup]]);
            $users = ServerService::getUsersForSync($node)->keyBy('id');
            $directRoute = ServerRelayService::ensureRouteId(DirectPoolService::node($pool));
            $childRoute = ServerRelayService::ensureRouteId($child);
            $this->assertSame([$directRoute], $users[$this->user->id]->relay_routes);
            $this->assertSame([$childRoute], $users[$relayUser->id]->relay_routes);
            $expected = [$directRoute, $childRoute]; sort($expected);
            $this->assertSame($expected, $users[$both->id]->relay_routes);
            $this->assertSame([], LoadBalancerService::memberCompatibility($pool, $node));
        }
    }

    public function test_mixed_permission_changes_revoke_one_route_and_preserve_the_other(): void
    {
        $pool = $this->pool();
        $child = $this->landing($this->a);
        $directRoute = ServerRelayService::ensureRouteId(DirectPoolService::node($pool));
        $childRoute = ServerRelayService::ensureRouteId($child);
        $this->user->update(['group_ids' => [$this->poolGroup, $this->originalGroup]]);
        Cache::put('node_ws_alive:' . $this->a->id, true);
        $sent = [];
        $redis = \Mockery::mock();
        Redis::swap($redis);
        $redis->shouldReceive('publish')->andReturnUsing(function ($channel, $json) use (&$sent) {
            $sent[] = json_decode($json, true);
            return 0;
        });
        foreach ([[$this->originalGroup, $this->poolGroup, $childRoute],
            [$this->poolGroup, $this->originalGroup, $directRoute]] as [$keptGroup, $removedGroup, $keptRoute]) {
            $this->user->update(['group_id' => $keptGroup, 'group_ids' => [$keptGroup]]);
            $sent = [];
            NodeSyncService::notifyUserRemovedFromGroups($this->user->id, [$removedGroup]);
            $sync = collect($sent)->firstWhere('event', 'sync.users');
            $this->assertSame($this->a->id, $sync['node_id']);
            $this->assertSame($this->user->id, $sync['data']['users'][0]['id']);
            $this->assertSame([$keptRoute], $sync['data']['users'][0]['relay_routes']);
            $this->assertFalse(collect($sent)->contains('event', 'sync.user.delta'));
        }
    }

    public function test_disabling_direct_pool_keeps_transit_users_and_runtime_counts(): void
    {
        $pool = $this->pool();
        $child = $this->landing($this->a);
        $relayUser = $this->relayUser();
        $this->sync($pool);
        $this->postJson('/_tests/direct-pool/save', [...$this->payload, 'id' => $pool->id, 'enabled' => false])->assertOk();
        $this->sync($pool);
        $users = ServerService::getAvailableUsers($this->a->fresh());
        $this->assertSame([$relayUser->id], $users->pluck('id')->all());
        $this->assertSame([ServerRelayService::ensureRouteId($child)], $users->first()->relay_routes);
        $this->assertCount(0, ServerService::getAvailableUsers($this->b->fresh()));
        $this->assertCount(0, ServerService::getAvailableServers($this->user));
        $this->assertSame([$child->id], array_column(ServerService::getAvailableServers($relayUser), 'id'));
        $this->assertSame([$child->id], array_column(ServerService::buildNodeConfig($this->a->fresh())['relay']['children'], 'node_id'));
        $nodes = DirectPoolService::runtimeNodes(Server::whereNull('relay_entry_id')->get(['id', 'group_ids', 'enabled']));
        $this->assertTrue($nodes->firstWhere('id', $this->a->id)->enabled);
        $this->assertSame([$this->originalGroup], $nodes->firstWhere('id', $this->a->id)->group_ids);
        $this->assertFalse($nodes->firstWhere('id', $this->b->id)->enabled);
        $this->assertSame([], $this->remote);
    }

    public function test_disabled_or_removed_landing_keeps_direct_route_and_rejects_transit(): void
    {
        $pool = $this->pool();
        $child = $this->landing($this->b);
        $relayUser = $this->relayUser();
        $route = ServerRelayService::ensureRouteId($child);
        $child->update(['enabled' => false]);
        $config = ServerService::buildNodeConfig($this->b->fresh());
        $this->assertContains($route, $config['relay']['blocked_route_ids']);
        $this->assertSame([], $config['relay']['children']);
        $this->assertSame([$this->user->id], ServerService::getAvailableUserIds($this->b->fresh()));
        $this->assertCount(0, ServerService::getAvailableServers($relayUser));
        $child->delete();
        $directRoute = ServerRelayService::ensureRouteId(DirectPoolService::node($pool));
        foreach ([$this->a, $this->b] as $node) {
            $this->assertSame($directRoute, ServerService::buildNodeConfig($node->fresh())['relay']['route_id']);
            $this->assertSame([$directRoute], ServerService::getAvailableUsers($node->fresh())->first()->relay_routes);
        }
    }

    public function test_mixed_billing_preserves_transit_rates_and_freezes_direct_attribution(): void
    {
        $pool = $this->pool();
        $follow = $this->landing($this->a);
        $override = $this->landing($this->a, ['rate' => 5, 'relay_rate_override' => true]);
        $this->user->update(['group_ids' => [$this->poolGroup, $this->originalGroup]]);
        DB::table('v2_settings')->where('name', 'user_route_statistics_started_at')->update(['value' => (string) (time() - 60)]);
        ServerService::getUsersForSync($this->a);
        $traffic = [$this->user->id => [1000, 2000]];
        $relay = [$follow->id => [300, 600], $override->id => [200, 400]];
        $relayUsers = [$this->user->id => $relay];
        $batch = app(NodeReportService::class)->accept($this->a, 'mixed-report', $traffic, $relay, $relayUsers);
        $this->assertEquals([$follow->id => 3, $override->id => 5], $batch->server_snapshot['relay_rates']);
        $this->postJson('/_tests/direct-pool/save', [...$this->payload, 'id' => $pool->id,
            'direct_settings' => [...$this->payload['direct_settings'], 'rate' => 9]])->assertOk();
        $follow->update(['relay_rate_override' => true, 'rate' => 8]);
        $job = new ProcessNodeReportBatch($batch->id); $job->handle(); $job->handle();
        $this->assertSame($batch->id, app(NodeReportService::class)->accept($this->a->fresh(), 'mixed-report', $traffic, $relay, $relayUsers)->id);
        $this->assertSame(2900, (int) $this->user->fresh()->u);
        $this->assertSame(5800, (int) $this->user->fresh()->d);
        $rows = DB::table(UserRouteTraffic::TABLE)->where('user_id', $this->user->id)->get()->keyBy('server_id');
        $this->assertCount(3, $rows);
        $this->assertSame(500, (int) $rows[$pool->gateway_node_id]->u);
        $this->assertSame(1000, (int) $rows[$pool->gateway_node_id]->billed_u);
        $this->assertSame(300, (int) $rows[$follow->id]->u);
        $this->assertSame(900, (int) $rows[$follow->id]->billed_u);
        $this->assertSame(1000, (int) $rows[$override->id]->billed_u);
        $this->assertSame(500, (int) $this->a->fresh()->usage_u);
        $this->assertSame(300, (int) $follow->fresh()->usage_u);
    }

    public function test_deleting_mixed_pool_keeps_landings_and_restores_original_direct_permissions(): void
    {
        $pool = $this->pool();
        $child = $this->landing($this->a);
        $relayUser = $this->relayUser();
        $childRoute = ServerRelayService::ensureRouteId($child);
        (new LoadBalancerController())->delete(Request::create('/', 'POST', ['id' => $pool->id]));
        $this->a->refresh();
        $this->assertNull($this->a->balancer_pool_id);
        $this->assertSame($this->a->id, $child->fresh()->relay_entry_id);
        $routes = ServerService::getAvailableUsers($this->a)->keyBy('id')[$relayUser->id]->relay_routes;
        $this->assertContains($childRoute, $routes);
        $this->assertContains(ServerRelayService::ensureRouteId($this->a), $routes);
        $this->assertSame($childRoute, ServerService::buildNodeConfig($this->a)['relay']['children'][0]['route_id']);
        $this->assertCount(0, ServerService::getAvailableServers($this->user));
    }

    public function test_pool_creates_one_subscription_and_reuses_both_original_listeners(): void
    {
        $before = [$this->a->protocol_settings, $this->b->protocol_settings];
        $pool = $this->pool();
        $this->sync($pool);
        $nodes = app(DdnsService::class)->project(ServerService::getAvailableServers($this->user), $this->user);
        $this->assertCount(1, $nodes);
        $this->assertSame($pool->gateway_node_id, $nodes[0]['id']);
        $this->assertSame('美国直出', $nodes[0]['name']);
        $directRoute = ServerRelayService::ensureRouteId(DirectPoolService::node($pool));
        $this->assertSame(Helper::applyVlessRoute($this->user->uuid, $directRoute), $nodes[0]['password']);
        $this->assertSame(30443, $nodes[0]['port']);
        $this->assertEquals(2, $nodes[0]['rate']);
        $this->assertSame(1, $nodes[0]['is_online']);
        $this->assertNotNull($nodes[0]['last_check_at']);
        $this->assertNull($nodes[0]['protocol_settings']['reality_settings']['private_key'] ?? null);
        $this->assertStringEndsWith('.direct.example.test', $nodes[0]['host']);
        $alias = PoolDnsAlias::firstOrFail();
        $this->assertSame($alias->label . '.direct.example.test', $nodes[0]['host']);
        $this->assertNull(LoadBalancerService::entryForPool($pool->id));
        $this->assertNull(DirectPoolService::node($pool)->machine_id);
        $this->assertCount(2, ServerService::getAllServers());
        foreach ([$this->a, $this->b] as $index => $node) {
            $config = ServerService::buildNodeConfig($node);
            $this->assertSame($index === 0 ? 443 : 8443, $config['server_port']);
            $this->assertSame('entry', $config['relay']['mode']);
            $this->assertSame($directRoute, $config['relay']['route_id']);
            $this->assertSame([], $config['relay']['children']);
            $this->assertSame($before[$index], $node->protocol_settings);
            $this->assertSame([$this->originalGroup], $node->group_ids);
            $this->assertEquals(3, $node->rate);
            $this->assertCount(1, ServerService::getMachineNodes($node->machine));
        }
    }

    public function test_pool_permissions_and_multiplier_apply_to_each_member(): void
    {
        $pool = $this->pool();
        foreach ([$this->a, $this->b] as $node) {
            $this->assertSame([$this->user->id], ServerService::getAvailableUserIds($node));
            $this->assertEquals(2, $node->getEffectiveRate());
        }
        $this->user->update(['group_id' => $this->originalGroup]);
        foreach ([$this->a, $this->b] as $node) $this->assertCount(0, ServerService::getAvailableUsers($node));
        $this->assertCount(0, ServerService::getAvailableServers($this->user));
        $this->assertCount(0, ServerService::getAvailableUsers(DirectPoolService::node($pool)));
    }

    public function test_health_check_without_landing_uses_port_results_and_base_agent(): void
    {
        $this->app->instance(FrontPortProbe::class, new class extends FrontPortProbe {
            public function check(array $addresses, int $port): array
            {
                return array_fill_keys($addresses, ['ok' => true, 'rtt_ms' => 12]);
            }
        });
        $pool = $this->pool(['scheduling_config' => ['health_enabled' => true]]);
        $this->sync($pool);
        $this->assertCount(1, app(DdnsService::class)->project(ServerService::getAvailableServers($this->user), $this->user));
        $this->assertSame([], $pool->members()->first()->scheduling_state['links']);
    }

    public function test_offline_member_moves_user_and_all_offline_removes_dns(): void
    {
        $pool = $this->pool(); $this->sync($pool);
        $selected = PoolDnsAlias::firstOrFail()->member_id;
        $member = $pool->members()->findOrFail($selected);
        Cache::forget(CacheKey::get('SERVER_VLESS_LAST_CHECK_AT', $member->source_node_id));
        $this->sync($pool);
        $this->assertNotSame($selected, PoolDnsAlias::firstOrFail()->member_id);
        foreach ([$this->a, $this->b] as $node) Cache::forget(CacheKey::get('SERVER_VLESS_LAST_CHECK_AT', $node->id));
        $this->sync($pool);
        $this->assertNull(PoolDnsAlias::firstOrFail()->member_id);
        $this->assertSame([], $this->remote);
    }

    public function test_dns_failure_keeps_last_confirmed_assignment(): void
    {
        $pool = $this->pool(); $this->sync($pool);
        $before = PoolDnsAlias::firstOrFail()->toArray();
        $this->dnsFailed = true;
        $this->assertFalse(app(BalancerDnsService::class)->sync($pool));
        $this->assertSame($before, PoolDnsAlias::firstOrFail()->toArray());
        $this->dnsFailed = false; $this->sync($pool);
        $this->assertNull($pool->fresh()->dns_error);
    }

    public function test_disabled_pool_rejects_new_users_and_does_not_expose_member_subscriptions(): void
    {
        $pool = $this->pool(); $this->sync($pool);
        $this->a->update(['protocol_settings' => ['tls' => 1, 'network' => 'ws']]);
        $this->postJson('/_tests/direct-pool/save', [...$this->payload, 'id' => $pool->id, 'enabled' => false])->assertOk();
        $this->sync($pool);
        $this->assertFalse(DirectPoolService::node($pool)->enabled);
        $this->assertCount(0, ServerService::getAvailableServers($this->user));
        $this->assertCount(0, ServerService::getAvailableUsers($this->a));
        $this->assertSame([], $this->remote);
    }

    public function test_remove_and_delete_restore_original_member_permissions_and_rate(): void
    {
        $pool = $this->pool();
        $this->postJson('/_tests/direct-pool/save', [...$this->payload, 'id' => $pool->id, 'members' => [$this->payload['members'][1]]])->assertOk();
        $this->a->refresh();
        $this->assertNull($this->a->balancer_pool_id);
        $this->assertEquals(3, $this->a->getEffectiveRate());
        $this->user->update(['group_id' => $this->originalGroup]);
        $this->assertSame([$this->user->id], ServerService::getAvailableUserIds($this->a));
        (new LoadBalancerController())->delete(Request::create('/', 'POST', ['id' => $pool->id]));
        $this->assertNull(Server::find($pool->gateway_node_id));
        $this->assertNull($this->b->fresh()->balancer_pool_id);
        $this->assertCount(2, ServerService::getAvailableServers($this->user));
    }

    public function test_settings_and_subscription_identity_survive_reality_sync_and_reordering(): void
    {
        $pool = $this->pool(['reality_sync_enabled' => true]);
        app(LoadBalancerService::class)->syncReality($pool->id);
        $this->postJson('/_tests/direct-pool/save', [...$this->payload, 'id' => $pool->id,
            'members' => array_reverse($this->payload['members'])])->assertOk();
        $this->assertSame($pool->gateway_node_id, $pool->fresh()->gateway_node_id);
        $this->assertEquals($this->payload['direct_settings'], DirectPoolService::settings($pool->fresh()));
    }

    public function test_existing_pool_mode_cannot_change_and_members_cannot_join_another_pool(): void
    {
        $pool = $this->pool();
        $this->postJson('/_tests/direct-pool/save', [...$this->payload, 'id' => $pool->id, 'mode' => 'dns'])->assertUnprocessable()->assertJsonValidationErrors('mode');
        foreach (['dns', 'direct'] as $mode) {
            $this->postJson('/_tests/direct-pool/save', [...$this->payload, 'mode' => $mode, 'domain' => 'other.example.test'])
                ->assertUnprocessable()->assertJsonValidationErrors('members');
        }
        $this->assertSame(1, BalancerPool::count());
    }

    public function test_direct_members_support_relay_but_virtual_node_and_latency_targets_do_not(): void
    {
        $pool = $this->pool();
        $this->assertTrue(ServerRelayService::isSupportedEntry($this->a));
        $this->assertNull(ServerRelayService::validateEntry(null, $this->a->id, 'shadowsocks', ['cipher' => 'aes-128-gcm'], '192.0.2.20', 'xray'));
        $this->assertStringContainsString('直出订阅线路', ServerRelayService::validateEntry(null, $pool->gateway_node_id, 'shadowsocks', ['cipher' => 'aes-128-gcm'], '192.0.2.20', 'xray'));
        $this->assertNotNull(ServerRelayService::validateEntry($this->a->id, $this->b->id, 'vless', $this->a->protocol_settings, $this->a->host, 'xray'));
        $this->postJson('/_tests/direct-pool/save', [...$this->payload, 'id' => BalancerPool::first()->id,
            'scheduling_config' => ['strategy' => 'latency']])->assertUnprocessable()->assertJsonValidationErrors('scheduling_config.strategy');
    }

    public function test_invalid_permissions_and_failed_database_write_leave_no_partial_pool(): void
    {
        $this->postJson('/_tests/direct-pool/save', [...$this->payload, 'direct_settings' => ['group_ids' => [], 'rate' => 2]])
            ->assertUnprocessable()->assertJsonValidationErrors('direct_settings.group_ids');
        $this->failNextQueryMatching('/insert into [`"]?v2_balancer_member/i');
        try { app(LoadBalancerService::class)->save($this->payload); $this->fail('数据库故障应拒绝保存'); }
        catch (\App\Exceptions\ApiException) { }
        $this->assertSame(0, BalancerPool::count());
        $this->assertSame(2, Server::count());
        $this->assertNull($this->a->fresh()->balancer_pool_id);
    }

    public function test_both_members_bill_once_and_route_statistics_use_unified_node(): void
    {
        $pool = $this->pool();
        DB::table('v2_settings')->where('name', 'user_route_statistics_started_at')->update(['value' => (string) (time() - 60)]);
        foreach ([$this->a, $this->b] as $index => $node) {
            ServerService::getUsersForSync($node);
            $batch = app(NodeReportService::class)->accept($node, 'direct-report-' . $index, [$this->user->id => [100, 200]], []);
            $batch = NodeReportBatch::where('server_id', $node->id)->firstOrFail();
            $this->assertSame($pool->gateway_node_id, $batch->server_snapshot['direct_node_id']);
            $job = new ProcessNodeReportBatch($batch->id); $job->handle(); $job->handle();
        }
        $this->assertSame(400, (int) $this->user->fresh()->u);
        $this->assertSame(800, (int) $this->user->fresh()->d);
        $this->assertSame(200, (int) DB::table(UserRouteTraffic::TABLE)->where('server_id', $pool->gateway_node_id)->sum('u'));
        $this->assertSame(400, (int) DB::table(UserRouteTraffic::TABLE)->where('server_id', $pool->gateway_node_id)->sum('d'));
        $this->assertSame(100, (int) $this->a->fresh()->u);
        $this->assertSame(100, (int) $this->b->fresh()->u);
    }

    public function test_pending_report_keeps_received_rate_after_pool_edit(): void
    {
        $pool = $this->pool();
        ServerService::getUsersForSync($this->a);
        $batch = app(NodeReportService::class)->accept($this->a, 'frozen-direct-rate', [$this->user->id => [100, 200]], []);
        $this->postJson('/_tests/direct-pool/save', [...$this->payload, 'id' => $pool->id,
            'direct_settings' => [...$this->payload['direct_settings'], 'rate' => 9]])->assertOk();
        (new ProcessNodeReportBatch($batch->id))->handle();
        $this->assertSame(200, (int) $this->user->fresh()->u);
        $this->assertSame(400, (int) $this->user->fresh()->d);
        $this->assertEquals(9, $this->a->getEffectiveRate());
    }

    public function test_legacy_jobs_use_pool_rate_and_unified_route_without_extra_billing(): void
    {
        $pool = $this->pool();
        [$snapshot, $protocol, $traffic] = (new \App\Services\UserService())->prepareTraffic($this->a, 'vless', [$this->user->id => [100, 200]]);
        $this->assertSame($pool->gateway_node_id, $snapshot['direct_node_id']);
        $this->assertEquals(2, $snapshot['rate']);
        DB::table('v2_settings')->where('name', 'user_route_statistics_started_at')->update(['value' => (string) (time() - 60)]);
        (new \App\Jobs\TrafficFetchJob($snapshot, $traffic, $protocol, time()))->handle();
        (new \App\Jobs\StatUserJob($snapshot, $traffic, $protocol))->handle();
        (new \App\Jobs\StatServerJob($snapshot, $traffic, $protocol))->handle();
        $this->assertSame(200, (int) $this->user->fresh()->u);
        $this->assertSame(400, (int) $this->user->fresh()->d);
        $this->assertSame(100, (int) $this->a->fresh()->u);
        $this->assertSame(100, (int) DB::table(UserRouteTraffic::TABLE)->where('server_id', $pool->gateway_node_id)->sum('u'));
    }

    public function test_member_list_distinguishes_pool_compatibility_from_relay_entry_support(): void
    {
        $this->pool();
        $response = (new \App\Http\Controllers\V2\Admin\Server\ManageController())->getNodes(Request::create('/'));
        $node = collect($response->getData(true)['data'])->firstWhere('id', $this->a->id);
        $this->assertTrue($node['relay_entry_supported']);
        $this->assertTrue($node['pool_entry_supported']);
        $this->assertNotEmpty($node['balancer_pool_id']);
        $this->assertEquals(2, $node['effective_rate']);
        $this->assertEquals(3, $node['relay_entry_rate']);
        $this->assertEquals(3, $node['rate']);
    }

    public function test_permission_changes_send_full_member_users_and_disabled_pool_sends_empty_list(): void
    {
        $pool = $this->pool();
        Cache::put('node_ws_alive:' . $this->a->id, true);
        $sent = [];
        $redis = \Mockery::mock();
        Redis::swap($redis);
        $redis->shouldReceive('publish')->andReturnUsing(function ($channel, $json) use (&$sent) {
            $sent[] = json_decode($json, true);
            return 0;
        });
        NodeSyncService::notifyUserChanged($this->user);
        $sync = collect($sent)->firstWhere('event', 'sync.users');
        $this->assertSame($this->a->id, $sync['node_id']);
        $this->assertSame($this->user->id, $sync['data']['users'][0]['id']);
        $this->assertFalse(collect($sent)->contains('event', 'sync.user.delta'));
        $pool->update(['enabled' => false]);
        $sent = [];
        NodeSyncService::notifyUserChanged($this->user);
        $sync = collect($sent)->firstWhere('event', 'sync.users');
        $this->assertSame([], $sync['data']['users']);
    }

    public function test_runtime_counts_use_pool_groups_without_counting_virtual_node(): void
    {
        $pool = $this->pool();
        app(\App\Services\UserConnectionService::class)->replace($this->a, [$this->user->id => 0]);
        app(\App\Services\UserConnectionService::class)->replace($this->b, [$this->user->id => 0]);
        $counts = app(\App\Services\UserConnectionService::class)->forUsers([$this->user]);
        $this->assertSame(0, $counts[$this->user->id]['connection_count']);
        $this->assertFalse($counts[$this->user->id]['connection_count_partial']);
        $nodes = DirectPoolService::runtimeNodes(Server::get(['id', 'group_ids', 'enabled']));
        $this->assertCount(2, $nodes);
        $this->assertSame([$this->poolGroup], $nodes->first()->group_ids);
        $this->assertNull($nodes->firstWhere('id', $pool->gateway_node_id));
    }

    public function test_failed_dns_delete_keeps_pool_disabled_until_retry_then_preserves_route_name(): void
    {
        $pool = $this->pool(); $this->sync($pool);
        $this->dnsFailed = true;
        try {
            (new LoadBalancerController())->delete(Request::create('/', 'POST', ['id' => $pool->id]));
            $this->fail('DNS 失败时不能删除池');
        } catch (\Illuminate\Validation\ValidationException) { }
        $this->assertFalse($pool->fresh()->enabled);
        $this->assertFalse(DirectPoolService::node($pool)->enabled);
        $this->assertSame($pool->id, $this->a->fresh()->balancer_pool_id);
        $this->assertNotNull(Server::find($pool->gateway_node_id));
        $this->dnsFailed = false;
        (new LoadBalancerController())->delete(Request::create('/', 'POST', ['id' => $pool->id]));
        $this->assertNull(BalancerPool::find($pool->id));
        $this->assertSame('美国直出', DB::table('v2_stat_server_name')->where('server_id', $pool->gateway_node_id)->value('name'));
    }
}
