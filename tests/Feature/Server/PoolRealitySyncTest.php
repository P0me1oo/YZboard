<?php

namespace Tests\Feature\Server;

use App\Http\Controllers\V2\Admin\Server\LoadBalancerController;
use App\Models\BalancerDnsPreset;
use App\Models\BalancerPool;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Services\LoadBalancerService;
use App\Services\PoolRealityService;
use App\Services\ServerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PoolRealitySyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        Redis::shouldReceive('publish')->andReturn(1);
        Route::post('/_tests/pool-reality/save', [LoadBalancerController::class, 'save']);
        Route::post('/_tests/pool-reality/sync', [LoadBalancerController::class, 'syncReality']);
        Route::get('/_tests/pool-reality/fetch', [LoadBalancerController::class, 'fetch']);
    }

    private function reality(): array
    {
        $private = random_bytes(32);
        $encode = fn ($value) => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
        return ['private_key' => $encode($private), 'public_key' => $encode(\ParagonIE_Sodium_Compat::crypto_scalarmult_base($private)),
            'short_id' => bin2hex(random_bytes(8)), 'server_name' => 'handshake.example.test', 'server_port' => 443];
    }

    private function node(int $index, string $kernel = 'xray'): Server
    {
        $machine = ServerMachine::create(['name' => '测试前置', 'token' => Str::random(32), 'is_active' => true, 'agent_runtime' => ['version' => 'v2.30.0']]);
        return Server::create(['name' => '测试前置 ' . $index, 'machine_id' => $machine->id, 'host' => '192.0.2.' . $index,
            'type' => 'vless', 'kernel_type' => $kernel, 'port' => '30443', 'server_port' => 443 + $index, 'enabled' => true, 'show' => true,
            'rate' => $index, 'group_ids' => [1], 'accept_proxy_protocol' => $index === 1,
            'protocol_settings' => ['tls' => 2, 'network' => 'tcp', 'flow' => 'xtls-rprx-vision',
                'utls' => ['enabled' => true, 'fingerprint' => 'chrome'], 'reality_settings' => [...$this->reality(),
                    'server_name' => 'node' . $index . '.example.test', 'dest' => '127.0.0.1:' . (8443 + $index)]]]);
    }

    private function payload(array $nodes, array $extra = []): array
    {
        $preset = BalancerDnsPreset::first() ?? BalancerDnsPreset::create(['name' => '测试 DNS', 'provider' => 'powerdns',
            'domain' => 'example.test', 'zone_id' => md5('example.test'), 'api_url' => 'https://dns-api.example.test',
            'server_id' => 'localhost', 'api_token' => Str::random(40)]);
        return [...['name' => '测试统一配置', 'domain' => 'pool.example.test', 'dns_preset_id' => $preset->id,
            'enabled' => true, 'reality_sync_enabled' => true,
            'members' => array_map(fn ($node) => ['node_id' => $node->id, 'priority' => 1, 'weight' => 1], $nodes)], ...$extra];
    }

    public static function kernels(): array
    {
        return [['xray'], ['singbox']];
    }

    #[DataProvider('kernels')]
    public function test_save_unifies_reality_and_preserves_node_specific_settings(string $kernel): void
    {
        $a = $this->node(1, $kernel); $b = $this->node(2, $kernel);
        $before = [$a->fresh()->getAttributes(), $b->fresh()->getAttributes()];
        $reference = data_get($a->protocol_settings, 'reality_settings');
        $response = $this->postJson('/_tests/pool-reality/save', $this->payload([$a, $b]))->assertOk();
        $pool = BalancerPool::findOrFail($response->json('data.id'));
        $this->assertTrue(PoolRealityService::enabled($pool));
        $this->assertSame(2, Server::count());
        foreach ([$a, $b] as $index => $node) {
            $node->refresh();
            $settings = $node->protocol_settings;
            foreach (PoolRealityService::FIELDS as $key) $this->assertEquals($reference[$key], $settings['reality_settings'][$key]);
            $this->assertSame('127.0.0.1:' . (8444 + $index), $settings['reality_settings']['dest']);
            $this->assertSame([], LoadBalancerService::memberCompatibility($pool, $node));
            $after = $node->getAttributes();
            foreach (['protocol_settings', 'updated_at'] as $key) unset($before[$index][$key], $after[$key]);
            $this->assertSame($before[$index], $after);
            $config = ServerService::buildNodeConfig($node);
            $this->assertSame($reference['private_key'], data_get($config, 'tls_settings.private_key'));
            $this->assertSame($reference['server_name'], data_get($config, 'tls_settings.server_name'));
        }
        $view = $this->getJson('/_tests/pool-reality/fetch')->assertOk()->json('data.pools.0');
        $this->assertArrayNotHasKey('template_config', $view);
        $this->assertArrayNotHasKey('private_key', $view['reality_settings']);
        $this->assertTrue($view['reality_settings']['has_private_key']);
        $this->assertSame($reference['public_key'], $view['reality_settings']['public_key']);
        $this->assertStringNotContainsString($reference['private_key'], DB::table('v2_balancer_pool')->value('template_config'));
    }

    public function test_repeat_save_blank_private_key_and_member_reordering_keep_pool_keys(): void
    {
        $a = $this->node(1); $b = $this->node(2);
        $pool = app(LoadBalancerService::class)->save($this->payload([$a, $b]));
        $original = data_get($pool->template_config, 'protocol_settings.reality_settings');
        $different = $this->reality();
        $b->update(['protocol_settings' => [...$b->fresh()->protocol_settings, 'reality_settings' => $different]]);
        $settings = [...PoolRealityService::view($pool), 'private_key' => ''];
        unset($settings['has_private_key']);
        $response = $this->postJson('/_tests/pool-reality/save', $this->payload([$b, $a], ['id' => $pool->id, 'reality_settings' => $settings]))->assertOk();
        $pool->refresh();
        $this->assertSame($pool->id, $response->json('data.id'));
        foreach ([$a, $b] as $node) foreach (PoolRealityService::FIELDS as $key) {
            $this->assertEquals($original[$key], data_get($node->fresh()->protocol_settings, 'reality_settings.' . $key));
        }
        $this->assertSame($b->id, $pool->template_node_id);
        // 首选节点移出后，池密钥仍以已保存的模板为准。
        $this->postJson('/_tests/pool-reality/save', $this->payload([$a], ['id' => $pool->id]))->assertOk();
        $this->assertSame($original['private_key'], data_get($pool->fresh()->template_config, 'protocol_settings.reality_settings.private_key'));
    }

    public function test_explicit_key_rotation_derives_public_key_and_updates_all_nodes(): void
    {
        $a = $this->node(1); $b = $this->node(2);
        $pool = app(LoadBalancerService::class)->save($this->payload([$a, $b]));
        $replacement = [...$this->reality(), 'server_name' => 'new.example.test', 'server_port' => 9443];
        $input = $replacement;
        unset($input['public_key']);
        $this->postJson('/_tests/pool-reality/save', $this->payload([$a, $b], ['id' => $pool->id, 'reality_settings' => $input]))->assertOk();
        foreach ([$a, $b] as $node) foreach ($replacement as $key => $value) {
            $this->assertEquals($value, data_get($node->fresh()->protocol_settings, 'reality_settings.' . $key));
        }
    }

    public static function invalidSettings(): array
    {
        return [[['private_key' => 'invalid'], 'private_key'], [['public_key' => str_repeat('A', 43)], 'public_key'],
            [['short_id' => 'abc'], 'short_id'], [['short_id' => 'zz'], 'short_id'],
            [['server_name' => 'https://example.test'], 'server_name'], [['server_name' => '192.0.2.1'], 'server_name'],
            [['server_port' => 0], 'server_port']];
    }

    #[DataProvider('invalidSettings')]
    public function test_invalid_pool_configuration_never_changes_nodes(array $settings, string $field): void
    {
        $a = $this->node(1); $b = $this->node(2);
        $before = [$a->fresh()->getAttributes(), $b->fresh()->getAttributes()];
        $this->postJson('/_tests/pool-reality/save', $this->payload([$a, $b], ['reality_settings' => $settings]))
            ->assertUnprocessable()->assertJsonValidationErrors('reality_settings.' . $field);
        $this->assertSame(0, BalancerPool::count());
        $this->assertSame($before, [$a->fresh()->getAttributes(), $b->fresh()->getAttributes()]);
    }

    public function test_port_and_flow_mismatches_still_block_save_without_partial_changes(): void
    {
        $a = $this->node(1); $b = $this->node(2);
        foreach ([['port' => '15432'], ['protocol_settings' => [...$b->protocol_settings, 'flow' => '']]] as $changes) {
            $b->update(['port' => '30443', 'protocol_settings' => [...$b->protocol_settings, 'flow' => 'xtls-rprx-vision'], ...$changes]);
            $before = [$a->fresh()->getAttributes(), $b->fresh()->getAttributes()];
            $this->postJson('/_tests/pool-reality/save', $this->payload([$a, $b]))->assertUnprocessable()->assertJsonValidationErrors('members');
            $this->assertSame($before, [$a->fresh()->getAttributes(), $b->fresh()->getAttributes()]);
        }
    }

    public function test_database_failure_rolls_back_pool_and_all_node_updates(): void
    {
        $a = $this->node(1); $b = $this->node(2);
        $before = [$a->fresh()->getAttributes(), $b->fresh()->getAttributes()];
        Log::spy();
        config(['app.debug' => true]);
        $this->failNextQueryMatching('/update ["`]v2_server["`]/i');
        $response = $this->postJson('/_tests/pool-reality/save', $this->payload([$a, $b]))->assertStatus(500);
        $this->assertStringNotContainsString(data_get($a->protocol_settings, 'reality_settings.private_key'), $response->getContent());
        Log::shouldHaveReceived('error')->once()->with('前置池保存失败', ['pool_id' => 0, 'sql_state' => '0']);
        $this->assertSame(0, BalancerPool::count());
        $this->assertSame($before, [$a->fresh()->getAttributes(), $b->fresh()->getAttributes()]);
    }

    public function test_sync_restores_drift_and_disabling_does_not_modify_nodes(): void
    {
        $a = $this->node(1); $b = $this->node(2);
        $payload = $this->payload([$a, $b]);
        $pool = app(LoadBalancerService::class)->save($payload);
        $keys = $this->reality();
        $b->update(['protocol_settings' => [...$b->fresh()->protocol_settings, 'reality_settings' => $keys]]);
        $this->postJson('/_tests/pool-reality/save', [...$payload, 'id' => $pool->id, 'enabled' => false])->assertOk();
        $this->assertSame($keys['private_key'], data_get($b->fresh()->protocol_settings, 'reality_settings.private_key'));
        $this->postJson('/_tests/pool-reality/sync', ['id' => $pool->id])->assertOk();
        $this->assertFalse($pool->fresh()->enabled);
        $this->assertSame(data_get($a->fresh()->protocol_settings, 'reality_settings.private_key'), data_get($b->fresh()->protocol_settings, 'reality_settings.private_key'));
    }

    public function test_shared_nodes_cannot_be_overwritten_with_another_pools_keys(): void
    {
        $a = $this->node(1); $b = $this->node(2);
        $pool = app(LoadBalancerService::class)->save($this->payload([$a, $b]));
        $second = $this->payload([$a, $b], ['domain' => 'second.example.test']);
        $this->postJson('/_tests/pool-reality/save', $second)->assertOk();
        $before = [$a->fresh()->getAttributes(), $b->fresh()->getAttributes()];
        $this->postJson('/_tests/pool-reality/save', [...$second, 'id' => $pool->id, 'domain' => $pool->domain, 'reality_settings' => $this->reality()])
            ->assertUnprocessable()->assertJsonValidationErrors('members');
        $this->assertSame($before, [$a->fresh()->getAttributes(), $b->fresh()->getAttributes()]);
        $this->assertSame(2, BalancerPool::count());
    }

    public function test_legacy_pool_keeps_manual_validation_and_sync_requires_opt_in(): void
    {
        $a = $this->node(1); $b = $this->node(2);
        $b->update(['protocol_settings' => $a->protocol_settings]);
        $before = [$a->fresh()->getAttributes(), $b->fresh()->getAttributes()];
        $payload = $this->payload([$a, $b]);
        unset($payload['reality_sync_enabled']);
        $this->postJson('/_tests/pool-reality/save', $payload)->assertOk();
        $pool = BalancerPool::firstOrFail();
        $this->assertFalse(PoolRealityService::enabled($pool));
        $this->assertSame($before, [$a->fresh()->getAttributes(), $b->fresh()->getAttributes()]);
        $this->postJson('/_tests/pool-reality/sync', ['id' => $pool->id])->assertUnprocessable()->assertJsonValidationErrors('reality_sync_enabled');
    }

    public function test_existing_pool_opt_in_and_new_node_join_preserve_saved_template(): void
    {
        $a = $this->node(1); $b = $this->node(2);
        $b->update(['protocol_settings' => $a->protocol_settings]);
        $pool = app(LoadBalancerService::class)->save($this->payload([$a, $b], ['reality_sync_enabled' => false]));
        $saved = data_get($pool->template_config, 'protocol_settings.reality_settings');
        $a->update(['protocol_settings' => [...$a->protocol_settings, 'reality_settings' => $this->reality()]]);
        $this->postJson('/_tests/pool-reality/save', $this->payload([$a, $b], ['id' => $pool->id]))->assertOk();
        $c = $this->node(3);
        $this->postJson('/_tests/pool-reality/save', $this->payload([$c, $b, $a], ['id' => $pool->id]))->assertOk();
        foreach ([$a, $b, $c] as $node) foreach (PoolRealityService::FIELDS as $key) {
            $this->assertEquals($saved[$key], data_get($node->fresh()->protocol_settings, 'reality_settings.' . $key));
        }
        $this->assertSame($c->id, $pool->fresh()->template_node_id);
    }

    public function test_encryption_mismatches_are_rejected_even_with_reality_sync(): void
    {
        $a = $this->node(1); $b = $this->node(2);
        $encryption = ['enabled' => true, 'encryption' => 'mlkem768x25519plus.native.0rtt.' . str_repeat('A', 43),
            'decryption' => 'mlkem768x25519plus.native.600s.' . str_repeat('A', 43)];
        foreach (['enabled', 'encryption', 'decryption'] as $field) {
            $different = [...$encryption, $field => $field === 'enabled' ? false : str_replace(str_repeat('A', 43), str_repeat('B', 43), $encryption[$field])];
            $a->update(['protocol_settings' => [...$a->protocol_settings, 'encryption' => $encryption]]);
            $b->update(['protocol_settings' => [...$b->protocol_settings, 'encryption' => $different]]);
            $before = [$a->fresh()->protocol_settings, $b->fresh()->protocol_settings];
            $this->postJson('/_tests/pool-reality/save', $this->payload([$a, $b]))
                ->assertUnprocessable()->assertJsonValidationErrors('members');
            $this->assertSame($before, [$a->fresh()->protocol_settings, $b->fresh()->protocol_settings]);
            $this->assertSame(0, BalancerPool::count());
        }
        $b->update(['protocol_settings' => [...$b->protocol_settings, 'encryption' => $encryption]]);
        $this->postJson('/_tests/pool-reality/save', $this->payload([$a, $b]))->assertOk();
        $this->assertSame($encryption, $b->fresh()->protocol_settings['encryption']);
        $this->assertSame([], LoadBalancerService::memberCompatibility(BalancerPool::first(), $b->fresh()));
        $b->update(['protocol_settings' => [...$b->protocol_settings, 'encryption' => [...$encryption, 'enabled' => false]]]);
        $this->assertNotEmpty(LoadBalancerService::memberCompatibility(BalancerPool::first(), $b->fresh()));
    }

    public function test_adding_a_frontend_validates_existing_internal_protocols_before_writing(): void
    {
        $a = $this->node(1); $b = $this->node(2, 'singbox');
        $this->postJson('/_tests/pool-reality/save', $this->payload([$a]))->assertOk();
        $pool = BalancerPool::firstOrFail();
        $landing = $this->node(3);
        $landing->update(['relay_pool_id' => $pool->id, 'host' => '10.0.0.3',
            'protocol_settings' => ['network' => 'xhttp', 'tls' => 0, 'flow' => '']]);
        $before = [$a->fresh()->protocol_settings, $b->fresh()->protocol_settings];
        $this->postJson('/_tests/pool-reality/save', $this->payload([$a, $b], ['id' => $pool->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('members');
        $this->assertSame(1, $pool->members()->count());
        $this->assertSame($before, [$a->fresh()->protocol_settings, $b->fresh()->protocol_settings]);
        // 已有兼容成员仍能重复保存；落地使用双内核均支持的 TCP 后可以增加成员。
        $this->postJson('/_tests/pool-reality/save', $this->payload([$a], ['id' => $pool->id]))->assertOk();
        $landing->update(['protocol_settings' => ['network' => 'tcp', 'tls' => 0, 'flow' => '']]);
        $this->postJson('/_tests/pool-reality/save', $this->payload([$a, $b], ['id' => $pool->id]))->assertOk();
        $this->assertSame(2, $pool->members()->count());
    }

    public function test_existing_landing_failover_rejects_a_new_old_agent(): void
    {
        $a = $this->node(1); $b = $this->node(2);
        $this->postJson('/_tests/pool-reality/save', $this->payload([$a]))->assertOk();
        $pool = BalancerPool::firstOrFail();
        $landing = $this->node(3);
        $landing->update(['relay_pool_id' => $pool->id, 'type' => 'shadowsocks', 'protocol_settings' => ['cipher' => 'aes-128-gcm']]);
        app(\App\Services\LandingPoolService::class)->save(['name' => '主备测试', 'node_id' => $landing->id,
            'members' => [$landing->id], 'enabled' => true]);
        $b->machine->update(['agent_runtime' => ['version' => 'v2.29.0']]);
        $this->postJson('/_tests/pool-reality/save', $this->payload([$a, $b], ['id' => $pool->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('members');
        $this->assertSame(1, $pool->members()->count());
        $b->machine->update(['agent_runtime' => ['version' => 'v2.30.0']]);
        $this->postJson('/_tests/pool-reality/save', $this->payload([$a, $b], ['id' => $pool->id]))->assertOk();
    }

    public function test_node_editor_cannot_change_a_frontend_to_an_incompatible_core(): void
    {
        Route::post('/_tests/pool-reality/node-save', [\App\Http\Controllers\V2\Admin\Server\ManageController::class, 'save']);
        Route::post('/_tests/pool-reality/node-update', [\App\Http\Controllers\V2\Admin\Server\ManageController::class, 'update']);
        $a = $this->node(1);
        $this->postJson('/_tests/pool-reality/save', $this->payload([$a]))->assertOk();
        $landing = $this->node(3);
        $landing->update(['relay_pool_id' => BalancerPool::first()->id, 'host' => '10.0.0.3',
            'protocol_settings' => ['network' => 'xhttp', 'tls' => 0, 'flow' => '']]);
        $values = [...$a->fresh()->only(['id', 'name', 'type', 'host', 'port', 'server_port', 'group_ids', 'protocol_settings', 'enabled', 'show', 'rate', 'machine_id']),
            'kernel_type' => 'singbox'];
        $this->postJson('/_tests/pool-reality/node-save', $values)->assertUnprocessable()->assertJsonValidationErrors('kernel_type');
        $this->assertSame('xray', $a->fresh()->kernel_type);
        $this->postJson('/_tests/pool-reality/node-update', ['id' => $a->id, 'kernel_type' => 'singbox'])
            ->assertUnprocessable()->assertJsonValidationErrors('kernel_type');
        $this->assertSame('xray', $a->fresh()->kernel_type);
    }

    public function test_anti_abuse_destination_conflict_is_rejected_before_any_writes(): void
    {
        $a = $this->node(1); $b = $this->node(2);
        $b->update(['protocol_settings' => [...$b->protocol_settings, 'reality_settings' => [...$b->protocol_settings['reality_settings'], 'anti_abuse' => true]]]);
        $before = [$a->fresh()->getAttributes(), $b->fresh()->getAttributes()];
        $this->postJson('/_tests/pool-reality/save', $this->payload([$a, $b]))->assertUnprocessable()->assertJsonValidationErrors('reality_settings.server_name');
        $this->assertSame($before, [$a->fresh()->getAttributes(), $b->fresh()->getAttributes()]);
    }
}
