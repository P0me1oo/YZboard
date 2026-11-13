<?php

namespace Tests\Feature\Server;

use App\Http\Controllers\V2\Admin\Server\OperationsController;
use App\Models\BalancerMember;
use App\Models\BalancerPool;
use App\Models\Server;
use App\Models\User;
use App\Services\NodeHealthConfig;
use App\Services\NodeHealthProjection;
use App\Services\NodeOperations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class NodeHealthAttributionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        admin_setting([NodeHealthConfig::SETTING => ['enabled' => true, 'url' => 'https://example.com/', 'interval' => 60]]);
        Queue::fake();
        Redis::shouldReceive('publish')->byDefault()->andReturn(1);
        Route::middleware('admin')->group(function () {
            Route::get('/_attribution/fetch', [OperationsController::class, 'fetch']);
            Route::get('/_attribution/detail', [OperationsController::class, 'detail']);
            Route::get('/_attribution/topology', [OperationsController::class, 'topology']);
            Route::post('/_attribution/check', [OperationsController::class, 'check']);
        });
        $this->actingAs(User::create(['email' => 'health-admin@example.invalid', 'password' => 'test-only',
            'uuid' => bin2hex(random_bytes(16)), 'token' => bin2hex(random_bytes(16)), 'is_admin' => true]), 'sanctum');
    }

    private function node(string $name, array $attributes = []): Server
    {
        return Server::create($attributes + ['name' => $name, 'type' => 'vless', 'host' => 'example.com',
            'port' => 443, 'server_port' => 443, 'enabled' => true, 'group_ids' => [1], 'rate' => 1]);
    }

    private function state(array $relays = [], int $sequence = 1): array
    {
        $routes = [['tag' => '', 'status' => 'ok', 'latency_ms' => 23]];
        foreach ($relays as $id => $status) {
            $routes[] = ['tag' => 'relay-'.$id, 'node_id' => $id, 'status' => $status, 'latency_ms' => 50,
                'error' => $status === 'failed' ? '代理出站或 TLS 请求失败' : null];
        }
        return ['run' => str_repeat('a', 32), 'sequence' => $sequence, 'version' => 1,
            'sampled_at' => now()->getTimestampMs(), 'runtime' => 'running', 'config_hash' => str_repeat('b', 64),
            'pending_bytes' => 0, 'health' => ['id' => 'sample-'.$sequence, 'checked_at' => now()->timestamp,
                'status' => in_array('failed', $relays, true) ? 'failed' : 'ok', 'target' => 'https://example.com/',
                'source' => '节点本机', 'steps' => [['name' => 'listener', 'status' => 'ok', 'latency_ms' => 0]], 'routes' => $routes]];
    }

    public function test_failures_belong_to_the_landing_and_never_the_entry_or_other_landings(): void
    {
        $entry = $this->node('xtom');
        $bad = $this->node('落地 A', ['relay_entry_id' => $entry->id]);
        $good = $this->node('落地 B', ['relay_entry_id' => $entry->id]);
        $ops = app(NodeOperations::class);
        $ops->accept($entry, $this->state([$bad->id => 'failed', $good->id => 'ok']));
        foreach ([$bad, $good] as $node) $ops->accept($node, $this->state());

        $this->getJson('/_attribution/fetch?ids[]='.$entry->id)->assertOk()
            ->assertJsonPath('data.0.state.health.status', 'ok')->assertJsonCount(1, 'data.0.state.health.routes')
            ->assertJsonPath('data.0.relay_health', []);
        $this->getJson('/_attribution/detail?node_id='.$bad->id)->assertOk()
            ->assertJsonPath('data.relay_health.0.source_node_id', $entry->id)
            ->assertJsonPath('data.relay_health.0.source_name', 'xtom')
            ->assertJsonPath('data.relay_health.0.status', 'failed');
        $this->assertSame('ok', $ops->view($good)['relay_health'][0]['status']);
        $topology = $this->getJson('/_attribution/topology')->assertOk()->json('data.nodes');
        $this->assertSame('ok', collect($topology)->firstWhere('node_id', $entry->id)['operations']['state']['health']['status']);
        $this->assertSame('failed', collect($topology)->firstWhere('node_id', $bad->id)['operations']['relay_health'][0]['status']);
        $this->assertCount(3, Cache::get('node:operations:'.$entry->id)['health']['routes']);
    }

    public function test_current_and_old_history_use_only_local_health_without_rewriting_old_rows(): void
    {
        $entry = $this->node('entry');
        $ops = app(NodeOperations::class);
        $ops->accept($entry, $this->state([123 => 'failed']));
        $ops->accept($entry, $this->state([123 => 'failed']));
        $row = DB::table('v2_node_health_sample')->where('server_id', $entry->id)->first();
        $this->assertSame('ok', $row->status);
        $this->assertSame('failed', json_decode($row->detail, true)['status']);
        DB::table('v2_node_health_sample')->where('id', $row->id)->update(['status' => 'failed']);
        $detail = $ops->detail($entry);
        $this->assertSame(1, $detail['samples']);
        $this->assertEquals(100, $detail['availability']);
        $this->assertSame('ok', $detail['history'][0]->status);
        $this->assertSame(23, $detail['history'][0]->latency_ms);
        $this->assertObjectNotHasProperty('detail', $detail['history'][0]);
        $this->assertSame('failed', DB::table('v2_node_health_sample')->where('id', $row->id)->value('status'));
    }

    public function test_local_failures_and_unfinished_checks_are_not_hidden_by_relay_filtering(): void
    {
        $raw = $this->state([123 => 'failed'])['health'];
        foreach (['listener', 'target'] as $name) {
            $health = $raw;
            $health['steps'][] = ['name' => $name, 'status' => 'failed', 'latency_ms' => 0];
            $this->assertSame('failed', NodeHealthProjection::local($health)['status']);
        }
        foreach (['failed' => 'failed', 'incomplete' => 'partial'] as $status => $expected) {
            $health = $raw;
            $health['routes'][0]['status'] = $status;
            $this->assertSame($expected, NodeHealthProjection::local($health)['status']);
        }
        $raw['status'] = 'partial';
        $raw['steps'][] = ['name' => 'routes', 'status' => 'not_supported', 'latency_ms' => 0];
        $this->assertSame('ok', NodeHealthProjection::local($raw)['status']);
        $this->assertCount(1, NodeHealthProjection::local($raw)['steps']);
        array_shift($raw['routes']);
        $this->assertSame('partial', NodeHealthProjection::local($raw)['status']);
    }

    public function test_pool_routes_are_grouped_by_landing_and_removed_bindings_are_not_reused(): void
    {
        $a = $this->node('前置 A');
        $b = $this->node('前置 B');
        $outsider = $this->node('无关前置');
        $pool = BalancerPool::create(['name' => 'pool', 'domain' => 'pool.example.invalid', 'enabled' => true,
            'mode' => 'independent', 'gateway_machine_id' => 0, 'gateway_address' => '', 'gateway_port' => 443,
            'backend_port' => 443, 'protocol' => 'vless', 'template_node_id' => $a->id, 'template_config' => []]);
        $member = BalancerMember::create(['pool_id' => $pool->id, 'source_node_id' => $a->id, 'runtime_node_id' => $a->id]);
        BalancerMember::create(['pool_id' => $pool->id, 'source_node_id' => $b->id, 'runtime_node_id' => $b->id]);
        $landing = $this->node('landing', ['relay_pool_id' => $pool->id]);
        $ops = app(NodeOperations::class);
        foreach ([$a, $outsider] as $source) $ops->accept($source, $this->state([$landing->id => 'failed']));
        $ops->accept($b, $this->state([$landing->id => 'ok']));
        $ops->accept($landing, $this->state());
        $view = $ops->view($landing);
        $this->assertSame([$a->id, $b->id], array_column($view['relay_health'], 'source_node_id'));
        $this->assertSame(['failed', 'ok'], array_column($view['relay_health'], 'status'));
        $member->delete();
        $this->assertSame([$b->id], array_column($ops->view($landing)['relay_health'], 'source_node_id'));
        $pool->update(['enabled' => false]);
        $this->assertSame([], $ops->view($landing)['relay_health']);
    }

    public function test_shared_landing_uses_owner_routes_and_rebinding_discards_old_source(): void
    {
        $old = $this->node('old');
        $new = $this->node('new');
        $landing = $this->node('landing', ['relay_entry_id' => $old->id]);
        $alias = $this->node('alias', ['parent_id' => $landing->id]);
        $ops = app(NodeOperations::class);
        $ops->accept($old, $this->state([$landing->id => 'failed']));
        $ops->accept($new, $this->state([$landing->id => 'ok']));
        $ops->accept($landing, $this->state());
        $this->assertSame('failed', $ops->view($alias)['relay_health'][0]['status']);
        $landing->update(['relay_entry_id' => $new->id]);
        $this->assertSame($new->id, $ops->view($alias)['relay_health'][0]['source_node_id']);
        $this->assertSame('ok', $ops->view($alias)['relay_health'][0]['status']);
        $new->update(['enabled' => false]);
        $this->assertSame([], $ops->view($alias)['relay_health']);
    }

    public function test_old_disabled_stopped_missing_and_wrong_target_reports_stay_unknown(): void
    {
        $entry = $this->node('entry');
        $landing = $this->node('landing', ['relay_entry_id' => $entry->id]);
        $ops = app(NodeOperations::class);
        $ops->accept($landing, $this->state());
        $state = $this->state([$landing->id => 'failed']) + ['received_at' => now()->timestamp];
        foreach (['received_at', 'checked_at', 'target', 'runtime', 'missing'] as $kind) {
            $bad = $state;
            if ($kind === 'received_at') $bad['received_at'] -= 91;
            if ($kind === 'checked_at') $bad['health']['checked_at'] -= 121;
            if ($kind === 'target') $bad['health']['target'] = 'https://other.example/';
            if ($kind === 'runtime') $bad['runtime'] = 'stopped';
            if ($kind === 'missing') array_pop($bad['health']['routes']);
            Cache::put('node:operations:'.$entry->id, $bad);
            $route = $ops->view($landing)['relay_health'][0];
            $this->assertSame('incomplete', $route['status'], $kind);
            $this->assertNull($route['latency_ms']);
        }
        Cache::put('node:operations:'.$entry->id, $state);
        $entry->update(['health_check' => ['mode' => 'off']]);
        $this->assertSame('incomplete', $ops->view($landing)['relay_health'][0]['status']);
        $landing->update(['health_check' => ['mode' => 'off']]);
        $this->assertSame([], $ops->view($landing)['relay_health']);
    }

    public function test_manual_landing_check_refreshes_source_once_and_rolls_back_on_failure(): void
    {
        $entry = $this->node('entry');
        $landing = $this->node('landing', ['relay_entry_id' => $entry->id]);
        $ops = app(NodeOperations::class);
        $ops->accept($entry, $this->state([$landing->id => 'failed']));
        $ops->accept($landing, $this->state());
        $url = '/_attribution/check';
        $this->postJson($url, ['node_id' => $landing->id])->assertOk();
        $request = $entry->fresh()->health_check['request'];
        $ownRequest = $landing->fresh()->health_check['request'];
        $this->assertNotEmpty($request);
        $this->postJson($url, ['node_id' => $landing->id])->assertOk();
        $this->assertSame($request, $entry->fresh()->health_check['request']);
        Cache::forget('node:health:request:'.$landing->id);
        $fail = true;
        DB::connection()->beforeExecuting(function ($sql, $bindings, $connection) use ($entry, &$fail) {
            if ($fail && preg_match('/update .*v2_server/i', $sql) && (int) end($bindings) === (int) $entry->id) {
                $fail = false;
                throw new \Illuminate\Database\QueryException($connection->getName(), $sql, $bindings,
                    new \RuntimeException('前置请求保存失败'));
            }
        });
        $this->postJson($url, ['node_id' => $landing->id])->assertStatus(500);
        $this->assertSame($request, $entry->fresh()->health_check['request']);
        $this->assertSame($ownRequest, $landing->fresh()->health_check['request']);
        $this->postJson($url, ['node_id' => $landing->id])->assertOk();
        $this->assertNotSame($request, $entry->fresh()->health_check['request']);
    }
}
