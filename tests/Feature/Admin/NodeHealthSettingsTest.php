<?php

namespace Tests\Feature\Admin;

use App\Http\Controllers\V2\Admin\ConfigController;
use App\Http\Controllers\V2\Admin\Server\ManageController;
use App\Http\Controllers\V2\Admin\Server\OperationsController;
use App\Models\Server;
use App\Services\NodeHealthConfig;
use App\Services\NodeOperations;
use App\Services\ServerService;
use App\Support\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class NodeHealthSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Queue::fake();
        Redis::shouldReceive('publish')->byDefault()->andReturn(1);
        Route::get('/_health/config', [ConfigController::class, 'fetch']);
        Route::post('/_health/config', [ConfigController::class, 'save']);
        Route::post('/_health/node', [OperationsController::class, 'health']);
        Route::post('/_health/check', [OperationsController::class, 'check']);
        Route::post('/_health/save', [ManageController::class, 'save']);
    }

    private function node(?array $health = null): Server
    {
        return Server::create(['name' => 'health-test', 'type' => 'vmess', 'host' => '127.0.0.1',
            'port' => 24443, 'server_port' => 24443, 'enabled' => true, 'group_ids' => [1], 'rate' => 1,
            'health_check' => $health]);
    }

    public function test_defaults_legacy_overrides_and_all_three_modes_follow_global_target(): void
    {
        $this->getJson('/_health/config?key=server')->assertOk()
            ->assertJsonPath('data.server.node_health_check', NodeHealthConfig::defaults());
        $cases = [[null, 'inherit'], [[], 'inherit'], [['mode' => 'inherit'], 'inherit'],
            [['mode' => 'on'], 'on'], [['mode' => 'off'], 'off'],
            [['enabled' => true, 'url' => 'https://legacy.example/', 'interval' => 120], 'on'],
            [['enabled' => false], 'off']];
        foreach ($cases as [$raw, $mode]) {
            $node = $this->node($raw);
            foreach ([false, true] as $globalEnabled) {
                $config = ['enabled' => $globalEnabled, 'url' => 'https://example.com/health', 'interval' => 90];
                $this->postJson('/_health/config', ['node_health_check' => $config])->assertOk();
                $this->app->forgetInstance(Setting::class);
                $this->assertSame($config, NodeHealthConfig::global());
                $effective = array_replace($config, ['enabled' => $mode === 'inherit' ? $globalEnabled : $mode === 'on']);
                $this->assertSame($effective, ServerService::buildNodeConfig($node)['health_check']);
                $view = app(NodeOperations::class)->view($node);
                $this->assertSame($mode, $view['health_mode']);
                $this->assertSame($effective, $view['health_config']);
                $this->assertSame($effective['enabled'], $view['enabled']);
            }
            $this->assertSame($raw, $node->fresh()->health_check);
        }
    }

    public function test_global_validation_is_atomic_and_unrelated_settings_keep_health(): void
    {
        $config = ['enabled' => true, 'url' => 'https://example.com/', 'interval' => 30];
        $this->postJson('/_health/config', ['node_health_check' => $config])->assertOk();
        foreach ([null, [], ['enabled' => false], $config + ['request' => 'invalid'],
            array_replace($config, ['enabled' => 'on']), array_replace($config, ['interval' => 29]),
            array_replace($config, ['interval' => 3601]), array_replace($config, ['interval' => 60.5])] as $invalid) {
            $this->postJson('/_health/config', ['node_health_check' => $invalid])->assertUnprocessable();
            $this->assertSame($config, NodeHealthConfig::global());
        }
        foreach (['http://example.com/', 'https://user:pass@example.com/', 'https://example.com/?key=x',
            'https://example.com/#fragment', '', 'https://example.com/'.str_repeat('x', 500)] as $url) {
            $this->postJson('/_health/config', ['node_health_check' => array_replace($config, ['enabled' => false, 'url' => $url])])
                ->assertUnprocessable()->assertJsonValidationErrors('node_health_check.url');
            $this->assertSame($config, NodeHealthConfig::global());
        }
        $this->postJson('/_health/config', ['server_pull_interval' => 45])->assertOk();
        $this->assertSame($config, NodeHealthConfig::global());
        $this->failNextQueryMatching('/update .*v2_settings/i');
        $this->postJson('/_health/config', ['node_health_check' => array_replace($config, ['interval' => 120])])->assertStatus(500);
        $this->app->forgetInstance(Setting::class);
        $this->assertSame($config, NodeHealthConfig::global());
        $this->postJson('/_health/config', ['node_health_check' => array_replace($config, ['interval' => 3600])])->assertOk();
        $this->assertSame(3600, NodeHealthConfig::global()['interval']);
    }

    public function test_global_change_pushes_resolved_config_once_and_unchanged_save_does_not_push(): void
    {
        $inherit = $this->node();
        $off = $this->node(['mode' => 'off']);
        foreach ([$inherit, $off] as $node) Cache::put('node_ws_alive:'.$node->id, true, 90);
        $pushes = [];
        Redis::shouldReceive('publish')->andReturnUsing(function ($channel, $message) use (&$pushes) {
            $pushes[] = json_decode($message, true);
            return 1;
        });
        $config = ['enabled' => true, 'url' => 'https://example.com/check', 'interval' => 120];
        $this->postJson('/_health/config', ['node_health_check' => $config])->assertOk();
        $this->assertCount(2, $pushes);
        $this->assertSame($config, $pushes[0]['data']['config']['health_check']);
        $this->assertFalse($pushes[1]['data']['config']['health_check']['enabled']);
        $this->postJson('/_health/config', ['node_health_check' => ['enabled' => 1, 'url' => $config['url'], 'interval' => '120']])->assertOk();
        $this->postJson('/_health/config', ['server_pull_interval' => 45])->assertOk();
        $this->assertCount(2, $pushes);
        $config['enabled'] = false;
        $this->postJson('/_health/config', ['node_health_check' => $config])->assertOk();
        $this->assertCount(4, $pushes);
        $this->assertFalse($pushes[2]['data']['config']['health_check']['enabled']);
    }

    public function test_inherit_rechecks_only_when_enabled_and_shared_nodes_follow_parent(): void
    {
        $parent = $this->node();
        $child = $this->node(['mode' => 'off']);
        $child->update(['parent_id' => $parent->id]);
        foreach ([false, true, false] as $enabled) {
            admin_setting(['node_health_check' => array_replace(NodeHealthConfig::defaults(), ['enabled' => $enabled])]);
            $this->postJson('/_health/node', ['node_id' => $parent->id, 'mode' => 'inherit'])->assertOk();
            $parent->refresh();
            $this->assertSame($enabled, isset($parent->health_check['request']));
            $view = app(NodeOperations::class)->view($child);
            $this->assertSame($enabled, $view['enabled']);
            $this->assertSame('inherit', $view['health_mode']);
            $this->assertSame(NodeHealthConfig::effective($parent), $view['health_config']);
        }
        $this->postJson('/_health/node', ['node_id' => $child->id, 'mode' => 'on'])->assertUnprocessable();
        $this->postJson('/_health/node', ['node_id' => $parent->id, 'mode' => 'on', 'url' => 'https://ignored.example/', 'interval' => 999])->assertOk();
        $this->assertArrayNotHasKey('url', $parent->fresh()->health_check);
        $this->assertSame(NodeHealthConfig::defaults()['url'], NodeHealthConfig::effective($parent->fresh())['url']);
    }

    public function test_global_target_change_rejects_old_reports_and_hides_cached_results(): void
    {
        $node = $this->node();
        $config = ['enabled' => true, 'url' => 'https://example.com/', 'interval' => 60];
        admin_setting(['node_health_check' => $config]);
        $ops = app(NodeOperations::class);
        $state = ['run' => str_repeat('a', 32), 'sequence' => 1, 'version' => 1, 'sampled_at' => now()->getTimestampMs(),
            'runtime' => 'running', 'config_hash' => str_repeat('b', 64), 'pending_bytes' => 0,
            'health' => ['id' => 'first', 'checked_at' => now()->timestamp, 'status' => 'ok', 'target' => $config['url'], 'source' => 'node']];
        $ops->accept($node, $state);
        $this->assertSame(1, $ops->detail($node)['samples']);
        $this->postJson('/_health/check', ['node_id' => $node->id])->assertOk();
        $this->assertNotEmpty($node->fresh()->health_check['request']);
        admin_setting(['node_health_check' => array_replace($config, ['url' => 'https://example.com/new'])]);
        $this->assertArrayNotHasKey('health', $ops->view($node)['state']);
        $state['sequence']++;
        $state['health']['id'] = 'late-old-target';
        $ops->accept($node, $state);
        $this->assertSame(1, DB::table('v2_node_health_sample')->count());
        $state['sequence']++;
        $state['health']['id'] = 'new-target';
        $state['health']['target'] = 'https://example.com/new';
        $ops->accept($node, $state);
        $this->assertSame('new-target', $ops->view($node)['state']['health']['id']);
        $this->assertSame(2, DB::table('v2_node_health_sample')->count());
        admin_setting(['node_health_check' => array_replace($config, ['enabled' => false])]);
        $this->postJson('/_health/check', ['node_id' => $node->id])->assertUnprocessable();
        $this->assertArrayNotHasKey('health', $ops->view($node)['state']);
    }

    public function test_node_edit_generates_request_for_global_inheritance_without_overriding_mode(): void
    {
        $node = $this->node();
        admin_setting(['node_health_check' => array_replace(NodeHealthConfig::defaults(), ['enabled' => true])]);
        $payload = ['id' => $node->id, 'name' => 'health-test', 'type' => 'vmess', 'host' => '127.0.0.1',
            'port' => 24443, 'server_port' => 24443, 'protocol_settings' => ['tls' => 0, 'network' => 'tcp'], 'rate' => 1, 'group_ids' => [1]];
        $this->postJson('/_health/save', $payload)->assertOk();
        $node->refresh();
        $this->assertSame('inherit', NodeHealthConfig::mode($node));
        $request = $node->health_check['request'];
        $this->postJson('/_health/save', $payload)->assertOk();
        $this->assertNotSame($request, $node->fresh()->health_check['request']);
    }
}
