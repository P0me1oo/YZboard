<?php

namespace Tests\Feature\Server;

use App\Http\Controllers\V2\Admin\Server\GroupController;
use App\Models\Server;
use App\Models\ServerGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ServerGroupSortTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_group_is_appended_and_list_follows_sort(): void
    {
        $first = $this->saveGroup('组一');
        $second = $this->saveGroup('组二');
        $third = $this->saveGroup('组三');

        // 新建时依次排到末尾。
        $this->assertSame([1, 2, 3], [$first->sort, $second->sort, $third->sort]);
        $this->assertSame(['组一', '组二', '组三'], $this->fetchNames());

        $this->sortGroups([
            ['id' => $third->id, 'order' => 1],
            ['id' => $first->id, 'order' => 2],
            ['id' => $second->id, 'order' => 3],
        ]);

        $this->assertSame(['组三', '组一', '组二'], $this->fetchNames());
        $this->assertSame(2, $first->fresh()->sort);
    }

    public function test_group_without_sort_falls_to_the_end(): void
    {
        $legacy = $this->saveGroup('历史组');
        $sorted = $this->saveGroup('已排序组');

        // 模拟升级前写入、排序值为空的历史数据。
        DB::table('v2_server_group')->where('id', $legacy->id)->update(['sort' => null]);

        $this->assertSame(['已排序组', '历史组'], $this->fetchNames());
    }

    public function test_node_group_list_follows_the_same_order(): void
    {
        $first = $this->saveGroup('组一');
        $second = $this->saveGroup('组二');

        $server = Server::create([
            'name' => '节点一',
            'type' => Server::TYPE_SHADOWSOCKS,
            'host' => '127.0.0.1',
            'port' => 443,
            'server_port' => 443,
            'rate' => 1,
            'show' => true,
            'group_ids' => [(string) $first->id, (string) $second->id],
            'protocol_settings' => [
                'cipher' => '2022-blake3-aes-128-gcm',
            ],
        ]);

        $this->sortGroups([
            ['id' => $second->id, 'order' => 1],
            ['id' => $first->id, 'order' => 2],
        ]);

        $this->assertSame(
            ['组二', '组一'],
            $server->fresh()->groups()->pluck('name')->all()
        );
    }

    private function saveGroup(string $name): ServerGroup
    {
        $response = app(GroupController::class)->save(Request::create('/', 'POST', [
            'name' => $name,
        ]));

        $this->assertSame(200, $response->getStatusCode());

        return ServerGroup::where('name', $name)->firstOrFail();
    }

    /**
     * @param array<int, array{id: int, order: int}> $payload
     */
    private function sortGroups(array $payload): void
    {
        $response = app(GroupController::class)->sort(Request::create('/', 'POST', $payload));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue((bool) $response->getData(true)['data']);
    }

    /**
     * @return array<int, string>
     */
    private function fetchNames(): array
    {
        $response = app(GroupController::class)->fetch(Request::create('/', 'GET'));

        $this->assertSame(200, $response->getStatusCode());

        return array_column($response->getData(true)['data'], 'name');
    }
}
