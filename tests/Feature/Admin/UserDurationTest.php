<?php

namespace Tests\Feature\Admin;

use App\Http\Controllers\V2\Admin\UserController;
use App\Jobs\NodeUserSyncJob;
use App\Models\Plan;
use App\Models\User;
use App\Services\TrafficResetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class UserDurationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->freezeTime();
        Route::post('/_tests/user-duration', [UserController::class, 'extendDuration']);
    }

    private function user(?int $expiry): User
    {
        return User::create([
            'email' => 'duration-' . bin2hex(random_bytes(6)) . '@example.invalid',
            'password' => password_hash(bin2hex(random_bytes(12)), PASSWORD_DEFAULT),
            'uuid' => \App\Utils\Helper::guid(true), 'token' => \App\Utils\Helper::guid(),
            'expired_at' => $expiry, 'u' => 123, 'd' => 456,
        ]);
    }

    public function test_only_selected_unexpired_users_are_extended_once_per_request(): void
    {
        $now = now()->timestamp;
        $active = $this->user($now + 3600);
        $expired = $this->user($now - 1);
        $boundary = $this->user($now);
        $permanent = $this->user(null);
        $zero = $this->user(0);
        $other = $this->user($now + 86400);
        $payload = ['user_ids' => [$active->id, $active->id, $expired->id, $boundary->id, $permanent->id, $zero->id, 999999], 'days' => 3];

        $this->postJson('/_tests/user-duration', $payload)->assertOk()
            ->assertJsonPath('data.updated', 1)->assertJsonPath('data.skipped', 5);
        $this->assertSame($now + 3600 + 3 * 86400, $active->fresh()->expired_at);
        $this->assertSame($now - 1, $expired->fresh()->expired_at);
        $this->assertSame($now, $boundary->fresh()->expired_at);
        $this->assertNull($permanent->fresh()->expired_at);
        $this->assertSame(0, $zero->fresh()->expired_at);
        $this->assertSame($now + 86400, $other->fresh()->expired_at);
        $this->assertSame(123, $active->fresh()->u);
        $this->assertSame(456, $active->fresh()->d);

        // 第二次明确执行应继续累加，不能覆盖为“当前时间加天数”。
        $this->postJson('/_tests/user-duration', $payload)->assertOk();
        $this->assertSame($now + 3600 + 6 * 86400, $active->fresh()->expired_at);
    }

    public function test_empty_or_invalid_inputs_never_fall_back_to_all_users(): void
    {
        $user = $this->user(now()->timestamp + 3600);
        foreach ([
            ['days' => 1], ['user_ids' => [], 'days' => 1],
            ['user_ids' => [$user->id], 'days' => 0],
            ['user_ids' => [$user->id], 'days' => -1],
            ['user_ids' => [$user->id], 'days' => 1.5],
            ['user_ids' => [$user->id], 'days' => 36501],
            ['user_ids' => ['invalid'], 'days' => 1],
            ['user_ids' => [-1], 'days' => 1],
            ['user_ids' => array_fill(0, 1001, $user->id), 'days' => 1],
        ] as $payload) {
            $this->postJson('/_tests/user-duration', $payload)->assertUnprocessable();
            $this->assertSame($user->expired_at, $user->fresh()->expired_at);
        }
    }

    public function test_all_ineligible_users_are_skipped_and_restored_users_are_rechecked(): void
    {
        $user = $this->user(now()->timestamp - 1);
        $payload = ['user_ids' => [$user->id], 'days' => 1];
        $this->postJson('/_tests/user-duration', $payload)->assertOk()
            ->assertJsonPath('data.updated', 0)->assertJsonPath('data.skipped', 1);
        $user->update(['expired_at' => now()->timestamp + 1]);
        $this->postJson('/_tests/user-duration', $payload)->assertOk()->assertJsonPath('data.updated', 1);
        $this->assertSame(now()->timestamp + 1 + 86400, $user->fresh()->expired_at);
    }

    public function test_failure_rolls_back_the_whole_selection(): void
    {
        $first = $this->user(now()->timestamp + 3600);
        $overflow = $this->user(253402300799);
        Queue::fake();
        $this->postJson('/_tests/user-duration', ['user_ids' => [$first->id, $overflow->id], 'days' => 1])
            ->assertUnprocessable();
        $this->assertSame($first->expired_at, $first->fresh()->expired_at);
        $this->assertSame($overflow->expired_at, $overflow->fresh()->expired_at);
        Queue::assertNothingPushed();
    }

    public function test_extension_preserves_reset_calculation_and_node_sync(): void
    {
        $plan = Plan::create(['name' => '续期测试套餐', 'group_id' => 1, 'transfer_enable' => 10,
            'reset_traffic_method' => Plan::RESET_TRAFFIC_MONTHLY]);
        $user = $this->user(now()->addMonths(2)->timestamp);
        $user->update(['plan_id' => $plan->id, 'group_id' => 1]);
        Queue::fake();
        $this->postJson('/_tests/user-duration', ['user_ids' => [$user->id], 'days' => 3])->assertOk();
        $user->refresh();
        $this->assertSame(app(TrafficResetService::class)->calculateNextResetTime($user)?->timestamp, $user->next_reset_at);
        Queue::assertPushed(NodeUserSyncJob::class);
    }

    public function test_production_route_requires_admin_authentication(): void
    {
        $route = collect(Route::getRoutes()->getRoutes())->first(fn ($route) =>
            $route->getActionName() === UserController::class . '@extendDuration'
            && in_array('admin', $route->gatherMiddleware()));
        $this->assertNotNull($route);
        $this->postJson('/' . $route->uri(), ['user_ids' => [1], 'days' => 1])->assertForbidden();
    }
}
