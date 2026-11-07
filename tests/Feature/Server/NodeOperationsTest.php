<?php

namespace Tests\Feature\Server;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use App\Models\Server;
use App\Models\User;
use App\Models\Plan;
use App\Models\NodeReportBatch;
use App\Services\NodeOperations;
use App\Services\ServerService;
use App\Services\AutoSpeedRule;
use App\Http\Controllers\V2\Admin\Server\OperationsController;
use App\Http\Controllers\V2\Admin\PlanController;
use App\Http\Controllers\V2\Admin\UserController;
use App\Http\Controllers\V2\Admin\TrafficReconciliationController;

class NodeOperationsTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Queue::fake();
        Redis::shouldReceive('publish')->andReturn(1);
        Route::middleware('admin')->group(function () {
            Route::get('/_ops/detail', [OperationsController::class,'detail']);
            Route::get('/_ops/certificates', [OperationsController::class,'certificates']);
            Route::get('/_ops/topology', [OperationsController::class,'topology']);
            Route::post('/_ops/health', [OperationsController::class,'health']);
            Route::post('/_ops/renew', [OperationsController::class,'renew']);
            Route::post('/_ops/plan', [PlanController::class,'save']);
            Route::post('/_ops/user', [UserController::class,'update']);
            Route::get('/_ops/ledger', [TrafficReconciliationController::class,'fetch']);
        });
    }
    private function node(): Server
    {
        return Server::create(['name' => 'ops','type' => 'vmess','host' => '127.0.0.1','port' => 24443,'server_port' => 24443,'enabled' => true,'group_ids' => [1],'rate' => 1,'health_check' => ['enabled' => true,'url' => 'https://example.com/','interval' => 60],'cert_config' => ['cert_mode' => 'dns','domain' => 'example.com','dns_env' => ['CF_TOKEN' => 'test-secret-not-for-response']]]);
    }
    private function admin(): void
    {
        $this->actingAs(User::create(['email' => 'ops-admin@example.invalid','password' => 'test-only','uuid' => bin2hex(random_bytes(16)),'token' => bin2hex(random_bytes(16)),'is_admin' => true]), 'sanctum');
    }
    private function state(): array
    {
        return ['run'=>str_repeat('a',32),'sequence'=>2,'version' => 1,'sampled_at' => now()->getTimestampMs(),'runtime' => 'running','config_hash' => str_repeat('a', 64),'pending_bytes' => 120,'health' => ['id' => 'sample-1','checked_at' => now()->timestamp,'status' => 'ok','target' => 'https://example.com/','source' => '节点本机','steps' => [],'routes' => [['tag' => '','status' => 'ok','latency_ms' => 11]]],'certificate' => ['domain' => 'example.com','present' => true,'applied' => true,'mode' => 'dns','fingerprint' => str_repeat('b', 64),'not_after' => now()->addDays(20)->timestamp]];
    }
    public function test_admin_only_and_health_config_validation(): void
    {
        $n = $this->node();
        $this->getJson('/_ops/detail?node_id='.$n->id)->assertForbidden();
        $this->admin();
        $this->postJson('/_ops/health', ['node_id' => $n->id,'enabled' => true,'url' => 'http://example.com','interval' => 60])->assertUnprocessable();
        $this->postJson('/_ops/health', ['node_id' => $n->id,'enabled' => true,'url' => 'https://example.com/?token=secret','interval' => 60])->assertUnprocessable();
        $this->postJson('/_ops/health', ['node_id' => $n->id,'enabled' => true,'url' => 'https://example.com/','interval' => 60])->assertOk();
    }
    public function test_stale_duplicate_and_malformed_reports_do_not_replace_current(): void
    {
        $n = $this->node();
        $s = $this->state();
        $ops = app(NodeOperations::class);
        $ops->accept($n, $s);
        $ops->accept($n, $s);
        $this->assertSame(1, DB::table('v2_node_health_sample')->count());
        $old = $s;
        $old['sampled_at']--;
        $old['sequence']=1;
        $old['runtime'] = 'failed';
        $ops->accept($n, $old);
        $this->assertSame('running', $ops->view($n)['state']['runtime']);
        $bad = $s;
        $bad['pending_bytes'] = -1;
        $bad['sampled_at']++;
        $ops->accept($n, $bad);
        $this->assertSame(120, $ops->view($n)['state']['pending_bytes']);
        $this->travel(100)->seconds();
        $this->assertFalse($ops->view($n)['fresh']);
    }
    public function test_operation_order_survives_clock_changes_and_rejects_retired_runs(): void
    {
        $node=$this->node();$ops=app(NodeOperations::class);$state=$this->state();
        $ops->accept($node,$state);$new=$state;$new['sequence']=3;$new['sampled_at']-=3600000;$new['runtime']='failed';
        $ops->accept($node,$new);$this->assertSame('failed',$ops->view($node)['state']['runtime']);
        $restart=$state;$restart['run']=str_repeat('b',32);$restart['sequence']=1;$ops->accept($node,$restart);
        $new['sequence']=100;$ops->accept($node,$new);$this->assertSame(str_repeat('b',32),$ops->view($node)['state']['run']);
    }

    public function test_certificates_and_topology_never_return_private_settings(): void
    {
        $n = $this->node();
        app(NodeOperations::class)->accept($n, $this->state());
        $this->admin();
        foreach (['certificates','topology'] as $path) {
            $response = $this->getJson('/_ops/'.$path)->assertOk();
            $this->assertStringNotContainsString('test-secret', $response->getContent());
            $this->assertStringNotContainsString('dns_env', $response->getContent());
        }$this->postJson('/_ops/renew', ['node_id' => $n->id])->assertOk();
        $this->assertNotEmpty($n->fresh()->certificate_renew);
        $this->postJson('/_ops/renew', ['node_id' => $n->id])->assertUnprocessable();
    }
    public function test_renewal_requires_fresh_supported_agent(): void
    {
        $n = $this->node();
        $this->admin();
        $this->postJson('/_ops/renew', ['node_id' => $n->id])->assertUnprocessable();
    }
    private function rule(): array
    {
        return ['enabled' => true,'mode' => 'continuous','direction' => 'download','threshold_mbps' => 50,'limit_mbps' => 10,'duration_seconds' => 30,'window_seconds' => 60,'hits' => 6,'hold_seconds' => 600];
    }
    public function test_plan_rule_sync_and_node_serialization(): void
    {
        $this->admin();
        $p = Plan::create(['name' => 'ops-plan','group_ids' => [1],'speed_limit' => 100,'auto_speed_limit' => $this->rule()]);
        $u = User::create(['email' => 'user@example.invalid','password' => 'test-only','uuid' => bin2hex(random_bytes(16)),'token' => bin2hex(random_bytes(16)),'plan_id' => $p->id,'group_id' => 1,'transfer_enable' => 100000,'expired_at' => null,'banned' => false,'u' => 0,'d' => 0,'speed_limit' => 100]);
        $node = $this->node();
        $rule = $this->rule();
        $rule['limit_mbps'] = 5;
        $this->postJson('/_ops/plan', ['id' => $p->id,'name' => 'ops-plan','group_id' => 1,'transfer_enable' => 100,'speed_limit' => 100,'auto_speed_limit' => $rule,'force_update' => true])->assertOk();
        $this->assertSame(5, $u->fresh()->auto_speed_limit['limit_mbps']);
        $wire = ServerService::getAvailableUsers($node)->firstWhere('id', $u->id);
        $this->assertIsArray($wire->auto_speed_limit);
        $this->assertSame(5, $wire->auto_speed_limit['limit_mbps']);
        $rule['limit_mbps'] = 80;
        $this->postJson('/_ops/plan', ['id' => $p->id,'name' => 'ops-plan','group_id' => 1,'transfer_enable' => 100,'speed_limit' => 100,'auto_speed_limit' => $rule])->assertUnprocessable();
    }
    public function test_reconciliation_keeps_billing_separate_and_reads_without_mutation(): void
    {
        $n = $this->node();
        $this->admin();
        DB::table('v2_stat_user_server')->insert(['server_id' => $n->id,'user_id' => 1,'kind' => 'entry','record_at' => now()->startOfDay()->timestamp,'u' => 100,'d' => 200,'billed_u' => 150,'billed_d' => 300]);
        $this->getJson('/_ops/ledger?period=today')->assertOk()->assertJsonPath('data.list.0.actual', 300)->assertJsonPath('data.list.0.billed', 450)->assertJsonPath('data.list.0.agent_pending_bytes', null);
        $this->assertSame(300,(int)DB::table('v2_stat_user_server')->selectRaw('SUM(u+d) total')->value('total'));
    }

    public function test_admin_plan_change_preserves_rule_object_through_user_sync(): void
    {
        $this->admin();
        $plan = Plan::create(['name' => 'target', 'group_ids' => [1], 'speed_limit' => 100,
            'transfer_enable' => 10, 'auto_speed_limit' => $this->rule()]);
        $user = User::create(['email' => 'switch@example.invalid', 'password' => 'test-only',
            'uuid' => bin2hex(random_bytes(16)), 'token' => bin2hex(random_bytes(16)),
            'expired_at' => null, 'banned' => false, 'u' => 0, 'd' => 0]);
        $node = $this->node();
        $this->postJson('/_ops/user', ['id' => $user->id, 'plan_id' => $plan->id])->assertOk();
        $this->assertSame($this->rule(), $user->fresh()->auto_speed_limit);
        $stored = json_decode(DB::table('v2_user')->where('id', $user->id)->value('auto_speed_limit'), true);
        $this->assertSame($this->rule(), $stored);
        $wire = ServerService::getUsersForSync($node)->firstWhere('id', $user->id);
        $decoded = json_decode(json_encode($wire), true);
        $this->assertSame($this->rule(), $decoded['auto_speed_limit']);
    }

    public function test_batch_views_reuse_parent_cache_and_keep_missing_states_unknown(): void
    {
        $parent = (new Server())->forceFill(['id' => 100, 'health_check' => ['enabled' => true]]);
        $child = (new Server())->forceFill(['id' => 101, 'parent_id' => 100]);
        $missing = (new Server())->forceFill(['id' => 102]);
        $state = $this->state() + ['received_at' => now()->timestamp];
        Cache::shouldReceive('many')->once()->with(['node:operations:100', 'node:operations:102'])
            ->andReturn(['node:operations:100' => $state, 'node:operations:102' => null]);
        Cache::shouldNotReceive('get');
        $views = app(NodeOperations::class)->views([$parent, $child, $missing]);
        $this->assertTrue($views[100]['fresh']);
        $this->assertSame($views[100]['state'], $views[101]['state']);
        $this->assertFalse($views[102]['supported']);
        $this->assertFalse($views[102]['fresh']);
    }

    public function test_incomplete_probes_are_accepted_without_counting_as_outages(): void
    {
        $node = $this->node();
        $state = $this->state();
        $state['health']['status'] = 'partial';
        $state['health']['routes'][0]['status'] = 'incomplete';
        $state['health']['routes'][0]['error'] = '本轮检查未完成，请重试';
        $ops = app(NodeOperations::class);
        $ops->accept($node, $state);
        $detail = $ops->detail($node);
        $this->assertSame('incomplete', $detail['state']['health']['routes'][0]['status']);
        $this->assertSame(0, $detail['samples']);
        $this->assertNull($detail['availability']);
        $this->assertSame(1, $detail['history']->count());
    }

    public function test_duplicate_metrics_are_projected_once_and_legacy_metrics_still_work(): void
    {
        $node = $this->node();
        $store = new class extends \Illuminate\Cache\ArrayStore {
            public int $operationLocks = 0;
            public function lock($name, $seconds = 0, $owner = null)
            {
                if (str_starts_with($name, 'node:operations:lock:')) $this->operationLocks++;
                return parent::lock($name, $seconds, $owner);
            }
        };
        Cache::extend('operation-test', fn () => Cache::repository($store));
        config(['cache.stores.operation-test' => ['driver' => 'operation-test'], 'cache.default' => 'operation-test']);
        $state = $this->state();
        \App\Services\NodeStateService::apply($node, ['operations' => $state, 'metrics' => ['operations' => $state]]);
        $this->assertSame(1, $store->operationLocks);
        $state['sequence']++;
        \App\Services\NodeStateService::apply($node, ['metrics' => ['operations' => $state]]);
        $this->assertSame(2, $store->operationLocks);
        $this->assertSame(3, app(NodeOperations::class)->view($node)['state']['sequence']);
    }

    public function test_reconciliation_filters_before_paging_and_aggregates_only_page_traffic(): void
    {
        $this->admin();
        $nodes = [$this->node(), $this->node(), $this->node()];
        $nodes[2]->update(['type' => 'vless', 'relay_entry_id' => $nodes[0]->id]);
        $state = $this->state();
        $state['pending_bytes'] = 0;
        foreach (array_slice($nodes, 0, 2) as $node) app(NodeOperations::class)->accept($node, $state);
        foreach ($nodes as $i => $node) {
            $node->update(['name' => 'match-'.$i]);
            DB::table('v2_stat_user_server')->insert(['server_id' => $node->id, 'user_id' => 1, 'kind' => 'entry',
                'record_at' => now()->startOfDay()->timestamp, 'u' => 100 * ($i + 1), 'd' => 0,
                'billed_u' => 200 * ($i + 1), 'billed_d' => 0]);
            if ($i > 0) NodeReportBatch::create(['server_id' => $node->id, 'report_key' => hash('sha256', 'batch-'.$i),
                'report_id' => 'batch-'.$i, 'server_type' => 'vmess', 'server_snapshot' => [], 'traffic' => [],
                'relay_traffic' => [], 'record_at' => now()->timestamp, 'status' => $i === 1 ? 'pending' : 'failed', 'attempts' => 0]);
        }
        DB::connection()->enableQueryLog();
        $this->getJson('/_ops/ledger?period=today&search=match&only_issues=1&page_size=1&page=2')->assertOk()
            ->assertJsonPath('data.total', 2)->assertJsonPath('data.last_page', 2)
            ->assertJsonPath('data.list.0.id', $nodes[2]->id)->assertJsonPath('data.list.0.actual', 300)
            ->assertJsonPath('data.list.0.billed', 600)->assertJsonPath('data.list.0.agent_pending_bytes', null)
            ->assertJsonPath('data.list.0.relay', true)
            ->assertJsonPath('data.summary.pending', 1)->assertJsonPath('data.summary.failed', 1)
            ->assertJsonPath('data.summary.unknown', 1);
        $trafficQueries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'SUM(u+d)'));
        $this->assertCount(1, $trafficQueries);
        $this->assertSame($nodes[2]->id, $trafficQueries->first()['bindings'][0]);
        DB::disableQueryLog();
        $this->getJson('/_ops/ledger?period=today&search=absent')->assertOk()
            ->assertJsonPath('data.list', [])->assertJsonPath('data.total', 0);
    }
}
