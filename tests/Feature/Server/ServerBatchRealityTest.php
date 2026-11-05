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

    private function payload(array $nodes, array $settings): array
    {
        return ['ids' => array_map(fn ($node) => $node->id, $nodes), 'mode' => 'manual', 'reality_settings' => $settings];
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

    public function test_pool_template_conflicts_are_rejected_but_matching_settings_are_allowed(): void
    {
        $a = $this->node(1); $b = $this->node(2);
        $settings = $this->settings();
        $pool = BalancerPool::create(['name' => '测试池', 'mode' => 'dns', 'protocol' => 'vless',
            'domain' => 'pool.example.test', 'gateway_machine_id' => $a->machine_id, 'gateway_address' => '192.0.2.1',
            'backend_port' => 24443, 'template_node_id' => $a->id,
            'template_config' => ['type' => 'vless', 'kernel_type' => 'xray', 'reality_sync_enabled' => true, 'protocol_settings' => ['tls' => 2,
                'network' => 'tcp', 'flow' => 'xtls-rprx-vision', 'reality_settings' => $settings]],
            'gateway_port' => 30443, 'enabled' => true])->refresh();
        BalancerMember::create(['pool_id' => $pool->id, 'source_node_id' => $a->id, 'runtime_node_id' => $a->id]);
        $before = [$a->getAttributes(), $b->getAttributes(), $pool->getAttributes()];
        $this->postJson('/_tests/batch-reality', $this->payload([$a, $b], $this->settings()))->assertUnprocessable()->assertJsonValidationErrors('ids');
        $this->assertSame($before, [$a->fresh()->getAttributes(), $b->fresh()->getAttributes(), $pool->fresh()->getAttributes()]);
        $this->postJson('/_tests/batch-reality', $this->payload([$a, $b], $settings))->assertOk();
        $this->assertSame([], LoadBalancerService::memberCompatibility($pool, $a->fresh()));
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
        $user = User::create(['email' => 'test-batch-reality@example.invalid', 'password' => password_hash('test-only', PASSWORD_DEFAULT),
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'token' => bin2hex(random_bytes(16)), 'is_admin' => false]);
        Sanctum::actingAs($user);
        $this->postJson($url, $payload)->assertForbidden();
        $user->update(['is_admin' => true]);
        Sanctum::actingAs($user->fresh());
        $this->postJson($url, $payload)->assertOk();
        $audit = AdminAuditLog::firstOrFail();
        $this->assertEquals(['ids' => [$a->id, $b->id]], json_decode($audit->getRawOriginal('request_data'), true));
    }
}
