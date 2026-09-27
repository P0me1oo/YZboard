<?php

namespace Tests\Feature\Admin;

use App\Http\Controllers\V2\Admin\PlanController;
use App\Jobs\NodeGroupSyncJob;
use App\Models\Plan;
use App\Models\Server;
use App\Models\ServerGroup;
use App\Models\User;
use App\Services\ServerService;
use App\Services\UserService;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PlanGroupAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Route::post('/_tests/plan-groups/save', [PlanController::class, 'save']);
        Queue::fake();
    }

    public function test_plan_groups_grant_union_of_nodes_and_force_update_replaces_it(): void
    {
        $groups = collect(['一', '二', '三'])->map(function ($name) {
            $group = new ServerGroup();
            $group->name = $name;
            $group->save();
            return $group;
        });
        [$first, $second, $third] = $groups->all();

        $this->savePlan(null, [$first->id, $second->id])->assertOk();
        $plan = Plan::firstOrFail();
        $this->assertSame([$first->id, $second->id], $plan->effectiveGroupIds());
        $this->assertSame($first->id, $plan->group_id);

        $user = User::create([
            'email' => 'groups@example.invalid',
            'password' => password_hash('unused-password', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(),
            'u' => 0,
            'd' => 0,
            'banned' => 0,
        ]);
        app(UserService::class)->assignPlan($user, $plan, 30);

        $nodes = collect([$first, $second, $third])->map(fn ($group) => Server::create([
            'name' => '节点' . $group->id,
            'type' => Server::TYPE_SHADOWSOCKS,
            'host' => '127.0.0.1',
            'port' => 443,
            'server_port' => 443,
            'rate' => 1,
            'show' => true,
            'group_ids' => [(string) $group->id],
            'protocol_settings' => ['cipher' => '2022-blake3-aes-128-gcm'],
        ]));

        $this->assertEqualsCanonicalizing([$nodes[0]->id, $nodes[1]->id],
            array_column(ServerService::getAvailableServers($user->fresh()), 'id'));
        $this->assertContains($user->id, ServerService::getAvailableUsers($nodes[1])->pluck('id')->all());
        $this->assertNotContains($user->id, ServerService::getAvailableUsers($nodes[2])->pluck('id')->all());

        $this->savePlan($plan, [$second->id, $third->id], true)->assertOk();
        Queue::assertPushed(NodeGroupSyncJob::class);
        $this->assertSame([$second->id, $third->id], $user->fresh()->effectiveGroupIds());
        $this->assertEqualsCanonicalizing([$nodes[1]->id, $nodes[2]->id],
            array_column(ServerService::getAvailableServers($user->fresh()), 'id'));
        $this->assertNotContains($user->id, ServerService::getAvailableUsers($nodes[0])->pluck('id')->all());

        User::withoutEvents(fn () => $user->update(['u' => 100 * 1073741824]));
        Cache::put('node_ws_alive:' . $nodes[1]->id, true);
        Cache::put('node_ws_alive:' . $nodes[2]->id, true);
        Redis::shouldReceive('scard')->once()->with('traffic:pending_check')->andReturn(1);
        Redis::shouldReceive('spop')->once()->with('traffic:pending_check', 1)->andReturn([$user->id]);
        $notified = [];
        Redis::shouldReceive('publish')->twice()->withArgs(function ($channel, $payload) use (&$notified, $user) {
            $message = json_decode($payload, true);
            $notified[] = $message['node_id'];
            return $channel === 'node:push'
                && $message['data']['action'] === 'remove'
                && $message['data']['users'] === [['id' => $user->id]];
        });
        $this->artisan('check:traffic-exceeded')->assertSuccessful();
        $this->assertEqualsCanonicalizing([$nodes[1]->id, $nodes[2]->id], $notified);
    }

    private function savePlan(?Plan $plan, array $groupIds, bool $force = false)
    {
        return $this->postJson('/_tests/plan-groups/save', [
            'id' => $plan?->id,
            'name' => '多组套餐',
            'group_ids' => $groupIds,
            'transfer_enable' => 100,
            'prices' => [Plan::PERIOD_MONTHLY => 10],
            'force_update' => $force,
        ]);
    }
}
