<?php

namespace Tests\Feature\Server;

use App\Http\Controllers\V2\Admin\Server\ManageController;
use App\Models\Server;
use App\Models\ServerGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class ServerBatchGroupTest extends TestCase
{
    use RefreshDatabase;

    public function test_batch_group_actions_are_incremental_and_idempotent(): void
    {
        $baseGroup = $this->makeGroup('基础组');
        $targetGroup = $this->makeGroup('目标组');

        $first = $this->makeServer('节点一', [$baseGroup->id]);
        $second = $this->makeServer('节点二', [$baseGroup->id, $targetGroup->id]);
        $unselected = $this->makeServer('未选节点', [$baseGroup->id]);

        $this->batchUpdateGroups([$first->id, $second->id], 'add', $targetGroup->id);
        $this->batchUpdateGroups([$first->id, $second->id], 'add', $targetGroup->id);

        $this->assertSame(
            [(string) $baseGroup->id, (string) $targetGroup->id],
            $first->fresh()->group_ids
        );
        $this->assertSame(
            [(string) $baseGroup->id, (string) $targetGroup->id],
            $second->fresh()->group_ids
        );
        $this->assertSame([(string) $baseGroup->id], $unselected->fresh()->group_ids);

        $this->batchUpdateGroups([$first->id, $second->id], 'remove', $targetGroup->id);
        $this->batchUpdateGroups([$first->id, $second->id], 'remove', $targetGroup->id);

        $this->assertSame([(string) $baseGroup->id], $first->fresh()->group_ids);
        $this->assertSame([(string) $baseGroup->id], $second->fresh()->group_ids);
        $this->assertSame([(string) $baseGroup->id], $unselected->fresh()->group_ids);
    }

    private function makeGroup(string $name): ServerGroup
    {
        $group = new ServerGroup();
        $group->name = $name;
        $group->save();

        return $group;
    }

    /**
     * @param array<int, int|string> $groupIds
     */
    private function makeServer(string $name, array $groupIds): Server
    {
        return Server::create([
            'name' => $name,
            'type' => Server::TYPE_SHADOWSOCKS,
            'host' => '127.0.0.1',
            'port' => 443,
            'server_port' => 443,
            'rate' => 1,
            'show' => true,
            'group_ids' => array_map('strval', $groupIds),
            'protocol_settings' => [
                'cipher' => '2022-blake3-aes-128-gcm',
            ],
        ]);
    }

    /**
     * @param array<int, int> $serverIds
     */
    private function batchUpdateGroups(array $serverIds, string $action, int $groupId): void
    {
        $request = Request::create('/', 'POST', [
            'ids' => $serverIds,
            'group_action' => $action,
            'group_id' => $groupId,
        ]);

        $response = app(ManageController::class)->batchUpdate($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue((bool) $response->getData(true)['data']);
    }
}
