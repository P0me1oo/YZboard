<?php

namespace Tests\Feature\Admin;

use App\Http\Controllers\V2\Admin\PlanController;
use App\Models\Plan;
use App\Models\User;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 套餐保存：改流量归零方式时，重算存量用户身上的下次归零日期。
 *
 * 用户读取的归零方式来自套餐的实时设置，定时任务只按用户身上存的日期执行。
 * 两者不一致会导致按旧日期多归零一次，或者永远不再归零。
 */
class PlanResetMethodTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Route::post('/_tests/admin-plan/save', [PlanController::class, 'save']);
        Carbon::setTestNow('2026-09-22 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function plan(?int $resetMethod, array $overrides = []): Plan
    {
        return Plan::create(array_replace([
            'name' => 'reset-plan',
            'group_id' => 1,
            'transfer_enable' => 100,
            'speed_limit' => 100,
            'device_limit' => 3,
            'conn_limit' => 64,
            'conn_rate_limit' => 8,
            'reset_traffic_method' => $resetMethod,
            'prices' => [Plan::PERIOD_MONTHLY => 10],
        ], $overrides));
    }

    private function user(Plan $plan, array $overrides = []): User
    {
        static $sequence = 0;
        $sequence++;

        return User::create(array_replace([
            'email' => 'reset' . $sequence . '@example.invalid',
            'password' => password_hash('unused-password', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(),
            'plan_id' => $plan->id,
            'group_id' => $plan->group_id,
            'transfer_enable' => 100 * 1073741824,
            'expired_at' => Carbon::parse('2027-03-15 08:30:00')->timestamp,
            'u' => 3 * 1073741824,
            'd' => 2 * 1073741824,
        ], $overrides));
    }

    /** 以套餐现有字段为基础构造保存请求，只覆盖本次要改的项 */
    private function save(Plan $plan, array $overrides = []): TestResponse
    {
        return $this->postJson('/_tests/admin-plan/save', array_replace([
            'id' => $plan->id,
            'name' => $plan->name,
            'group_id' => $plan->group_id,
            'transfer_enable' => $plan->transfer_enable,
            'speed_limit' => $plan->speed_limit,
            'device_limit' => $plan->device_limit,
            'conn_limit' => $plan->conn_limit,
            'conn_rate_limit' => $plan->conn_rate_limit,
            'reset_traffic_method' => $plan->reset_traffic_method,
            'prices' => [Plan::PERIOD_MONTHLY => 10],
        ], $overrides));
    }

    public function test_changing_reset_method_recalculates_stored_reset_date(): void
    {
        $plan = $this->plan(Plan::RESET_TRAFFIC_MONTHLY);
        $user = $this->user($plan);

        // 按月重置：日期取自到期时间的 15 日 08:30，当月的 15 日已过，落到下月
        $this->assertSame(
            Carbon::parse('2026-10-15 08:30:00')->timestamp,
            $user->fresh()->next_reset_at
        );

        $this->save($plan, ['reset_traffic_method' => Plan::RESET_TRAFFIC_FIRST_DAY_MONTH])
            ->assertOk()
            ->assertJsonPath('data', true);

        // 改成每月 1 号后，日期应重算到下月 1 号
        $this->assertSame(
            Carbon::parse('2026-10-01 00:00:00')->timestamp,
            $user->fresh()->next_reset_at
        );
    }

    public function test_switching_to_never_clears_stored_reset_date(): void
    {
        $plan = $this->plan(Plan::RESET_TRAFFIC_MONTHLY);
        $user = $this->user($plan);
        $this->assertNotNull($user->fresh()->next_reset_at);

        $this->save($plan, ['reset_traffic_method' => Plan::RESET_TRAFFIC_NEVER])->assertOk();

        $this->assertNull($user->fresh()->next_reset_at);
    }

    public function test_switching_back_from_never_fills_the_empty_reset_date(): void
    {
        $plan = $this->plan(Plan::RESET_TRAFFIC_NEVER);
        $user = $this->user($plan);
        // 不重置的套餐下用户身上没有日期，定时任务按非空条件筛选，永远选不到他们
        $this->assertNull($user->fresh()->next_reset_at);

        $this->save($plan, ['reset_traffic_method' => Plan::RESET_TRAFFIC_FIRST_DAY_MONTH])->assertOk();

        $this->assertSame(
            Carbon::parse('2026-10-01 00:00:00')->timestamp,
            $user->fresh()->next_reset_at
        );
    }

    public function test_monthly_reset_keeps_each_users_own_date(): void
    {
        $plan = $this->plan(Plan::RESET_TRAFFIC_FIRST_DAY_MONTH);
        $early = $this->user($plan, ['expired_at' => Carbon::parse('2027-01-05 01:02:03')->timestamp]);
        $late = $this->user($plan, ['expired_at' => Carbon::parse('2027-01-28 22:00:00')->timestamp]);

        $this->save($plan, ['reset_traffic_method' => Plan::RESET_TRAFFIC_MONTHLY])->assertOk();

        // 按月重置各自取到期时间的日和时分秒，两个用户的结果必须不同
        $this->assertSame(
            Carbon::parse('2026-10-05 01:02:03')->timestamp,
            $early->fresh()->next_reset_at
        );
        $this->assertSame(
            Carbon::parse('2026-09-28 22:00:00')->timestamp,
            $late->fresh()->next_reset_at
        );
    }

    public function test_saving_without_changing_reset_method_leaves_the_date_untouched(): void
    {
        $plan = $this->plan(Plan::RESET_TRAFFIC_MONTHLY);
        $user = $this->user($plan);
        $original = $user->fresh()->next_reset_at;

        // 人为改成一个与算法结果不同的日期，用来确认本次保存确实没有重算
        $manual = Carbon::parse('2026-12-31 12:00:00')->timestamp;
        User::where('id', $user->id)->update(['next_reset_at' => $manual]);
        $this->assertNotSame($original, $manual);

        $this->save($plan, ['speed_limit' => 200, 'force_update' => true])->assertOk();

        $this->assertSame($manual, $user->fresh()->next_reset_at);
        $this->assertSame(200, (int) $user->fresh()->speed_limit);
    }

    public function test_recalculation_only_touches_users_of_the_saved_plan(): void
    {
        $target = $this->plan(Plan::RESET_TRAFFIC_MONTHLY);
        $other = $this->plan(Plan::RESET_TRAFFIC_MONTHLY, ['name' => 'other-plan']);
        $targetUser = $this->user($target);
        $otherUser = $this->user($other);
        $otherBefore = $otherUser->fresh()->next_reset_at;
        $planless = $this->user($target, ['plan_id' => null]);

        $this->save($target, ['reset_traffic_method' => Plan::RESET_TRAFFIC_NEVER])->assertOk();

        $this->assertNull($targetUser->fresh()->next_reset_at);
        $this->assertSame($otherBefore, $otherUser->fresh()->next_reset_at);
        $this->assertNull($planless->fresh()->next_reset_at);
        $this->assertSame(Plan::RESET_TRAFFIC_MONTHLY, $other->fresh()->reset_traffic_method);
    }

    public function test_recalculation_does_not_change_expiry_or_used_traffic(): void
    {
        $plan = $this->plan(Plan::RESET_TRAFFIC_MONTHLY);
        $user = $this->user($plan);
        $expiredAt = $user->expired_at;

        $this->save($plan, ['reset_traffic_method' => Plan::RESET_TRAFFIC_FIRST_DAY_YEAR])->assertOk();

        $fresh = $user->fresh();
        $this->assertSame($expiredAt, $fresh->expired_at);
        $this->assertSame(3 * 1073741824, (int) $fresh->u);
        $this->assertSame(2 * 1073741824, (int) $fresh->d);
        $this->assertSame(
            Carbon::parse('2027-01-01 00:00:00')->timestamp,
            $fresh->next_reset_at
        );
    }

    public function test_users_without_expiry_get_no_reset_date(): void
    {
        $plan = $this->plan(Plan::RESET_TRAFFIC_FIRST_DAY_MONTH);
        $permanent = $this->user($plan, ['expired_at' => null]);
        $normal = $this->user($plan);

        $this->save($plan, ['reset_traffic_method' => Plan::RESET_TRAFFIC_MONTHLY])->assertOk();

        // 长期有效的用户没有到期时间，算不出归零日期
        $this->assertNull($permanent->fresh()->next_reset_at);
        $this->assertSame(
            Carbon::parse('2026-10-15 08:30:00')->timestamp,
            $normal->fresh()->next_reset_at
        );
    }
}
