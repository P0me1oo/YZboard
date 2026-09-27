<?php

namespace Tests\Feature\Server;

use App\Http\Controllers\V2\Admin\Server\ManageController;
use App\Http\Controllers\V2\Admin\Server\RouteController;
use App\Models\Server;
use App\Models\ServerRoute;
use App\Services\ServerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * 路由管理：附加匹配条件、在路由里批量选择节点、删除清理和新建节点的默认路由。
 */
class ServerRouteTest extends TestCase
{
    use RefreshDatabase;

    private array $pushes = [];

    protected function setUp(): void
    {
        parent::setUp();
        Route::get('/_tests/route/fetch', [RouteController::class, 'fetch']);
        Route::post('/_tests/route/save', [RouteController::class, 'save']);
        Route::post('/_tests/route/drop', [RouteController::class, 'drop']);
        Route::post('/_tests/route/node-save', [ManageController::class, 'save']);
        Redis::shouldReceive('publish')->byDefault()->andReturnUsing(function (string $channel, string $message): int {
            $this->pushes[] = json_decode($message, true, flags: JSON_THROW_ON_ERROR);
            return 1;
        });
    }

    public function test_save_normalizes_conditions_and_node_config_output(): void
    {
        $this->postJson('/_tests/route/save', [
            'remarks' => 'BT 与端口',
            'match' => [' example.com ', '', 'example.com', '1.1.1.0/24'],
            'protocol' => ['bittorrent', 'bittorrent'],
            'port' => '25, 6881-6889，443-443',
            'network' => 'udp',
            'action' => 'block',
        ])->assertOk();
        $route = ServerRoute::latest('id')->firstOrFail();
        $this->assertSame(['example.com', '1.1.1.0/24'], $route->match);
        $this->assertSame(['bittorrent'], $route->protocol);
        $this->assertSame('25,6881-6889,443', $route->port);
        $this->assertSame('udp', $route->network);
        $this->assertTrue($route->fresh()->apply_to_new_nodes);

        $this->postJson('/_tests/route/save', [
            'remarks' => '只有协议', 'match' => [], 'protocol' => ['bittorrent'], 'action' => 'block',
        ])->assertOk();
        $protocolOnly = ServerRoute::latest('id')->firstOrFail();
        $legacy = ServerRoute::create(['remarks' => '旧路由', 'match' => ['example.org'], 'action' => 'direct']);

        $node = $this->node(['route_ids' => [(string) $route->id, (string) $protocolOnly->id, (string) $legacy->id]]);
        $routes = collect(ServerService::buildNodeConfig($node)['routes'])->keyBy('id');
        $this->assertSame([
            'id' => $route->id, 'match' => ['example.com', '1.1.1.0/24'], 'action' => 'block', 'action_value' => null,
            'protocol' => ['bittorrent'], 'port' => '25,6881-6889,443', 'network' => 'udp',
        ], $routes[$route->id]);
        $this->assertSame([
            'id' => $protocolOnly->id, 'match' => [], 'action' => 'block', 'action_value' => null, 'protocol' => ['bittorrent'],
        ], $routes[$protocolOnly->id]);
        // 未使用新条件的路由与旧版下发格式完全相同。
        $this->assertSame(['id', 'match', 'action', 'action_value'], array_keys($routes[$legacy->id]));
    }

