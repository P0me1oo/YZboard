<?php

namespace Tests\Feature\Server;

use App\Http\Controllers\V1\Client\ClientController;
use App\Http\Controllers\V1\User\ServerController as UserServerController;
use App\Http\Controllers\V2\Admin\Server\ManageController;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\User;
use App\Services\ServerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ServerSwitchTest extends TestCase
{
    use RefreshDatabase;

    private array $pushes = [];

    protected function setUp(): void
    {
        parent::setUp();
        Route::post('/_tests/server-switch', [ManageController::class, 'update']);
        Route::post('/_tests/server-switch/batch', [ManageController::class, 'batchUpdate']);
        Route::post('/_tests/server-switch/save', [ManageController::class, 'save']);
        Route::get('/_tests/server-switch/user-nodes', [UserServerController::class, 'fetch']);
        Route::get('/_tests/server-switch/subscribe', [ClientController::class, 'subscribe']);
        Redis::shouldReceive('publish')->byDefault()->andReturnUsing(function (string $channel, string $message): int {
            $this->assertSame('node:push', $channel);
            $this->pushes[] = json_decode($message, true, flags: JSON_THROW_ON_ERROR);
            return 1;
        });
    }

    private function machine(): ServerMachine
    {
        return ServerMachine::create([
            'name' => '节点开关测试服务器',
            'token' => bin2hex(random_bytes(24)),
            'is_active' => true,
        ]);
    }

    private function node(ServerMachine $machine, int $port, array $overrides = []): Server
    {
        return Server::create(array_replace([
            'name' => '开关测试节点 ' . $port,
            'machine_id' => $machine->id,
            'type' => Server::TYPE_VLESS,
            'kernel_type' => 'xray',
            'host' => 'switch.example.invalid',
            'port' => (string) $port,
            'server_port' => $port,
            'protocol_settings' => ['tls' => 0, 'network' => 'tcp'],
            'rate' => 1,
            'group_ids' => ['1'],
            'enabled' => true,
            'show' => true,
        ], $overrides));
    }

    private function assertDiscovered(ServerMachine $machine, array $nodes): void
    {
        $response = $this->postJson('/api/v2/server/machine/nodes', [
            'machine_id' => $machine->id,
            'token' => $machine->token,
        ])->assertOk();
        $this->assertSame(
            array_map(fn (Server $node) => $node->id, $nodes),
            array_column($response->json('nodes'), 'id')
        );
        $this->assertTrue($machine->fresh()->is_active);
    }

    private function assertVisible(array $nodes): void
    {
        $user = new User(['group_id' => 1, 'uuid' => (string) Str::uuid()]);
        $this->assertEqualsCanonicalizing(
            array_map(fn (Server $node) => $node->id, $nodes),
            array_column(ServerService::getAvailableServers($user), 'id')
        );
    }

    public static function switchOperations(): array
    {
        return ['单节点' => [false], '批量节点' => [true]];
    }

    private function creationPayload(ServerMachine $machine, array $overrides = []): array
    {
        return array_replace([
            'name' => '新建开关测试节点',
            'machine_id' => $machine->id,
            'type' => Server::TYPE_VLESS,
            'host' => 'creation.example.invalid',
            'port' => '24444',
            'server_port' => 24444,
            'protocol_settings' => ['tls' => 0, 'network' => 'tcp'],
            'rate' => 1,
            'group_ids' => ['1'],
        ], $overrides);
    }

    public static function creationSwitchStates(): array
    {
        return [
            '开启覆盖表单默认隐藏' => [['enabled' => true, 'show' => false], true, true],
            '关闭覆盖显式显示' => [['enabled' => false, 'show' => true], false, false],
            '未传开关沿用默认开启' => [['show' => false], true, true],
            '未传开关和显隐' => [[], true, true],
            '字符串开启' => [['enabled' => '1', 'show' => 0], true, true],
            '数字关闭' => [['enabled' => 0, 'show' => 1], false, false],
            '空开关保留独立部署显示' => [['machine_id' => null, 'enabled' => null, 'show' => true], null, true],
            '空开关保留独立部署隐藏' => [['machine_id' => null, 'enabled' => null, 'show' => false], null, false],
        ];
    }

    #[DataProvider('creationSwitchStates')]
    public function test_new_node_visibility_follows_its_initial_switch(
        array $fields,
        ?bool $expectedEnabled,
        bool $expectedShow,
    ): void {
        $machine = $this->machine();
        $peer = $this->node($machine, 24443, ['show' => false]);
        $peerAttributes = $peer->fresh()->getAttributes();

        $this->postJson('/_tests/server-switch/save', $this->creationPayload($machine, $fields))
            ->assertOk()->assertJsonPath('data', true);
        $node = Server::latest('id')->firstOrFail();

        $this->assertSame($expectedEnabled, $node->enabled);
        $this->assertSame($expectedShow, $node->show);
        $this->assertSame($peerAttributes, $peer->fresh()->getAttributes());
        $this->assertDiscovered($machine, $expectedEnabled ? [$peer, $node] : [$peer]);
        $this->assertVisible($expectedShow ? [$node] : []);
    }

    private function assertUserLists(User $user, array $nodes): void
    {
        $this->actingAs($user);
        $response = $this->getJson('/_tests/server-switch/user-nodes')->assertOk();
        $this->assertEqualsCanonicalizing(
            array_map(fn (Server $node) => $node->id, $nodes),
            array_column($response->json('data'), 'id'),
        );

        $response = $this->get('/_tests/server-switch/subscribe?flag=general')->assertOk();
        $subscription = base64_decode($response->getContent(), true);
        $this->assertIsString($subscription);
        // 只比较最终订阅中的节点名称，不在断言失败时输出测试认证信息。
        $names = array_map(
            fn (string $uri) => rawurldecode((string) parse_url($uri, PHP_URL_FRAGMENT)),
            preg_split('/\r?\n/', trim($subscription), -1, PREG_SPLIT_NO_EMPTY),
        );
        $this->assertEqualsCanonicalizing(array_map(fn (Server $node) => $node->name, $nodes), $names);
    }

    public function test_new_enabled_node_appears_in_user_list_and_subscription_and_survives_edits(): void
    {
        $machine = $this->machine();
        $user = User::create([
            'email' => 'creation-test@example.invalid',
            'password' => bcrypt(Str::random(32)),
            'uuid' => (string) Str::uuid(),
            'token' => Str::random(32),
            'group_id' => 1,
            'transfer_enable' => 1024 * 1024,
            'expired_at' => null,
            'banned' => false,
            'u' => 0,
            'd' => 0,
        ]);

        // 模拟现有管理端新建表单提交的开启开关和默认隐藏值。
        $this->postJson('/_tests/server-switch/save', $this->creationPayload($machine, [
            'enabled' => true, 'show' => false,
        ]))->assertOk()->assertJsonPath('data', true);
        $node = Server::latest('id')->firstOrFail();

        for ($repeat = 0; $repeat < 2; $repeat++) {
            $this->assertUserLists($user, [$node]);
            $this->postJson('/_tests/server-switch/save', array_replace($node->fresh()->toArray(), [
                'name' => '新建后编辑的节点',
            ]))->assertOk()->assertJsonPath('data', true);
            $node->refresh();
        }

        foreach ([false, false, true, true] as $enabled) {
            $this->postJson('/_tests/server-switch', ['id' => $node->id, 'enabled' => $enabled])
                ->assertOk()->assertJsonPath('data', true);
            $this->assertUserLists($user, $enabled ? [$node] : []);
            $this->assertDiscovered($machine, $enabled ? [$node] : []);
        }
    }

    public function test_failed_creation_leaves_existing_node_visibility_and_runtime_unchanged(): void
    {
        $machine = $this->machine();
        $peer = $this->node($machine, 24443, ['show' => false]);
        $peerAttributes = $peer->fresh()->getAttributes();
        $this->pushes = [];

        foreach ([['enabled' => 'invalid'], ['enabled' => true, 'server_port' => 24443]] as $fields) {
            $this->postJson('/_tests/server-switch/save', $this->creationPayload($machine, $fields))
                ->assertUnprocessable()->assertJsonValidationErrors(
                    isset($fields['server_port']) ? 'server_port' : 'enabled',
                );
            $this->assertSame(1, Server::count());
            $this->assertSame($peerAttributes, $peer->fresh()->getAttributes());
            $this->assertDiscovered($machine, [$peer]);
            $this->assertVisible([]);
            $this->assertSame([], $this->pushes);
        }
    }

    public function test_disabling_and_reenabling_one_node_updates_visibility_and_preserves_its_peers_and_machine(): void
    {
        $machine = $this->machine();
        $otherMachine = $this->machine();
        $target = $this->node($machine, 24443);
        $peer = $this->node($machine, 24444, ['kernel_type' => 'singbox']);
        $other = $this->node($otherMachine, 24443);
        $peerAttributes = $peer->fresh()->getAttributes();
        $otherAttributes = $other->fresh()->getAttributes();
        $this->pushes = [];

        foreach ([false, false, true, true] as $enabled) {
            $this->postJson('/_tests/server-switch', ['id' => $target->id, 'enabled' => $enabled])
                ->assertOk()->assertJsonPath('data', true);

            $this->assertSame($enabled, $target->fresh()->enabled);
            $this->assertSame($enabled, $target->fresh()->show);
            $this->assertSame($machine->id, $target->fresh()->machine_id);
            $this->assertSame($peerAttributes, $peer->fresh()->getAttributes());
            $this->assertSame($otherAttributes, $other->fresh()->getAttributes());
            $this->assertDiscovered($machine, $enabled ? [$target, $peer] : [$peer]);
            $this->assertDiscovered($otherMachine, [$other]);
            $this->assertVisible($enabled ? [$target, $peer, $other] : [$peer, $other]);
        }

        // 重复设置相同状态不产生额外通知，通知始终保留同机器上的其他节点。
        $this->assertCount(2, $this->pushes);
        foreach ($this->pushes as $push) {
            $this->assertSame($machine->id, $push['machine_id']);
            $this->assertSame('sync.nodes', $push['event']);
            $this->assertArrayNotHasKey('node_id', $push);
        }
        $this->assertSame([$peer->id], array_column($this->pushes[0]['data']['nodes'], 'id'));
        $this->assertSame([$target->id, $peer->id], array_column($this->pushes[1]['data']['nodes'], 'id'));
        $this->assertSame('singbox', $this->pushes[0]['data']['nodes'][0]['kernel_type']);
    }

    public function test_last_node_can_be_stopped_and_started_without_disabling_its_machine(): void
    {
        $machine = $this->machine();
        $node = $this->node($machine, 24443);
        $this->pushes = [];

        $this->postJson('/_tests/server-switch', ['id' => $node->id, 'enabled' => false])
            ->assertOk()->assertJsonPath('data', true);
        $this->assertFalse($node->fresh()->show);
        $this->assertDiscovered($machine, []);
        $this->postJson('/api/v2/server/handshake', [
            'machine_id' => $machine->id, 'token' => $machine->token,
        ])->assertOk();

        $this->postJson('/_tests/server-switch', ['id' => $node->id, 'enabled' => true])
            ->assertOk()->assertJsonPath('data', true);
        $this->assertTrue($node->fresh()->show);
        $this->assertDiscovered($machine, [$node]);
        $this->assertSame([], $this->pushes[0]['data']['nodes']);
        $this->assertSame([$node->id], array_column($this->pushes[1]['data']['nodes'], 'id'));
    }

    public function test_conflicting_restart_is_rejected_without_changing_other_nodes_or_sending_a_stop(): void
    {
        $machine = $this->machine();
        $peer = $this->node($machine, 24443);
        $target = $this->node($machine, 24443, ['enabled' => false, 'show' => false]);
        $targetAttributes = $target->fresh()->getAttributes();
        $this->pushes = [];

        $this->postJson('/_tests/server-switch', ['id' => $target->id, 'enabled' => true])
            ->assertStatus(422)->assertJsonValidationErrors('server_port');
        $this->assertFalse($target->fresh()->enabled);
        $this->assertSame($targetAttributes, $target->fresh()->getAttributes());
        $this->assertTrue($peer->fresh()->enabled);
        $this->assertDiscovered($machine, [$peer]);
        $this->assertSame([], $this->pushes);
    }

    public function test_invalid_switch_requests_do_not_change_any_node(): void
    {
        $machine = $this->machine();
        $node = $this->node($machine, 24443);
        $this->pushes = [];

        foreach ([['enabled' => false], ['id' => $node->id, 'enabled' => 'stop']] as $payload) {
            $this->postJson('/_tests/server-switch', $payload)->assertStatus(422);
        }
        $response = $this->postJson('/_tests/server-switch', ['id' => $node->id + 1000, 'enabled' => false]);
        $this->assertNotEquals(true, $response->json('data'));
        $this->assertTrue($node->fresh()->enabled);
        $this->assertTrue($node->fresh()->show);
        $this->assertDiscovered($machine, [$node]);
        $this->assertSame([], $this->pushes);
    }

    public function test_rediscovery_preserves_peers_when_the_realtime_notification_fails(): void
    {
        $machine = $this->machine();
        $target = $this->node($machine, 24443);
        $peer = $this->node($machine, 24444);
        Redis::shouldReceive('publish')->once()->andThrow(new \RuntimeException('测试推送不可用'));

        $this->postJson('/_tests/server-switch', ['id' => $target->id, 'enabled' => false])
            ->assertOk()->assertJsonPath('data', true);
        $this->assertFalse($target->fresh()->enabled);
        $this->assertFalse($target->fresh()->show);
        $this->assertTrue($peer->fresh()->enabled);
        $this->assertDiscovered($machine, [$peer]);
        $this->assertVisible([$peer]);
    }

    #[DataProvider('switchOperations')]
    public function test_visibility_only_changes_do_not_start_or_stop_nodes(bool $batch): void
    {
        $machine = $this->machine();
        $running = $this->node($machine, 24443);
        $stopped = $this->node($machine, 24444, ['enabled' => false, 'show' => false]);
        $peer = $this->node($machine, 24445, ['kernel_type' => 'singbox']);
        $peerAttributes = $peer->fresh()->getAttributes();
        $this->pushes = [];

        foreach ([false, false, true, true] as $show) {
            if ($batch) {
                $this->postJson('/_tests/server-switch/batch', [
                    'ids' => [$running->id, $stopped->id], 'show' => (int) $show,
                ])->assertOk()->assertJsonPath('data', true);
            } else {
                foreach ([$running, $stopped] as $node) {
                    $this->postJson('/_tests/server-switch', ['id' => $node->id, 'show' => (int) $show])
                        ->assertOk()->assertJsonPath('data', true);
                }
            }

            $this->assertSame($show, $running->fresh()->show);
            $this->assertSame($show, $stopped->fresh()->show);
            $this->assertTrue($running->fresh()->enabled);
            $this->assertFalse($stopped->fresh()->enabled);
            $this->assertSame($peerAttributes, $peer->fresh()->getAttributes());
            $this->assertDiscovered($machine, [$running, $peer]);
            $this->assertVisible($show ? [$running, $stopped, $peer] : [$peer]);
        }

        $this->assertSame([], $this->pushes);
    }

    #[DataProvider('switchOperations')]
    public function test_switch_takes_precedence_over_visibility_even_when_runtime_state_is_unchanged(bool $batch): void
    {
        $machine = $this->machine();
        $node = $this->node($machine, 24443, ['show' => false]);
        $url = $batch ? '/_tests/server-switch/batch' : '/_tests/server-switch';
        $target = $batch ? ['ids' => [$node->id]] : ['id' => $node->id];
        $this->pushes = [];

        foreach ([true, true, false, false, true] as $enabled) {
            $this->postJson($url, $target + ['enabled' => $enabled, 'show' => (int) !$enabled])
                ->assertOk()->assertJsonPath('data', true);
            $this->assertSame($enabled, $node->fresh()->enabled);
            $this->assertSame($enabled, $node->fresh()->show);
            $this->assertDiscovered($machine, $enabled ? [$node] : []);
            $this->assertVisible($enabled ? [$node] : []);

            // 单独改显隐后，再次设置相同开关状态仍应让显隐跟随。
            $this->postJson($url, $target + ['show' => (int) !$enabled])
                ->assertOk()->assertJsonPath('data', true);
            $this->postJson($url, $target + ['enabled' => $enabled])
                ->assertOk()->assertJsonPath('data', true);
            $this->assertSame($enabled, $node->fresh()->show);
        }

        $this->assertCount(2, $this->pushes);
    }

    public function test_batch_switch_updates_selected_nodes_and_visibility_without_changing_peers(): void
    {
        $machine = $this->machine();
        $otherMachine = $this->machine();
        $first = $this->node($machine, 24443);
        $peer = $this->node($machine, 24444);
        $second = $this->node($otherMachine, 24443, ['kernel_type' => 'singbox']);
        $otherPeer = $this->node($otherMachine, 24444);
        $peerAttributes = $peer->fresh()->getAttributes();
        $otherPeerAttributes = $otherPeer->fresh()->getAttributes();
        $this->pushes = [];

        foreach ([false, false, true, true] as $enabled) {
            $this->postJson('/_tests/server-switch/batch', [
                'ids' => [$first->id, $second->id], 'enabled' => $enabled,
            ])->assertOk()->assertJsonPath('data', true);

            foreach ([$first, $second] as $node) {
                $this->assertSame($enabled, $node->fresh()->enabled);
                $this->assertSame($enabled, $node->fresh()->show);
            }
            $this->assertSame($peerAttributes, $peer->fresh()->getAttributes());
            $this->assertSame($otherPeerAttributes, $otherPeer->fresh()->getAttributes());
            $this->assertDiscovered($machine, $enabled ? [$first, $peer] : [$peer]);
            $this->assertDiscovered($otherMachine, $enabled ? [$second, $otherPeer] : [$otherPeer]);
            $this->assertVisible($enabled ? [$first, $peer, $second, $otherPeer] : [$peer, $otherPeer]);
        }

        $this->assertCount(4, $this->pushes);
    }

    public function test_batch_conflict_rolls_back_runtime_and_visibility_without_notifying_nodes(): void
    {
        $machine = $this->machine();
        $first = $this->node($machine, 24444, ['enabled' => false, 'show' => false]);
        $second = $this->node($machine, 24443, ['enabled' => false, 'show' => false]);
        $peer = $this->node($machine, 24443);
        $original = array_map(fn (Server $node) => $node->fresh()->getAttributes(), [$first, $second, $peer]);
        $this->pushes = [];

        $this->postJson('/_tests/server-switch/batch', [
            'ids' => [$first->id, $second->id], 'enabled' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('server_port');

        $this->assertSame(
            $original,
            array_map(fn (Server $node) => $node->fresh()->getAttributes(), [$first, $second, $peer])
        );
        $this->assertDiscovered($machine, [$peer]);
        $this->assertVisible([$peer]);
        $this->assertSame([], $this->pushes);
    }

    public function test_saving_other_node_settings_preserves_independent_visibility(): void
    {
        $machine = $this->machine();
        $node = $this->node($machine, 24443, ['show' => false]);
        $this->pushes = [];

        $this->postJson('/_tests/server-switch/save', array_replace($node->fresh()->toArray(), [
            'name' => '仅修改名称的隐藏节点',
        ]))->assertOk()->assertJsonPath('data', true);

        $this->assertTrue($node->fresh()->enabled);
        $this->assertFalse($node->fresh()->show);
        $this->assertDiscovered($machine, [$node]);
        $this->assertVisible([]);
        $this->assertSame([], $this->pushes);
    }
}
