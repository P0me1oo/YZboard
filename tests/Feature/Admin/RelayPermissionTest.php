<?php

namespace Tests\Feature\Admin;

use App\Http\Controllers\V2\Admin\PlanController;
use App\Jobs\NodeGroupSyncJob;
use App\Models\Plan;
use App\Models\Server;
use App\Models\ServerGroup;
use App\Models\User;
use App\Services\NodeControlStateService;
use App\Services\NodeSyncService;
use App\Services\ServerService;
use App\Services\UserService;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RelayPermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_force_update_revokes_only_removed_relay_route_and_changes_control_snapshot(): void
    {
        [$plan, $user, $entry, $child, $entryGroup, $childGroup] = $this->scenario();
        $before = app(NodeControlStateService::class)->snapshot($entry);
        $this->assertEqualsCanonicalizing([$entry->vless_route, $child->vless_route], $before['users'][0]->relay_routes);
        $this->assertContains($child->id, array_column(ServerService::getAvailableServers($user), 'id'));

        $this->savePlan($plan, [$entryGroup->id])->assertOk();
        $this->assertSame([$entryGroup->id], $user->fresh()->effectiveGroupIds());
        $this->assertNotContains($child->id, array_column(ServerService::getAvailableServers($user->fresh()), 'id'));
        $after = app(NodeControlStateService::class)->snapshot($entry);
        $this->assertSame([$entry->vless_route], $after['users'][0]->relay_routes);
        $this->assertSame($before['sequence'] + 1, $after['sequence']);
        $this->assertSame($before['config'], $after['config']);
        $this->assertSame($after['sequence'], (new NodeControlStateService())->snapshot($entry)['sequence']);

        $pushed = [];
        Cache::put('node_ws_alive:' . $entry->id, true);
        Redis::shouldReceive('publish')->andReturnUsing(function ($channel, $payload) use (&$pushed) {
            $message = json_decode($payload, true);
            $pushed[$message['node_id']] = $message['data']['users'];
            return 1;
        });
        Queue::pushed(NodeGroupSyncJob::class)->each(fn ($job) => $job->handle());
        $this->assertSame([$entry->vless_route], $pushed[$entry->id][0]['relay_routes']);

        $this->savePlan($plan, [$entryGroup->id])->assertOk();
        $this->assertSame($after['sequence'], app(NodeControlStateService::class)->snapshot($entry)['sequence']);
        $this->savePlan($plan, [$entryGroup->id, $childGroup->id])->assertOk();
        $this->assertEqualsCanonicalizing([$entry->vless_route, $child->vless_route], ServerService::getAvailableUsers($entry)->first()->relay_routes);
    }

    public function test_child_only_user_reaches_entry_with_only_child_route_and_is_removed_on_revocation(): void
    {
        [$plan, $user, $entry, $child, , $childGroup] = $this->scenario();
        $this->savePlan($plan, [$childGroup->id])->assertOk();
        $this->assertSame([$child->vless_route], ServerService::getAvailableUsers($entry)->first()->relay_routes);
        $this->assertCount(0, ServerService::getAvailableUsers($child));
        Cache::put('node_ws_alive:' . $entry->id, true);
        $pushed = [];
        Redis::shouldReceive('publish')->andReturnUsing(function ($channel, $payload) use (&$pushed) {
            $pushed[] = json_decode($payload, true);
            return 1;
        });
        NodeSyncService::notifyUserChanged($user->fresh());
        $this->assertSame($entry->id, $pushed[0]['node_id']);
        $this->assertSame([$child->vless_route], $pushed[0]['data']['users'][0]['relay_routes']);
        $this->savePlan($plan, [])->assertOk();
        $this->assertCount(0, ServerService::getAvailableUsers($entry));
        $pushed = [];
        NodeSyncService::notifyUsersUpdatedByGroups([$childGroup->id]);
        $this->assertSame($entry->id, $pushed[0]['node_id']);
        $this->assertSame([], $pushed[0]['data']['users']);
    }

    private function scenario(): array
    {
        Queue::fake();
        Route::post('/_tests/relay-permission/save', [PlanController::class, 'save']);
        $groups = collect(['入口组', '落地组'])->map(function ($name) {
            $group = new ServerGroup();
            $group->name = $name;
            $group->save();
            return $group;
        });
        [$entryGroup, $childGroup] = $groups->all();
        $plan = Plan::create(['name' => '线路权限测试', 'group_id' => $entryGroup->id,
            'group_ids' => [$entryGroup->id, $childGroup->id], 'transfer_enable' => 100, 'prices' => ['monthly' => 10]]);
        $user = User::create(['email' => 'relay-permission@example.invalid',
            'password' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true), 'token' => Helper::guid(), 'u' => 0, 'd' => 0, 'banned' => 0]);
        app(UserService::class)->assignPlan($user, $plan, 30);
        $entry = Server::create(['name' => '入口', 'type' => Server::TYPE_VLESS, 'kernel_type' => 'singbox',
            'host' => '127.0.0.1', 'port' => 24443, 'server_port' => 24443, 'rate' => 1, 'show' => true,
            'enabled' => true, 'group_ids' => [(string) $entryGroup->id], 'vless_route' => 11,
            'protocol_settings' => ['network' => 'tcp', 'tls' => 0]]);
        $child = Server::create(['name' => '落地', 'type' => Server::TYPE_SHADOWSOCKS, 'kernel_type' => 'singbox',
            'relay_entry_id' => $entry->id, 'host' => '127.0.0.1', 'port' => 28388, 'server_port' => 28388,
            'rate' => 1, 'show' => true, 'enabled' => true, 'group_ids' => [$childGroup->id], 'vless_route' => 12,
            'protocol_settings' => ['cipher' => 'aes-128-gcm']]);
        return [$plan, $user, $entry, $child, $entryGroup, $childGroup];
    }

    public function test_exhausted_child_only_user_is_removed_from_entry_not_sent_to_landing(): void
    {
        [$plan, $user, $entry, $child, , $childGroup] = $this->scenario();
        $this->savePlan($plan, [$childGroup->id])->assertOk();
        User::withoutEvents(fn () => $user->update(['u' => 100 * 1073741824]));
        Cache::put('node_ws_alive:' . $entry->id, true);
        Cache::put('node_ws_alive:' . $child->id, true);
        Redis::shouldReceive('scard')->once()->with('traffic:pending_check')->andReturn(1);
        Redis::shouldReceive('spop')->once()->with('traffic:pending_check', 1)->andReturn([$user->id]);
        Redis::shouldReceive('publish')->once()->withArgs(function ($channel, $payload) use ($entry) {
            $message = json_decode($payload, true);
            return $channel === 'node:push' && $message['node_id'] === $entry->id
                && $message['event'] === 'sync.users' && $message['data']['users'] === [];
        });
        $this->artisan('check:traffic-exceeded')->assertSuccessful();
    }

    public function test_child_group_changes_refresh_entry_authorization(): void
    {
        [, $user, $entry, $child] = $this->scenario();
        Cache::put('node_ws_alive:' . $entry->id, true);
        $messages = [];
        Redis::shouldReceive('publish')->andReturnUsing(function ($channel, $payload) use (&$messages) {
            $messages[] = json_decode($payload, true);
            return 1;
        });
        $child->update(['group_ids' => []]);
        $updates = array_values(array_filter($messages, fn ($message) => $message['event'] === 'sync.users'));
        $this->assertNotEmpty($updates);
        foreach ($updates as $message) {
            $this->assertSame($entry->id, $message['node_id']);
            $this->assertSame([$entry->vless_route], $message['data']['users'][0]['relay_routes']);
        }
    }

    private function savePlan(Plan $plan, array $groups)
    {
        return $this->postJson('/_tests/relay-permission/save', ['id' => $plan->id, 'name' => $plan->name,
            'group_ids' => $groups, 'transfer_enable' => 100, 'prices' => ['monthly' => 10], 'force_update' => true]);
    }
}