    public function test_save_rejects_invalid_conditions_without_side_effects(): void
    {
        $node = $this->node();
        $cases = [
            'match' => ['remarks' => '空路由', 'match' => ['', ' '], 'action' => 'block'],
            'port' => ['remarks' => '端口为零', 'match' => [], 'port' => '0', 'action' => 'block'],
            'protocol.0' => ['remarks' => '未知协议', 'match' => [], 'protocol' => ['quic'], 'action' => 'block'],
            'network' => ['remarks' => '未知网络', 'match' => [], 'network' => 'icmp', 'action' => 'block'],
            'action' => ['remarks' => 'DNS 加端口', 'match' => ['example.com'], 'port' => '53', 'action' => 'dns', 'action_value' => '1.1.1.1'],
            'node_ids' => ['remarks' => '节点不存在', 'match' => [], 'port' => '25', 'action' => 'block', 'node_ids' => [$node->id, 999999]],
        ];
        foreach ($cases as $field => $payload) {
            $this->postJson('/_tests/route/save', $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        foreach (['70000', '9000-8000', 'abc', '1-2-3'] as $port) {
            $this->postJson('/_tests/route/save', ['remarks' => '端口', 'match' => [], 'port' => $port, 'action' => 'block'])
                ->assertUnprocessable()->assertJsonValidationErrors('port');
        }

        $this->assertSame(0, ServerRoute::count());
        $this->assertNull($node->fresh()->route_ids);
    }

    public function test_node_selection_only_changes_this_route(): void
    {
        $other = ServerRoute::create(['remarks' => '其他路由', 'match' => ['other.example'], 'action' => 'block']);
        $route = ServerRoute::create(['remarks' => '目标路由', 'match' => [], 'protocol' => ['bittorrent'], 'action' => 'block']);
        $keep = $this->node(['route_ids' => [(string) $other->id]]);
        $remove = $this->node(['route_ids' => [(string) $other->id, (string) $route->id]]);
        $empty = $this->node();
        $stale = $this->node(['route_ids' => [(int) $route->id]]);
        $untouched = $this->node(['route_ids' => [(string) $other->id]]);
        $stamps = Server::pluck('updated_at', 'id');

        $payload = ['id' => $route->id, 'remarks' => '目标路由', 'match' => [], 'protocol' => ['bittorrent'], 'action' => 'block'];
        $this->postJson('/_tests/route/save', [...$payload, 'node_ids' => [$keep->id, $empty->id]])->assertOk();

        $this->assertSame([(string) $other->id, (string) $route->id], $keep->fresh()->route_ids);
        $this->assertSame([(string) $other->id], $remove->fresh()->route_ids);
        $this->assertSame([(string) $route->id], $empty->fresh()->route_ids);
        $this->assertSame([], $stale->fresh()->route_ids);
        $this->assertSame([(string) $other->id], $untouched->fresh()->route_ids);
        $this->assertEquals($stamps[$untouched->id], $untouched->fresh()->updated_at);

        // 重复保存同一选择不改动任何节点；不提交节点列表时绑定关系保持不变。
        $stamps = Server::pluck('updated_at', 'id');
        $this->travel(5)->seconds();
        $this->postJson('/_tests/route/save', [...$payload, 'node_ids' => [$empty->id, $keep->id]])->assertOk();
        $this->postJson('/_tests/route/save', [...$payload, 'remarks' => '改名'])->assertOk();
        $this->assertEquals($stamps->all(), Server::pluck('updated_at', 'id')->all());

        $fetched = collect($this->getJson('/_tests/route/fetch')->assertOk()->json('data'))->keyBy('id');
        $this->assertSame([$keep->id, $empty->id], $fetched[$route->id]['node_ids']);
        $this->assertSame([$keep->id, $remove->id, $untouched->id], $fetched[$other->id]['node_ids']);
        $this->assertSame('改名', $fetched[$route->id]['remarks']);

        $this->postJson('/_tests/route/save', [...$payload, 'node_ids' => []])->assertOk();
        $this->assertSame([(string) $other->id], $keep->fresh()->route_ids);
        $this->assertSame([], $empty->fresh()->route_ids);
    }

    public function test_drop_removes_route_from_all_nodes(): void
    {
        $other = ServerRoute::create(['remarks' => '其他路由', 'match' => ['other.example'], 'action' => 'block']);
        $route = ServerRoute::create(['remarks' => '待删除', 'match' => ['drop.example'], 'action' => 'block']);
        $first = $this->node(['route_ids' => [(string) $route->id, (string) $other->id]]);
        $second = $this->node(['route_ids' => [(string) $route->id]]);

        $this->postJson('/_tests/route/drop', ['id' => $route->id])->assertOk();

        $this->assertNull(ServerRoute::find($route->id));
        $this->assertSame([(string) $other->id], $first->fresh()->route_ids);
        $this->assertSame([], $second->fresh()->route_ids);
        $this->postJson('/_tests/route/drop', ['id' => $route->id])->assertStatus(400);
    }

    public function test_new_nodes_apply_default_routes_only_when_routes_are_not_submitted(): void
    {
        $this->postJson('/_tests/route/node-save', $this->nodePayload())->assertOk();
        $this->assertNull(Server::latest('id')->firstOrFail()->route_ids, '没有默认路由时保持原行为');

        $first = ServerRoute::create(['remarks' => '默认一', 'match' => [], 'protocol' => ['bittorrent'], 'action' => 'block', 'apply_to_new_nodes' => true]);
        ServerRoute::create(['remarks' => '非默认', 'match' => ['example.com'], 'action' => 'block']);
        $third = ServerRoute::create(['remarks' => '默认二', 'match' => [], 'port' => '25', 'action' => 'block', 'apply_to_new_nodes' => true]);

        $this->postJson('/_tests/route/node-save', $this->nodePayload())->assertOk();
        $created = Server::latest('id')->firstOrFail();
        $this->assertSame([(string) $first->id, (string) $third->id], $created->route_ids);

        $this->postJson('/_tests/route/node-save', $this->nodePayload(['route_ids' => []]))->assertOk();
        $this->assertSame([], Server::latest('id')->firstOrFail()->route_ids);

        $this->postJson('/_tests/route/node-save', $this->nodePayload(['route_ids' => [(string) $third->id]]))->assertOk();
        $this->assertSame([(string) $third->id], Server::latest('id')->firstOrFail()->route_ids);

        // 编辑已有节点和复制节点都不套用默认路由。
        $plain = $this->node(['route_ids' => []]);
        $this->postJson('/_tests/route/node-save', $this->nodePayload(['id' => $plain->id, 'name' => '已编辑']))->assertOk();
        $this->assertSame([], $plain->fresh()->route_ids);
        app(ManageController::class)->copy(Request::create('/', 'POST', ['id' => $plain->id]));
        $this->assertSame([], Server::latest('id')->firstOrFail()->route_ids);

        $this->getJson('/_tests/route/fetch')->assertOk()
            ->assertJsonPath('data.0.apply_to_new_nodes', true)
            ->assertJsonPath('data.1.apply_to_new_nodes', false);
    }

    public function test_changes_push_config_to_bound_nodes_including_hidden_ones(): void
    {
        $route = ServerRoute::create(['remarks' => '推送', 'match' => ['example.com'], 'action' => 'block']);
        $hidden = $this->node(['route_ids' => [(string) $route->id], 'show' => false]);
        $added = $this->node();
        $unrelated = $this->node();
        foreach ([$hidden, $added, $unrelated] as $node) {
            Cache::put("node_ws_alive:{$node->id}", true);
        }

        $this->postJson('/_tests/route/save', [
            'id' => $route->id, 'remarks' => '推送', 'match' => ['example.com'], 'protocol' => ['bittorrent'],
            'action' => 'block', 'node_ids' => [$hidden->id, $added->id],
        ])->assertOk();

        $pushed = collect($this->pushes)->where('event', 'sync.config')->groupBy('node_id');
        $this->assertEqualsCanonicalizing([$hidden->id, $added->id], $pushed->keys()->all());
        foreach ([$hidden->id, $added->id] as $nodeId) {
            $routes = $pushed[$nodeId]->last()['data']['config']['routes'];
            $this->assertSame(['bittorrent'], $routes[0]['protocol']);
        }

        $this->pushes = [];
        $this->postJson('/_tests/route/drop', ['id' => $route->id])->assertOk();
        $pushed = collect($this->pushes)->where('event', 'sync.config');
        $this->assertEqualsCanonicalizing([$hidden->id, $added->id], $pushed->pluck('node_id')->unique()->values()->all());
        $this->assertArrayNotHasKey('routes', $pushed->last()['data']['config']);
    }

    private function node(array $overrides = []): Server
    {
        static $port = 30000;
        $port++;

        return Server::create(array_replace([
            'name' => '路由测试节点 ' . $port,
            'type' => Server::TYPE_VLESS,
            'host' => 'route.example.invalid',
            'port' => (string) $port,
            'server_port' => $port,
            'rate' => 1,
            'show' => true,
            'group_ids' => [],
            'protocol_settings' => ['tls' => 0, 'network' => 'tcp'],
        ], $overrides));
    }

    private function nodePayload(array $overrides = []): array
    {
        static $port = 31000;
        $port++;

        return array_replace([
            'name' => '新建节点', 'type' => Server::TYPE_SHADOWSOCKS,
            'host' => 'route.example.invalid', 'port' => (string) $port, 'server_port' => $port,
            'rate' => 1, 'protocol_settings' => ['cipher' => 'aes-128-gcm'],
        ], $overrides);
    }
}
