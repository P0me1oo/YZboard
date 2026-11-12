<?php

namespace Tests\Feature\Server;

use App\Models\Server;
use App\Models\ServerMachine;
use App\Services\TelegramBot\BusinessNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AgentLogOwnershipTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\IsolatesLogStorage;

    private const URL = '/api/v2/server/machine/agent-log';

    protected function setUp(): void
    {
        parent::setUp();
        $this->isolateLogStorage();
        $this->travelTo(now()->startOfSecond());
        $this->mock(BusinessNotificationService::class)
            ->shouldNotReceive('recordXrayCrash');
    }

    protected function tearDown(): void
    {
        $this->removeLogStorage();
        parent::tearDown();
    }

    private function machine(): ServerMachine
    {
        return ServerMachine::create([
            'name' => '日志归属测试机器', 'token' => Str::random(32), 'is_active' => true,
        ]);
    }

    private function node(ServerMachine $machine): Server
    {
        return Server::create([
            'name' => '日志归属测试节点', 'machine_id' => $machine->id,
            'type' => 'vless', 'host' => 'node.example.invalid', 'port' => '443',
            'server_port' => 24443, 'rate' => 1, 'group_ids' => [], 'enabled' => true,
        ]);
    }

    private function record(?Server $node, string $message, array $changes = []): array
    {
        return array_replace([
            'node_id' => $node?->id, 'source' => 'agent', 'level' => 'warn',
            'message' => $message, 'occurred_at' => now()->timestamp,
        ], $changes);
    }

    private function auth(ServerMachine $machine): array
    {
        return ['machine_id' => $machine->id, 'token' => $machine->token];
    }

    private function removeFromMachine(Server $node, string $change): void
    {
        $query = DB::table('v2_server')->where('id', $node->id);
        match ($change) {
            'moved' => $query->update(['machine_id' => $this->machine()->id]),
            'unbound' => $query->update(['machine_id' => null]),
            'deleted' => $query->delete(),
        };
    }

    public static function ownershipChanges(): array
    {
        return ['迁移' => ['moved'], '解绑' => ['unbound'], '删除' => ['deleted']];
    }

    #[DataProvider('ownershipChanges')]
    public function test_old_node_logs_do_not_block_valid_records_retries_or_following_batches(string $change): void
    {
        $machine = $this->machine();
        $old = $this->node($machine);
        $current = $this->node($machine);
        $batch = ['batch_id' => str_repeat('a', 32), 'records' => [
            $this->record(null, '机器告警'),
            $this->record($old, '旧节点正在停止', ['level' => 'info']),
            $this->record($old, '旧节点最后上报失败'),
            $this->record($current, '当前节点告警'),
            $this->record($old, '旧节点崩溃', ['source' => 'xray', 'level' => 'error', 'event' => 'xray.crash']),
            $this->record(null, '机器后续告警'),
        ]];
        $this->removeFromMachine($old, $change);

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->postJson(self::URL, $this->auth($machine) + $batch)->assertOk()
                ->assertJsonPath('data.batch_id', $batch['batch_id']);
        }
        $this->assertDatabaseCount('v2_agent_log', 3);
        $this->assertDatabaseMissing('v2_agent_log', ['node_id' => $old->id]);
        foreach ([0, 3, 5] as $index) {
            $this->assertDatabaseHas('v2_agent_log', [
                'event_key' => hash('sha256', 'machine:' . $machine->id . '|' . $batch['batch_id'] . '|' . $index),
                'machine_id' => $machine->id, 'message' => $batch['records'][$index]['message'],
            ]);
        }
        $this->assertDatabaseCount('v2_telegram_business_notifications', 0);

        $next = ['batch_id' => str_repeat('b', 32), 'records' => [$this->record($current, '下一批日志')]];
        $this->postJson(self::URL, $this->auth($machine) + $next)->assertOk()
            ->assertJsonPath('data.batch_id', $next['batch_id']);
        $this->assertDatabaseCount('v2_agent_log', 4);
    }

    #[DataProvider('ownershipChanges')]
    public function test_machine_without_nodes_acknowledges_an_entire_stale_batch_without_storing_or_notifying(string $change): void
    {
        $machine = $this->machine();
        $old = $this->node($machine);
        $batch = ['batch_id' => str_repeat('c', 32), 'records' => [
            $this->record($old, '旧节点停止失败'),
            $this->record($old, '旧节点崩溃', ['source' => 'xray', 'level' => 'fatal', 'event' => 'xray.crash']),
        ]];
        $this->removeFromMachine($old, $change);
        $this->assertSame(0, Server::where('machine_id', $machine->id)->count());

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->postJson(self::URL, $this->auth($machine) + $batch)->assertOk()
                ->assertJsonPath('data.batch_id', $batch['batch_id']);
        }
        $this->assertDatabaseCount('v2_agent_log', 0);
        $this->assertDatabaseCount('v2_telegram_business_notifications', 0);
    }

    public function test_lost_acknowledgement_before_migration_does_not_duplicate_or_reassign_logs(): void
    {
        $machine = $this->machine();
        $destination = $this->machine();
        $moving = $this->node($machine);
        $current = $this->node($machine);
        $batch = ['batch_id' => str_repeat('d', 32), 'records' => [
            $this->record($moving, '迁移前日志'),
            $this->record($current, '保留节点日志'),
            $this->record(null, '机器日志'),
        ]];
        $this->postJson(self::URL, $this->auth($machine) + $batch)->assertOk();
        $before = DB::table('v2_agent_log')->orderBy('id')->get()->toArray();
        DB::table('v2_server')->where('id', $moving->id)->update(['machine_id' => $destination->id]);

        $this->postJson(self::URL, $this->auth($machine) + $batch)->assertOk()
            ->assertJsonPath('data.batch_id', $batch['batch_id']);
        $this->assertEquals($before, DB::table('v2_agent_log')->orderBy('id')->get()->toArray());

        $newBatch = ['batch_id' => $batch['batch_id'], 'records' => [$this->record($moving, '迁移后的新日志')]];
        $this->postJson(self::URL, $this->auth($destination) + $newBatch)->assertOk();
        $this->assertDatabaseCount('v2_agent_log', 4);
        $this->assertDatabaseHas('v2_agent_log', [
            'machine_id' => $destination->id, 'node_id' => $moving->id, 'message' => '迁移后的新日志',
        ]);
    }

    public function test_invalid_or_disabled_machine_is_still_rejected_before_stale_records_are_discarded(): void
    {
        $machine = $this->machine();
        $other = $this->node($this->machine());
        $batch = ['batch_id' => str_repeat('e', 32), 'records' => [$this->record($other, '其他节点日志')]];
        $this->postJson(self::URL, ['machine_id' => $machine->id, 'token' => 'invalid-test-only'] + $batch)
            ->assertForbidden();
        $machine->update(['is_active' => false]);
        $this->postJson(self::URL, $this->auth($machine) + $batch)->assertForbidden();
        $this->assertDatabaseCount('v2_agent_log', 0);
    }

    public function test_node_binding_still_rejects_logs_from_another_existing_node_atomically(): void
    {
        $machine = $this->machine();
        $node = $this->node($machine);
        $other = $this->node($machine);
        $token = Str::random(32);
        admin_setting(['server_token' => $token]);
        $batch = ['batch_id' => str_repeat('f', 32), 'records' => [
            $this->record($node, '本节点日志'), $this->record($other, '另一个节点日志'),
        ]];
        $this->postJson('/api/v2/server/agent-log', ['node_id' => $node->id, 'token' => $token] + $batch)
            ->assertForbidden();
        $this->assertDatabaseCount('v2_agent_log', 0);
    }

    public function test_database_failure_keeps_the_batch_retryable_after_stale_records_are_discarded(): void
    {
        $machine = $this->machine();
        $old = $this->node($machine);
        $current = $this->node($machine);
        $batch = ['batch_id' => str_repeat('1', 32), 'records' => [
            $this->record($old, '旧节点日志'), $this->record($current, '有效节点日志'),
        ]];
        $this->removeFromMachine($old, 'moved');
        $this->failNextQueryMatching('/^insert\b.*\bv2_agent_log\b/i');

        $this->postJson(self::URL, $this->auth($machine) + $batch)->assertStatus(500);
        $this->assertDatabaseCount('v2_agent_log', 0);
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->postJson(self::URL, $this->auth($machine) + $batch)->assertOk()
                ->assertJsonPath('data.batch_id', $batch['batch_id']);
        }
        $this->assertDatabaseCount('v2_agent_log', 1);
        $this->assertDatabaseHas('v2_agent_log', [
            'event_key' => hash('sha256', 'machine:' . $machine->id . '|' . $batch['batch_id'] . '|1'),
            'machine_id' => $machine->id, 'node_id' => $current->id,
        ]);
    }
}
