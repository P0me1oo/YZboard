<?php

namespace Tests\Feature\Server;

use App\Http\Controllers\V2\Admin\Server\RouteController;
use App\Models\Server;
use App\Models\ServerRoute;
use App\Services\ServerRouteService;
use App\Services\ServerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ServerRouteEnabledTest extends TestCase
{
    use RefreshDatabase;

    private array $pushes = [];

    protected function setUp(): void
    {
        parent::setUp();
        Route::get('/_tests/route-enabled/fetch', [RouteController::class, 'fetch']);
        Route::post('/_tests/route-enabled/save', [RouteController::class, 'save']);
        Route::post('/_tests/route-enabled/update', [RouteController::class, 'update']);
        Redis::shouldReceive('publish')->byDefault()->andReturnUsing(function ($channel, $message) {
            $this->pushes[] = json_decode($message, true, flags: JSON_THROW_ON_ERROR);
            return 1;
        });
    }

    public function test_save_defaults_to_enabled_and_legacy_edit_preserves_disabled_state(): void
    {
        $payload = ['remarks' => '路由', 'match' => ['example.invalid'], 'action' => 'block'];
        $this->postJson('/_tests/route-enabled/save', $payload)->assertOk();
        $this->assertTrue(ServerRoute::latest('id')->firstOrFail()->enabled);
        $this->postJson('/_tests/route-enabled/save', [...$payload, 'enabled' => false])->assertOk();
        $route = ServerRoute::latest('id')->firstOrFail();
        $this->assertFalse($route->enabled);
        $this->postJson('/_tests/route-enabled/save', [...$payload, 'id' => $route->id, 'remarks' => '仍停用'])->assertOk();
        $this->assertFalse($route->fresh()->enabled);
        $this->getJson('/_tests/route-enabled/fetch')->assertOk()
            ->assertJsonPath('data.0.enabled', true)->assertJsonPath('data.1.enabled', false);
        $this->postJson('/_tests/route-enabled/save', [...$payload, 'id' => $route->id, 'enabled' => true])->assertOk();
        $this->assertTrue($route->fresh()->enabled);
    }

    public function test_switch_preserves_bindings_and_pushes_final_state_to_all_affected_nodes(): void
    {
        $route = ServerRoute::create([
            'remarks' => '待切换', 'match' => [], 'protocol' => ['bittorrent'], 'action' => 'block',
            'apply_to_new_nodes' => true,
        ]);
        $other = ServerRoute::create(['remarks' => '其他路由', 'match' => ['other.invalid'], 'action' => 'direct']);
        $visible = $this->node([(string) $route->id, (string) $other->id]);
        $hidden = $this->node([(string) $route->id], false);
        $unrelated = $this->node([(string) $other->id]);
        foreach ([$visible, $hidden, $unrelated] as $node) Cache::put("node_ws_alive:{$node->id}", true);
        $bindings = Server::pluck('route_ids', 'id')->all();
        $original = $route->toNodeConfig();
        $this->pushes = [];

        $this->postJson('/_tests/route-enabled/update', ['id' => $route->id, 'enabled' => false])->assertOk();
        $this->assertFalse($route->fresh()->enabled);
        $this->assertSame($bindings, Server::pluck('route_ids', 'id')->all());
        $this->assertSame([(string) $route->id], ServerRouteService::defaultRouteIdsForNewNode());
        $this->assertSame([$other->id], ServerService::getRoutes($visible->route_ids)->pluck('id')->all());
        $pushed = collect($this->pushes)->where('event', 'sync.config')->keyBy('node_id');
        $this->assertEqualsCanonicalizing([$visible->id, $hidden->id], $pushed->keys()->all());
        $this->assertSame([], $pushed[$hidden->id]['data']['config']['routes']);
        $this->assertSame([$other->id], array_column($pushed[$visible->id]['data']['config']['routes'], 'id'));

        $this->pushes = [];
        $updatedAt = $route->fresh()->updated_at;
        $this->travel(5)->seconds();
        $this->postJson('/_tests/route-enabled/update', ['id' => $route->id, 'enabled' => false])->assertOk();
        $this->assertSame([], $this->pushes);
        $this->assertEquals($updatedAt, $route->fresh()->updated_at);
        $this->assertSame([], ServerService::buildNodeConfig($hidden->fresh())['routes']->all());

        $this->postJson('/_tests/route-enabled/update', ['id' => $route->id, 'enabled' => true])->assertOk();
        $this->assertSame($original, ServerService::getRoutes([$route->id])->sole());
        $this->assertSame($bindings, Server::pluck('route_ids', 'id')->all());
        $pushed = collect($this->pushes)->where('event', 'sync.config')->keyBy('node_id');
        $this->assertSame([$original], $pushed[$hidden->id]['data']['config']['routes']);
    }

    public function test_invalid_or_failed_switch_does_not_change_state_or_push_config(): void
    {
        $route = ServerRoute::create(['remarks' => '校验', 'match' => ['example.invalid'], 'action' => 'block']);
        $node = $this->node([(string) $route->id]);
        Cache::put("node_ws_alive:{$node->id}", true);
        $this->pushes = [];
        foreach ([null, 'false', [], 2] as $enabled) {
            $this->postJson('/_tests/route-enabled/update', ['id' => $route->id, 'enabled' => $enabled])
                ->assertUnprocessable()->assertJsonValidationErrors('enabled');
        }
        $this->postJson('/_tests/route-enabled/update', ['enabled' => false])->assertUnprocessable();
        $this->postJson('/_tests/route-enabled/update', ['id' => 999999, 'enabled' => false])->assertStatus(400);
        $this->failNextQueryMatching('/update ["`]v2_server_route["`]/i');
        $this->postJson('/_tests/route-enabled/update', ['id' => $route->id, 'enabled' => false])->assertStatus(500);
        $this->assertTrue($route->fresh()->enabled);
        $this->assertSame([], $this->pushes);
        $this->postJson('/_tests/route-enabled/update', ['id' => $route->id, 'enabled' => false])->assertOk();
        $this->assertFalse($route->fresh()->enabled);
    }

    private function node(array $routeIds, bool $show = true): Server
    {
        static $port = 32000;
        $port++;
        return Server::create([
            'name' => '开关测试节点', 'type' => Server::TYPE_VLESS, 'host' => 'route.example.invalid',
            'port' => (string) $port, 'server_port' => $port, 'rate' => 1, 'show' => $show,
            'group_ids' => [], 'protocol_settings' => ['tls' => 0, 'network' => 'tcp'], 'route_ids' => $routeIds,
        ]);
    }
}
