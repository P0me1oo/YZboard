<?php

namespace Tests\Feature\Server;

use App\Http\Controllers\V2\Admin\Server\ManageController;
use App\Models\BalancerMember;
use App\Models\BalancerPool;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\User;
use App\Models\AdminAuditLog;
use App\Http\Routes\V2\AdminRoute;
use App\Services\LoadBalancerService;
use App\Services\PoolRealityService;
use App\Services\ServerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Laravel\Sanctum\Sanctum;

class ServerBatchRealityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        Redis::shouldReceive('publish')->andReturn(1);
        Route::post('/_tests/batch-reality', [ManageController::class, 'batchReality']);
        Route::get('/_tests/batch-reality-scope', [ManageController::class, 'batchRealityScope']);
    }

    private function settings(): array
    {
        $private = random_bytes(32);
        $encode = fn ($bytes) => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
        return ['private_key' => $encode($private), 'public_key' => $encode(\ParagonIE_Sodium_Compat::crypto_scalarmult_base($private)),
            'short_id' => bin2hex(random_bytes(8)), 'server_name' => 'handshake.example.test', 'server_port' => 443];
    }

    private function node(int $index, string $type = 'vless'): Server
    {
        $machine = ServerMachine::create(['name' => '测试服务器 ' . $index, 'token' => bin2hex(random_bytes(16)), 'is_active' => true]);
        return Server::create(['name' => '测试节点 ' . $index, 'type' => $type, 'kernel_type' => 'xray',
            'machine_id' => $machine->id,
            'host' => '192.0.2.' . $index, 'port' => 30443, 'server_port' => 24443, 'enabled' => true,
            'show' => true, 'rate' => $index, 'group_ids' => [], 'accept_proxy_protocol' => true,
            'protocol_settings' => ['tls' => 2, 'network' => 'tcp', 'flow' => 'xtls-rprx-vision',
                'reality_settings' => [...$this->settings(), 'dest' => '127.0.0.1:' . (8443 + $index), 'anti_abuse' => false]]])->refresh();
    }

    private function payload(array $nodes, array $settings, array $poolIds = []): array
    {
        return ['ids' => array_map(fn ($node) => $node->id, $nodes), 'pool_ids' => $poolIds, 'mode' => 'manual', 'reality_settings' => $settings];
    }

    private function pool(array $nodes, array $settings, array $extra = []): BalancerPool
    {
        $first = $nodes[0];
        $pool = BalancerPool::create(['name' => '测试池 ' . (BalancerPool::count() + 1), 'mode' => 'dns', 'protocol' => 'vless',
            'domain' => 'pool' . (BalancerPool::count() + 1) . '.example.test', 'gateway_machine_id' => $first->machine_id,
            'gateway_address' => $first->host, 'backend_port' => 24443, 'gateway_port' => 30443, 'template_node_id' => $first->id,
            'template_config' => ['type' => 'vless', 'kernel_type' => 'xray', 'reality_sync_enabled' => true,
                'rate' => 2, 'group_ids' => [1], 'protocol_settings' => ['tls' => 2, 'network' => 'tcp', 'flow' => 'xtls-rprx-vision',
                    'reality_settings' => [...$settings, 'dest' => '127.0.0.1:8443', 'anti_abuse' => false]]],
            'enabled' => true, ...$extra])->refresh();
        foreach ($nodes as $position => $node) BalancerMember::create(['pool_id' => $pool->id, 'source_node_id' => $node->id,
            'runtime_node_id' => $node->id, 'priority' => $position + 1, 'weight' => $position + 2, 'position' => $position]);
        return $pool;
    }

    public function test_unifies_only_pool_reality_fields_and_is_idempotent(): void
    {
        $a = $this->node(1); $b = $this->node(2); $other = $this->node(3);
        $before = [$a->getAttributes(), $b->getAttributes(), $other->getAttributes()];
        $settings = $this->settings();
        $input = $settings;
        unset($input['public_key']);
        $response = $this->postJson('/_tests/batch-reality', $this->payload([$a, $b], $input))->assertOk()
            ->assertJsonPath('data.count', 2)->assertJsonPath('data.updated', 2);
        $this->assertStringNotContainsString($settings['private_key'], $response->getContent());
        foreach ([$a, $b] as $index => $node) {
            $node->refresh();
            foreach (PoolRealityService::FIELDS as $field) $this->assertEquals($settings[$field], $node->protocol_settings['reality_settings'][$field]);
            $this->assertSame('127.0.0.1:' . (8444 + $index), $node->protocol_settings['reality_settings']['dest']);
            $after = $node->getAttributes();
            foreach (['protocol_settings', 'updated_at'] as $key) unset($before[$index][$key], $after[$key]);
            $this->assertSame($before[$index], $after);
            $config = ServerService::buildNodeConfig($node);
            $this->assertSame($settings['private_key'], data_get($config, 'tls_settings.private_key'));
        }
        $this->assertSame($before[2], $other->fresh()->getAttributes());
        $this->postJson('/_tests/batch-reality', $this->payload([$a, $b], $input))->assertOk()->assertJsonPath('data.updated', 0);
    }

    public static function invalidSettings(): array
    {
        return [[['private_key' => 'invalid'], 'private_key'], [['public_key' => str_repeat('A', 43)], 'public_key'],
            [['short_id' => 'abc'], 'short_id'], [['server_name' => 'https://example.test'], 'server_name'],
            [['server_port' => 65536], 'server_port'], [['dest' => '127.0.0.1:443'], '']];
    }

    #[DataProvider('invalidSettings')]
    public function test_invalid_manual_settings_preserve_the_entire_batch(array $patch, string $field): void
    {
        $a = $this->node(1); $b = $this->node(2);
        $before = [$a->getAttributes(), $b->getAttributes()];
        $this->postJson('/_tests/batch-reality', $this->payload([$a, $b], [...$this->settings(), ...$patch]))
            ->assertUnprocessable()->assertJsonValidationErrors('reality_settings' . ($field ? '.' . $field : ''));
        $this->assertSame($before, [$a->fresh()->getAttributes(), $b->fresh()->getAttributes()]);
    }

    public function test_stale_duplicate_shared_and_non_reality_selections_are_rejected(): void
    {
        $a = $this->node(1); $b = $this->node(2);
        $settings = $this->settings();
        foreach ([[$a->id], [$a->id, $a->id], [$a->id, 999999]] as $ids) {
            $this->postJson('/_tests/batch-reality', ['ids' => $ids, 'mode' => 'manual', 'reality_settings' => $settings])->assertUnprocessable();
        }
        foreach ([['parent_id' => $a->id], ['protocol_settings' => ['tls' => 1]]] as $patch) {
            $b->update(['parent_id' => null, ...$patch]);
            $before = [$a->fresh()->getAttributes(), $b->fresh()->getAttributes()];
            $this->postJson('/_tests/batch-reality', $this->payload([$a, $b], $settings))->assertUnprocessable();
            $this->assertSame($before, [$a->fresh()->getAttributes(), $b->fresh()->getAttributes()]);
        }
    }

    public function test_selected_nodes_and_pool_template_are_updated_together_without_changing_other_fields(): void
    {
        $a = $this->node(1); $b = $this->node(2);
        $pool = $this->pool([$a, $b], $this->settings());
        $template = $pool->template_config;
        $before = $pool->getAttributes();
        $members = $pool->members()->get()->map->getAttributes()->all();
        $settings = $this->settings();
        $response = $this->postJson('/_tests/batch-reality', $this->payload([$a, $b], $settings, [$pool->id]))
            ->assertOk()->assertJsonPath('data.count', 2)->assertJsonPath('data.pools_updated', 1);
        $this->assertStringNotContainsString($settings['private_key'], $response->getContent());
        $pool->refresh();
        $template['protocol_settings']['reality_settings'] = array_replace($template['protocol_settings']['reality_settings'], $settings);
        $this->assertSame($template, $pool->template_config);
        $after = $pool->getAttributes();
        foreach (['template_config', 'updated_at'] as $key) unset($before[$key], $after[$key]);
        $this->assertSame($before, $after);
        $this->assertSame($members, $pool->members()->get()->map->getAttributes()->all());
        $this->assertSame([], LoadBalancerService::memberCompatibility($pool, $a->fresh()));
        $saved = [$a->fresh()->getAttributes(), $b->fresh()->getAttributes(), $pool->getAttributes()];
        $this->postJson('/_tests/batch-reality', $this->payload([$a, $b], $settings, [$pool->id]))
            ->assertOk()->assertJsonPath('data.updated', 0)->assertJsonPath('data.pools_updated', 0);
        $this->assertSame($saved, [$a->fresh()->getAttributes(), $b->fresh()->getAttributes(), $pool->fresh()->getAttributes()]);
    }

    public function test_anti_abuse_conflict_never_leaves_partial_changes(): void
    {
        $a = $this->node(1); $b = $this->node(2);
        $b->update(['protocol_settings' => [...$b->protocol_settings, 'reality_settings' => [
            ...$b->protocol_settings['reality_settings'], 'anti_abuse' => true, 'dest' => 'old.example.test:443']]]);
        $before = [$a->fresh()->getAttributes(), $b->fresh()->getAttributes()];
        $this->postJson('/_tests/batch-reality', $this->payload([$a, $b], $this->settings()))->assertUnprocessable();
        $this->assertSame($before, [$a->fresh()->getAttributes(), $b->fresh()->getAttributes()]);
    }

    public function test_second_write_failure_rolls_back_and_does_not_log_keys(): void
    {
        $a = $this->node(1); $b = $this->node(2);
        $before = [$a->getAttributes(), $b->getAttributes()];
        $settings = $this->settings();
        $writes = 0;
        $this->app['db']->connection()->beforeExecuting(function ($sql, $bindings, $connection) use (&$writes): void {
            if (preg_match('/\Aupdate ["`]v2_server["`]/', $sql) && ++$writes === 2) {
                throw new \Illuminate\Database\QueryException($connection->getName(), $sql, $bindings, new \RuntimeException('测试写入失败'));
            }
        });
        Log::shouldReceive('error')->once()->with('批量统一 Reality 失败', ['exception' => \Illuminate\Database\QueryException::class]);
        $response = $this->postJson('/_tests/batch-reality', $this->payload([$a, $b], $settings))->assertStatus(500);
        $this->assertStringNotContainsString($settings['private_key'], $response->getContent());
        $this->assertSame($before, [$a->fresh()->getAttributes(), $b->fresh()->getAttributes()]);
    }

    public function test_reference_mode_uses_only_a_selected_node_and_rejects_mixed_modes(): void
    {
        $a = $this->node(1); $b = $this->node(2); $other = $this->node(3);
        $before = [$a->getAttributes(), $b->getAttributes(), $other->getAttributes()];
        $payload = ['ids' => [$a->id, $b->id], 'mode' => 'node', 'source_id' => $b->id];
        $this->postJson('/_tests/batch-reality', [...$payload, 'source_id' => $other->id])->assertUnprocessable()->assertJsonValidationErrors('source_id');
        $this->postJson('/_tests/batch-reality', [...$payload, 'reality_settings' => $this->settings()])->assertUnprocessable()->assertJsonValidationErrors('reality_settings');
        $this->assertSame($before, [$a->fresh()->getAttributes(), $b->fresh()->getAttributes(), $other->fresh()->getAttributes()]);
        $settings = $b->protocol_settings['reality_settings'];
        $response = $this->postJson('/_tests/batch-reality', $payload)->assertOk()->assertJsonPath('data.updated', 1);
        foreach (PoolRealityService::FIELDS as $field) $this->assertEquals($settings[$field], $a->fresh()->protocol_settings['reality_settings'][$field]);
        $this->assertSame($before[1], $b->fresh()->getAttributes());
        $this->assertSame($before[2], $other->fresh()->getAttributes());
        $this->assertStringNotContainsString($settings['private_key'], $response->getContent());
    }

    public function test_admin_route_protects_access_and_audit_excludes_reality_settings(): void
    {
        admin_setting(['secure_path' => 'reality-admin']);
        Route::group(['prefix' => '_tests/admin-access'], fn () => (new AdminRoute())->map(app('router')));
        $url = '/_tests/admin-access/reality-admin/server/manage/batchReality';
        $a = $this->node(1); $b = $this->node(2);
        $settings = $this->settings();
        $payload = $this->payload([$a, $b], $settings);
        $this->postJson($url, $payload)->assertForbidden();
        $scopeUrl = '/_tests/admin-access/reality-admin/server/manage/batchRealityScope?ids[]=' . $a->id . '&ids[]=' . $b->id;
        $this->getJson($scopeUrl)->assertForbidden();
        $user = User::create(['email' => 'test-batch-reality@example.invalid', 'password' => password_hash('test-only', PASSWORD_DEFAULT),
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'token' => bin2hex(random_bytes(16)), 'is_admin' => false]);
        Sanctum::actingAs($user);
        $this->postJson($url, $payload)->assertForbidden();
        $user->update(['is_admin' => true]);
        Sanctum::actingAs($user->fresh());
        $this->postJson($url, $payload)->assertOk();
        $this->getJson($scopeUrl)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('data.pools', []);
        $audit = AdminAuditLog::firstOrFail();
        $this->assertEquals(['ids' => [$a->id, $b->id]], json_decode($audit->getRawOriginal('request_data'), true));
    }

    public function test_scope_lists_only_directly_related_pools_and_never_returns_keys(): void
    {
        $a = $this->node(1); $b = $this->node(2); $c = $this->node(3); $d = $this->node(4);
        $settings = $this->settings();
        $pool = $this->pool([$a, $c], $settings);
        $this->pool([$c, $d], $this->settings());
        $response = $this->getJson('/_tests/batch-reality-scope?ids[]=' . $a->id . '&ids[]=' . $b->id)
            ->assertOk()->assertJsonPath('data.count', 2)->assertJsonPath('data.pools', [['id' => $pool->id, 'name' => $pool->name]]);
        $this->assertStringNotContainsString($settings['private_key'], $response->getContent());
        $this->assertStringNotContainsString('template_config', $response->getContent());
    }

    public function test_unselected_conflicting_member_is_named_and_the_entire_batch_remains_unchanged(): void
    {
        $a = $this->node(1); $b = $this->node(2); $c = $this->node(3);
        $pool = $this->pool([$a, $b, $c], $this->settings());
        $before = [$a->getAttributes(), $b->getAttributes(), $c->getAttributes(), $pool->getAttributes()];
        $response = $this->postJson('/_tests/batch-reality', $this->payload([$a, $b], $this->settings(), [$pool->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('ids');
        $message = $response->json('errors.ids.0');
        $this->assertStringContainsString($pool->name, $message);
        $this->assertStringContainsString($c->name, $message);
        $this->assertStringContainsString('请补选', $message);
        $this->assertSame($before, [$a->fresh()->getAttributes(), $b->fresh()->getAttributes(), $c->fresh()->getAttributes(), $pool->fresh()->getAttributes()]);
    }

    public function test_compatible_unselected_member_is_never_written_and_indirect_pool_is_untouched(): void
    {
        $a = $this->node(1); $b = $this->node(2); $c = $this->node(3); $d = $this->node(4);
        $settings = array_intersect_key($c->protocol_settings['reality_settings'], array_flip(PoolRealityService::FIELDS));
        $pool = $this->pool([$a, $b, $c], $this->settings());
        $other = $this->pool([$c, $d], $settings);
        $before = [$c->getAttributes(), $d->getAttributes(), $other->getAttributes()];
        $this->postJson('/_tests/batch-reality', $this->payload([$a, $b], $settings, [$pool->id]))
            ->assertOk()->assertJsonPath('data.updated', 2)->assertJsonPath('data.pools_updated', 1);
        $this->assertSame($before, [$c->fresh()->getAttributes(), $d->fresh()->getAttributes(), $other->fresh()->getAttributes()]);
        foreach (PoolRealityService::FIELDS as $key) $this->assertEquals($settings[$key], data_get($pool->fresh()->template_config, 'protocol_settings.reality_settings.' . $key));
    }

    public function test_shared_selected_node_updates_every_related_pool_once(): void
    {
        $a = $this->node(1); $b = $this->node(2); $c = $this->node(3);
        $first = $this->pool([$a, $b], $this->settings());
        $second = $this->pool([$a, $c], $this->settings());
        $settings = $this->settings();
        $this->postJson('/_tests/batch-reality', $this->payload([$a, $b, $c], $settings, [$second->id, $first->id]))
            ->assertOk()->assertJsonPath('data.updated', 3)->assertJsonPath('data.pools_updated', 2);
        foreach ([$first, $second] as $pool) foreach (PoolRealityService::FIELDS as $key) {
            $this->assertEquals($settings[$key], data_get($pool->fresh()->template_config, 'protocol_settings.reality_settings.' . $key));
        }
    }

    public function test_missing_selection_in_another_pool_blocks_all_pool_and_node_writes(): void
    {
        $a = $this->node(1); $b = $this->node(2); $c = $this->node(3);
        $first = $this->pool([$a, $b], $this->settings());
        $second = $this->pool([$a, $c], $this->settings());
        $before = [$a->getAttributes(), $b->getAttributes(), $c->getAttributes(), $first->getAttributes(), $second->getAttributes()];
        $this->postJson('/_tests/batch-reality', $this->payload([$a, $b], $this->settings(), [$first->id, $second->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('ids');
        $this->assertSame($before, [$a->fresh()->getAttributes(), $b->fresh()->getAttributes(), $c->fresh()->getAttributes(), $first->fresh()->getAttributes(), $second->fresh()->getAttributes()]);
    }

    public function test_new_pool_reference_after_scope_read_requires_reconfirmation(): void
    {
        $a = $this->node(1); $b = $this->node(2);
        $scope = $this->getJson('/_tests/batch-reality-scope?ids[]=' . $a->id . '&ids[]=' . $b->id)->assertOk()->json('data');
        $pool = $this->pool([$a, $b], $this->settings());
        $before = [$a->getAttributes(), $b->getAttributes(), $pool->getAttributes()];
        $this->postJson('/_tests/batch-reality', $this->payload([$a, $b], $this->settings(), array_column($scope['pools'], 'id')))
            ->assertUnprocessable()->assertJsonValidationErrors('pool_ids');
        $this->assertSame($before, [$a->fresh()->getAttributes(), $b->fresh()->getAttributes(), $pool->fresh()->getAttributes()]);
    }

    public function test_reference_mode_links_templates_and_keeps_pool_status_and_sync_setting(): void
    {
        $a = $this->node(1); $b = $this->node(2);
        $pool = $this->pool([$a, $b], $this->settings(), ['enabled' => false]);
        $template = $pool->template_config; $template['reality_sync_enabled'] = false;
        $pool->update(['template_config' => $template]);
        $settings = $b->protocol_settings['reality_settings'];
        $this->postJson('/_tests/batch-reality', ['ids' => [$a->id, $b->id], 'pool_ids' => [$pool->id], 'mode' => 'node', 'source_id' => $b->id])
            ->assertOk()->assertJsonPath('data.pools_updated', 1);
        $this->assertFalse($pool->fresh()->enabled);
        $this->assertFalse(PoolRealityService::enabled($pool->fresh()));
        foreach (PoolRealityService::FIELDS as $key) $this->assertEquals($settings[$key], data_get($pool->fresh()->template_config, 'protocol_settings.reality_settings.' . $key));
    }

    public function test_node_write_failure_also_rolls_back_previously_written_pool_template(): void
    {
        $a = $this->node(1); $b = $this->node(2);
        $pool = $this->pool([$a, $b], $this->settings());
        $before = [$a->getAttributes(), $b->getAttributes(), $pool->getAttributes()];
        $this->failNextQueryMatching('/\Aupdate ["`]v2_server["`]/');
        Log::shouldReceive('error')->once()->with('批量统一 Reality 失败', ['exception' => \Illuminate\Database\QueryException::class]);
        $this->postJson('/_tests/batch-reality', $this->payload([$a, $b], $this->settings(), [$pool->id]))->assertStatus(500);
        $this->assertSame($before, [$a->fresh()->getAttributes(), $b->fresh()->getAttributes(), $pool->fresh()->getAttributes()]);
    }

    public function test_second_pool_write_failure_rolls_back_the_first_pool(): void
    {
        $a = $this->node(1); $b = $this->node(2);
        $first = $this->pool([$a, $b], $this->settings()); $second = $this->pool([$a, $b], $this->settings());
        $before = [$a->getAttributes(), $b->getAttributes(), $first->getAttributes(), $second->getAttributes()];
        $writes = 0;
        $this->app['db']->connection()->beforeExecuting(function ($sql, $bindings, $connection) use (&$writes): void {
            if (preg_match('/\Aupdate ["`]v2_balancer_pool["`]/', $sql) && ++$writes === 2) {
                throw new \Illuminate\Database\QueryException($connection->getName(), $sql, $bindings, new \RuntimeException('测试写入失败'));
            }
        });
        Log::shouldReceive('error')->once()->with('批量统一 Reality 失败', ['exception' => \Illuminate\Database\QueryException::class]);
        $this->postJson('/_tests/batch-reality', $this->payload([$a, $b], $this->settings(), [$first->id, $second->id]))->assertStatus(500);
        $this->assertSame($before, [$a->fresh()->getAttributes(), $b->fresh()->getAttributes(), $first->fresh()->getAttributes(), $second->fresh()->getAttributes()]);
    }

    public function test_numeric_short_ids_with_different_leading_zeroes_are_not_considered_identical(): void
    {
        $a = $this->node(1); $b = $this->node(2); $c = $this->node(3);
        $settings = [...$this->settings(), 'short_id' => '11'];
        $c->update(['protocol_settings' => [...$c->protocol_settings, 'reality_settings' => [...$settings, 'short_id' => '0011']]]);
        $pool = $this->pool([$a, $b, $c], $settings);
        $before = [$a->getAttributes(), $b->getAttributes(), $c->fresh()->getAttributes(), $pool->getAttributes()];
        $this->postJson('/_tests/batch-reality', $this->payload([$a, $b], $settings, [$pool->id]))->assertUnprocessable()->assertJsonValidationErrors('ids');
        $this->assertSame($before, [$a->fresh()->getAttributes(), $b->fresh()->getAttributes(), $c->fresh()->getAttributes(), $pool->fresh()->getAttributes()]);
    }

    public function test_unselected_low_id_is_not_confused_with_a_collection_position(): void
    {
        $unselected = $this->node(1); $a = $this->node(2); $b = $this->node(3);
        $pool = $this->pool([$unselected, $a, $b], $this->settings());
        $before = [$unselected->getAttributes(), $a->getAttributes(), $b->getAttributes(), $pool->getAttributes()];
        $this->postJson('/_tests/batch-reality', $this->payload([$a, $b], $this->settings(), [$pool->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('ids');
        $this->assertSame($before, [$unselected->fresh()->getAttributes(), $a->fresh()->getAttributes(), $b->fresh()->getAttributes(), $pool->fresh()->getAttributes()]);
    }

    public function test_pool_edit_lock_blocks_linked_save_and_allows_retry_after_release(): void
    {
        $a = $this->node(1); $b = $this->node(2);
        $pool = $this->pool([$a, $b], $this->settings());
        $settings = $this->settings();
        $before = [$a->getAttributes(), $b->getAttributes(), $pool->getAttributes()];
        $lock = Cache::lock('balancer:dns:' . $pool->id, 120);
        $this->assertTrue($lock->get());
        try {
            $this->postJson('/_tests/batch-reality', $this->payload([$a, $b], $settings, [$pool->id]))
                ->assertUnprocessable()->assertJsonValidationErrors('pool_ids');
            $this->assertSame($before, [$a->fresh()->getAttributes(), $b->fresh()->getAttributes(), $pool->fresh()->getAttributes()]);
        } finally { $lock->release(); }
        $this->postJson('/_tests/batch-reality', $this->payload([$a, $b], $settings, [$pool->id]))->assertOk()->assertJsonPath('data.pools_updated', 1);
    }
}
