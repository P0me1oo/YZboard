<?php

namespace Tests\Feature\Server;

use App\Models\LandingPool;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ServerDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::create(['email' => 'delete-admin@example.test', 'password' => Str::random(32),
            'uuid' => (string) Str::uuid(), 'token' => Str::random(32), 'is_admin' => true]));
    }

    private function path(string $action): string
    {
        return '/api/v2/' . hash('crc32b', config('app.key')) . '/server/manage/' . $action;
    }

    private function node(array $values = []): Server
    {
        return Server::create($values + ['name' => '测试节点', 'type' => 'vless', 'host' => 'example.test',
            'port' => 443, 'server_port' => 443, 'rate' => 1, 'group_ids' => [], 'enabled' => true, 'show' => true]);
    }

    private function pool(Server $primary, array $members): LandingPool
    {
        return LandingPool::create(['name' => '测试落地池', 'node_id' => $primary->id, 'members' => $members,
            'enabled' => true, 'state' => ['active' => ['100' => end($members)], 'members' => ['100' => [['node_id' => end($members)]]]]]);
    }

    private function preview(array $ids): array
    {
        return $this->postJson($this->path('deletionPreview'), ['ids' => $ids])->assertOk()->json('data');
    }

    public static function endpoints(): array
    {
        return [['drop'], ['batchDelete']];
    }

    #[DataProvider('endpoints')]
    public function test_confirmation_is_required_and_unlinks_disables_and_hides_dependents(string $endpoint): void
    {
        $entry = $this->node();
        $shared = $this->node(['parent_id' => $entry->id]);
        $relay = $this->node(['relay_entry_id' => $entry->id]);
        $other = $this->node();
        $data = $endpoint === 'drop' ? ['id' => $entry->id] : ['ids' => [$entry->id]];
        $scope = $this->preview([$entry->id]);
        $this->assertCount(2, $scope['dependents']);
        $this->assertDatabaseCount('v2_stat_server_name', 0);
        $this->postJson($this->path($endpoint), $data)->assertUnprocessable()->assertJsonValidationErrors('confirmation');
        $this->assertTrue($shared->fresh()->enabled);
        $this->postJson($this->path($endpoint), $data + ['confirmation' => $scope['confirmation']])->assertOk()->assertJsonPath('data', true);
        $this->assertDatabaseMissing('v2_server', ['id' => $entry->id]);
        foreach ([$shared, $relay] as $dependent) {
            $this->assertDatabaseHas('v2_server', ['id' => $dependent->id, 'enabled' => false, 'show' => false,
                'parent_id' => null, 'relay_entry_id' => null]);
        }
        $this->assertTrue($other->fresh()->enabled);
    }

    public function test_backup_removal_preserves_pool_with_two_remaining_members_and_clears_old_state(): void
    {
        $primary = $this->node(); $backup = $this->node(); $third = $this->node();
        $pool = $this->pool($primary, [$primary->id, $backup->id, $third->id]);
        $scope = $this->preview([$backup->id]);
        $this->assertFalse($scope['landing_pools'][0]['disable']);
        $this->postJson($this->path('drop'), ['id' => $backup->id, 'confirmation' => $scope['confirmation']])->assertOk();
        $this->assertSame([$primary->id, $third->id], $pool->fresh()->members);
        $this->assertTrue($pool->fresh()->enabled);
        $this->assertSame([], $pool->fresh()->state['active']);
        $this->assertSame([], $pool->fresh()->state['members']);
        $this->assertTrue($primary->fresh()->enabled);
    }

    public function test_pool_with_only_one_member_is_disabled_and_retained(): void
    {
        $primary = $this->node(); $backup = $this->node();
        $pool = $this->pool($primary, [$primary->id, $backup->id]);
        $scope = $this->preview([$backup->id]);
        $this->assertTrue($scope['landing_pools'][0]['disable']);
        $this->postJson($this->path('drop'), ['id' => $backup->id, 'confirmation' => $scope['confirmation']])->assertOk();
        $this->assertSame([$primary->id], $pool->fresh()->members);
        $this->assertFalse($pool->fresh()->enabled);
    }

    public function test_deleted_primary_leaves_disabled_pool_without_dangling_reference(): void
    {
        $primary = $this->node(); $backup = $this->node();
        $pool = $this->pool($primary, [$primary->id, $backup->id]);
        $scope = $this->preview([$primary->id]);
        $this->assertTrue($scope['landing_pools'][0]['primary_deleted']);
        $this->postJson($this->path('drop'), ['id' => $primary->id, 'confirmation' => $scope['confirmation']])->assertOk();
        $this->assertDatabaseHas('v2_landing_pool', ['id' => $pool->id, 'node_id' => null, 'enabled' => false]);
        $this->assertSame([$backup->id], $pool->fresh()->members);
        $this->assertTrue($backup->fresh()->enabled);
    }

    public function test_deleting_entry_disables_indirect_landing_pool_without_deleting_members(): void
    {
        $entry = $this->node();
        $primary = $this->node(['relay_entry_id' => $entry->id]);
        $backup = $this->node(['relay_entry_id' => $entry->id]);
        $pool = $this->pool($primary, [$primary->id, $backup->id]);
        $scope = $this->preview([$entry->id]);
        $this->assertTrue($scope['landing_pools'][0]['disable']);
        $this->postJson($this->path('drop'), ['id' => $entry->id, 'confirmation' => $scope['confirmation']])->assertOk();
        $this->assertFalse($pool->fresh()->enabled);
        $this->assertSame([$primary->id, $backup->id], $pool->fresh()->members);
        $this->assertFalse($primary->fresh()->enabled);
        $this->assertFalse($backup->fresh()->enabled);
    }

    public function test_changed_references_reject_stale_confirmation_but_health_updates_do_not(): void
    {
        $primary = $this->node(); $backup = $this->node();
        $pool = $this->pool($primary, [$primary->id, $backup->id]);
        $old = $this->preview([$primary->id]);
        $child = $this->node(['parent_id' => $primary->id]);
        $this->postJson($this->path('drop'), ['id' => $primary->id, 'confirmation' => $old['confirmation']])
            ->assertUnprocessable()->assertJsonValidationErrors('confirmation');
        $this->assertDatabaseHas('v2_server', ['id' => $primary->id]);
        $scope = $this->preview([$primary->id]);
        $pool->update(['state' => ['active' => [], 'members' => []]]);
        $this->postJson($this->path('drop'), ['id' => $primary->id, 'confirmation' => $scope['confirmation']])->assertOk();
        $this->assertFalse($child->fresh()->enabled);
    }

    public function test_changed_pool_membership_rejects_stale_confirmation(): void
    {
        $primary = $this->node(); $backup = $this->node(); $third = $this->node();
        $pool = $this->pool($primary, [$primary->id, $backup->id]);
        $scope = $this->preview([$backup->id]);
        $pool->update(['members' => [$primary->id, $backup->id, $third->id]]);
        $this->postJson($this->path('drop'), ['id' => $backup->id, 'confirmation' => $scope['confirmation']])
            ->assertUnprocessable()->assertJsonValidationErrors('confirmation');
        $this->assertDatabaseHas('v2_server', ['id' => $backup->id]);
    }

    public function test_batch_selection_does_not_disable_nodes_that_are_also_being_deleted(): void
    {
        $entry = $this->node(); $child = $this->node(['relay_entry_id' => $entry->id]);
        $pool = $this->pool($child, [$child->id]);
        $scope = $this->preview([$entry->id, $child->id]);
        $this->assertSame([], $scope['dependents']);
        $this->postJson($this->path('batchDelete'), ['ids' => [$child->id, $entry->id, $entry->id],
            'confirmation' => $scope['confirmation']])->assertOk();
        $this->assertDatabaseCount('v2_server', 0);
        $this->assertSame([], $pool->fresh()->members);
        $this->assertNull($pool->fresh()->node_id);
        $this->assertDatabaseCount('v2_stat_server_name', 2);
    }

    #[DataProvider('endpoints')]
    public function test_database_failure_rolls_back_all_reference_and_history_changes(string $endpoint): void
    {
        $entry = $this->node(); $child = $this->node(['relay_entry_id' => $entry->id]);
        $pool = $this->pool($entry, [$entry->id, $child->id]);
        $before = $pool->toArray();
        $scope = $this->preview([$entry->id]);
        $payload = ($endpoint === 'drop' ? ['id' => $entry->id] : ['ids' => [$entry->id]]) + ['confirmation' => $scope['confirmation']];
        $this->failNextQueryMatching('/^delete\\b.*\\bv2_server\\b/i');
        $this->postJson($this->path($endpoint), $payload)->assertStatus(500);
        $this->assertEquals($before, $pool->fresh()->toArray());
        $this->assertDatabaseHas('v2_server', ['id' => $child->id, 'relay_entry_id' => $entry->id, 'enabled' => true, 'show' => true]);
        $this->assertDatabaseCount('v2_stat_server_name', 0);
        $this->assertDatabaseCount('v2_scheduling_event', 0);
        $this->postJson($this->path($endpoint), $payload)->assertOk();
    }

    public function test_confirmation_cannot_be_reused_for_different_selection(): void
    {
        $first = $this->node(); $second = $this->node();
        $scope = $this->preview([$first->id]);
        $this->postJson($this->path('drop'), ['id' => $second->id, 'confirmation' => $scope['confirmation']])->assertUnprocessable();
        $this->assertDatabaseCount('v2_server', 2);
    }

    public function test_retry_for_missing_node_does_not_modify_historical_dangling_references(): void
    {
        $child = $this->node(['relay_entry_id' => 99999]);
        $scope = $this->preview([99999]);
        $this->assertSame([], $scope['nodes']);
        $this->assertSame([], $scope['dependents']);
        $this->postJson($this->path('batchDelete'), ['ids' => [99999]])->assertOk();
        $this->assertTrue($child->fresh()->enabled);
        $this->assertSame(99999, $child->fresh()->relay_entry_id);
    }

    public function test_pool_primary_can_be_reassigned_only_after_deletion_cleared_it(): void
    {
        $entry = $this->node(['protocol_settings' => ['tls' => 0]]);
        $machine1 = ServerMachine::create(['name' => '测试机一', 'token' => Str::random(32)]);
        $machine2 = ServerMachine::create(['name' => '测试机二', 'token' => Str::random(32)]);
        $first = $this->node(['relay_entry_id' => $entry->id, 'machine_id' => $machine1->id,
            'type' => 'shadowsocks', 'host' => '192.0.2.1', 'protocol_settings' => ['cipher' => 'aes-128-gcm']]);
        $second = $this->node(['relay_entry_id' => $entry->id, 'machine_id' => $machine2->id,
            'type' => 'shadowsocks', 'host' => '192.0.2.2', 'protocol_settings' => ['cipher' => 'aes-128-gcm']]);
        $pool = LandingPool::create(['name' => '待恢复池', 'node_id' => null, 'enabled' => false, 'members' => [$second->id]]);
        $service = app(\App\Services\LandingPoolService::class);
        $service->save(['id' => $pool->id, 'name' => '已恢复池', 'node_id' => $first->id, 'enabled' => false, 'members' => [$first->id, $second->id]]);
        $this->assertSame($first->id, $pool->fresh()->node_id);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $service->save(['id' => $pool->id, 'name' => '不能随意换主线路', 'node_id' => $second->id, 'enabled' => false, 'members' => [$second->id, $first->id]]);
    }

    public function test_unauthenticated_preview_is_rejected(): void
    {
        $this->app['auth']->forgetGuards();
        $this->postJson($this->path('deletionPreview'), ['ids' => [1]])->assertForbidden();
    }

    public function test_front_pool_reference_still_returns_its_specific_reason(): void
    {
        $node = $this->node();
        \App\Models\BalancerMember::create(['pool_id' => 1, 'source_node_id' => $node->id, 'runtime_node_id' => $node->id]);
        $scope = $this->preview([$node->id]);
        $this->assertSame('节点被前置池引用，请先移除对应成员', $scope['blocked'][0]['message']);
        $this->postJson($this->path('drop'), ['id' => $node->id, 'confirmation' => $scope['confirmation']])
            ->assertUnprocessable()->assertJsonPath('errors.ids.0', '节点被前置池引用，请先移除对应成员');
        $this->assertDatabaseHas('v2_server', ['id' => $node->id]);
    }

    public function test_direct_pool_subscription_line_keeps_its_management_boundary(): void
    {
        $node = $this->node(['balancer_role' => \App\Services\DirectPoolService::ROLE]);
        $scope = $this->preview([$node->id]);
        $this->postJson($this->path('drop'), ['id' => $node->id, 'confirmation' => $scope['confirmation']])
            ->assertUnprocessable()->assertJsonPath('errors.ids.0', '直出订阅线路请在负载池中删除');
    }
}
