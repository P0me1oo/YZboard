<?php

namespace Tests\Unit\Services;

use App\Exceptions\ApiException;
use App\Models\GiftCardCode;
use App\Models\GiftCardTemplate;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Services\GiftCardService;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OrderServiceConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_cancel_only_refunds_once_when_called_with_stale_order_models(): void
    {
        $user = $this->makeUser(['balance' => 0]);
        $plan = $this->makePlan();
        $order = $this->makeOrder($user, $plan, [
            'status' => Order::STATUS_PENDING,
            'balance_amount' => 100,
            'total_amount' => 1000,
        ]);

        $firstStaleOrder = Order::findOrFail($order->id);
        $secondStaleOrder = Order::findOrFail($order->id);

        $this->assertTrue((new OrderService($firstStaleOrder))->cancel());
        $this->assertFalse((new OrderService($secondStaleOrder))->cancel());
        $this->assertSame(100, User::findOrFail($user->id)->balance);
    }

    public function test_open_only_applies_subscription_once_when_called_with_stale_order_models(): void
    {
        $user = $this->makeUser([
            'balance' => 0,
            'expired_at' => 0,
            'transfer_enable' => 0,
            'u' => 1073741824,
            'd' => 0,
        ]);
        $plan = $this->makePlan();
        $order = $this->makeOrder($user, $plan, [
            'status' => Order::STATUS_PROCESSING,
            'balance_amount' => 1100,
            'total_amount' => 0,
        ]);

        $before = time();
        $firstStaleOrder = Order::findOrFail($order->id);
        $secondStaleOrder = Order::findOrFail($order->id);

        (new OrderService($firstStaleOrder))->open();
        (new OrderService($secondStaleOrder))->open();

        $user->refresh();
        $this->assertSame(1, $user->reset_count);
        $this->assertSame(Order::STATUS_COMPLETED, Order::findOrFail($order->id)->status);
        $this->assertLessThan($before + (45 * 86400), $user->expired_at);
    }

    public function test_gift_card_redeem_only_grants_rewards_once_for_stale_code_models(): void
    {
        $user = $this->makeUser(['balance' => 0]);
        $template = GiftCardTemplate::create([
            'name' => 'race-test',
            'description' => 'race-test',
            'type' => GiftCardTemplate::TYPE_GENERAL,
            'status' => 1,
            'rewards' => ['balance' => 100],
            'admin_id' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        GiftCardCode::create([
            'template_id' => $template->id,
            'code' => 'RACEUNITTEST',
            'status' => GiftCardCode::STATUS_UNUSED,
            'usage_count' => 0,
            'max_usage' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $firstService = (new GiftCardService('RACEUNITTEST'))->setUser(User::findOrFail($user->id));
        $secondService = (new GiftCardService('RACEUNITTEST'))->setUser(User::findOrFail($user->id));

        $firstService->validate();
        $secondService->validate();
        $firstService->redeem();

        try {
            $secondService->redeem();
            $this->fail('The second stale gift card redeem should fail.');
        } catch (ApiException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }

        $user->refresh();
        $code = GiftCardCode::where('code', 'RACEUNITTEST')->firstOrFail();
        $this->assertSame(100, $user->balance);
        $this->assertSame(1, $code->usage_count);
        $this->assertSame(1, $code->usages()->count());
    }

    public function test_checkout_rejects_negative_order_amount(): void
    {
        $user = $this->makeUser(['balance' => 0]);
        Sanctum::actingAs($user);

        $order = $this->makeOrder($user, $this->makePlan(), [
            'status' => Order::STATUS_PENDING,
            'total_amount' => -1,
        ]);

        $response = $this->postJson('/api/v1/user/order/checkout', [
            'trade_no' => $order->trade_no,
        ]);

        $response->assertStatus(400);
        $this->assertSame(Order::STATUS_PENDING, Order::findOrFail($order->id)->status);
        $this->assertNull(User::findOrFail($user->id)->plan_id);
    }

    public function test_paid_only_opens_once_when_callbacks_use_stale_order_models(): void
    {
        $user = $this->makeUser();
        $order = $this->makeOrder($user, $this->makePlan(), ['total_amount' => 1100]);
        $firstService = new OrderService($order->fresh());
        $secondService = new OrderService($order->fresh());

        $this->assertTrue($firstService->paid('test-first-callback'));
        $firstExpiry = $user->fresh()->expired_at;
        $this->assertTrue($secondService->paid('test-duplicate-callback'));

        $user->refresh();
        $order->refresh();
        $this->assertSame(Order::STATUS_COMPLETED, $order->status);
        $this->assertSame('test-first-callback', $order->callback_no);
        $this->assertSame($firstExpiry, $user->expired_at);
        $this->assertSame(1, $user->reset_count);
    }

    public function test_paid_does_not_reopen_an_order_cancelled_after_the_model_was_loaded(): void
    {
        $user = $this->makeUser();
        $order = $this->makeOrder($user, $this->makePlan(), ['balance_amount' => 100]);
        $paymentService = new OrderService($order->fresh());

        $this->assertTrue((new OrderService($order->fresh()))->cancel());
        $this->assertTrue($paymentService->paid('test-late-callback'));

        $this->assertSame(Order::STATUS_CANCELLED, $order->fresh()->status);
        $this->assertNull($order->fresh()->callback_no);
        $this->assertNull($user->fresh()->plan_id);
        $this->assertSame(100, $user->fresh()->balance);
    }

    public function test_cancel_rolls_back_on_refund_failure_and_can_retry(): void
    {
        $user = $this->makeUser();
        $order = $this->makeOrder($user, $this->makePlan(), ['balance_amount' => 100]);
        $rejectRefund = true;
        User::updating(function (User $updatedUser) use ($user, &$rejectRefund): ?bool {
            return $rejectRefund && $updatedUser->id === $user->id && $updatedUser->isDirty('balance')
                ? false
                : null;
        });
        $service = new OrderService($order);

        $this->assertFalse($service->cancel());
        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status);
        $this->assertSame(0, $user->fresh()->balance);

        $rejectRefund = false;
        $this->assertTrue($service->cancel());
        $this->assertFalse($service->cancel());
        $this->assertSame(Order::STATUS_CANCELLED, $order->fresh()->status);
        $this->assertSame(100, $user->fresh()->balance);
    }

    #[DataProvider('commissionBalances')]
    public function test_commission_is_based_on_payment_after_balance_deduction(
        int $balance,
        int $expectedPayment,
        int $expectedCommission,
    ): void {
        $inviter = $this->makeUser([
            'commission_type' => User::COMMISSION_TYPE_PERIOD,
            'commission_rate' => 10,
        ]);
        $user = $this->makeUser(['balance' => $balance, 'invite_user_id' => $inviter->id]);

        $order = OrderService::createFromRequest($user, $this->makePlan(), Plan::PERIOD_MONTHLY)->fresh();

        $this->assertSame($expectedPayment, $order->total_amount);
        $this->assertSame($balance, (int) $order->balance_amount);
        $this->assertSame($expectedCommission, (int) $order->commission_balance);
        $this->assertSame(0, $user->fresh()->balance);
    }

    public static function commissionBalances(): array
    {
        return [
            '无余额抵扣' => [0, 1100, 110],
            '部分余额抵扣' => [600, 500, 50],
            '全额余额抵扣' => [1100, 0, 0],
        ];
    }

    #[DataProvider('unfinishedOrderStatuses')]
    public function test_duplicate_creation_does_not_charge_a_stale_user_twice(int $status): void
    {
        $user = $this->makeUser(['balance' => 1500]);
        $staleUser = $user->fresh();
        $plan = $this->makePlan();
        $order = OrderService::createFromRequest($user, $plan, Plan::PERIOD_MONTHLY);
        $order->update(['status' => $status]);

        try {
            OrderService::createFromRequest($staleUser, $plan, Plan::PERIOD_MONTHLY);
            $this->fail('已有未完成订单时，应拒绝重复创建。');
        } catch (ApiException $exception) {
            $this->assertSame(
                __('You have an unpaid or pending order, please try again later or cancel it'),
                $exception->getMessage()
            );
        }

        $this->assertSame(1, Order::where('user_id', $user->id)->count());
        $this->assertSame(400, $user->fresh()->balance);
        $this->assertSame($status, $order->fresh()->status);
    }

    public static function unfinishedOrderStatuses(): array
    {
        return [
            '待支付' => [Order::STATUS_PENDING],
            '开通中' => [Order::STATUS_PROCESSING],
        ];
    }

    public function test_gift_card_reloads_a_template_disabled_after_validation(): void
    {
        $user = $this->makeUser();
        $code = $this->makeGiftCard();
        $service = (new GiftCardService($code->code))->setUser($user)->validate();
        $code->template->update(['status' => false]);

        try {
            $service->redeem();
            $this->fail('模板已停用时，应拒绝之前通过验证的兑换请求。');
        } catch (ApiException $exception) {
            $this->assertSame('该礼品卡类型已停用', $exception->getMessage());
        }

        $this->assertSame(0, $user->fresh()->balance);
        $this->assertSame(0, $code->fresh()->usage_count);
        $this->assertSame(0, $code->usages()->count());
    }

    public function test_inviter_reward_failure_rolls_back_redemption_and_allows_retry(): void
    {
        $inviter = $this->makeUser();
        $user = $this->makeUser(['invite_user_id' => $inviter->id]);
        $code = $this->makeGiftCard(['balance' => 100, 'invite_reward_rate' => 0.2]);
        $rejectReward = true;
        User::updating(function (User $updatedUser) use ($inviter, &$rejectReward): ?bool {
            return $rejectReward && $updatedUser->id === $inviter->id && $updatedUser->isDirty('balance')
                ? false
                : null;
        });
        $service = (new GiftCardService($code->code))->setUser($user)->validate();

        try {
            $service->redeem();
            $this->fail('邀请人奖励发放失败时，应回滚整次兑换。');
        } catch (ApiException $exception) {
            $this->assertSame('邀请人余额发放失败', $exception->getMessage());
        }

        $this->assertSame(0, $user->fresh()->balance);
        $this->assertSame(0, $inviter->fresh()->balance);
        $this->assertSame(GiftCardCode::STATUS_UNUSED, $code->fresh()->status);
        $this->assertSame(0, $code->fresh()->usage_count);
        $this->assertSame(0, $code->usages()->count());

        $rejectReward = false;
        $service->redeem();
        $this->assertSame(100, $user->fresh()->balance);
        $this->assertSame(20, $inviter->fresh()->balance);
        $this->assertSame(GiftCardCode::STATUS_USED, $code->fresh()->status);
        $this->assertSame(1, $code->fresh()->usage_count);
        $this->assertSame(1, $code->usages()->count());
    }

    private function makeGiftCard(array $rewards = ['balance' => 100]): GiftCardCode
    {
        $template = GiftCardTemplate::create([
            'name' => '同步回归测试礼品卡',
            'type' => GiftCardTemplate::TYPE_GENERAL,
            'status' => true,
            'rewards' => $rewards,
            'admin_id' => 1,
        ]);

        return GiftCardCode::create([
            'template_id' => $template->id,
            'code' => Str::upper(Str::random(16)),
            'status' => GiftCardCode::STATUS_UNUSED,
            'usage_count' => 0,
            'max_usage' => 1,
        ]);
    }

    private function makeUser(array $overrides = []): User
    {
        return User::create(array_merge([
            'email' => Str::random(16) . '@example.invalid',
            'password' => 'unused',
            'uuid' => (string) Str::uuid(),
            'token' => Str::random(32),
            'balance' => 0,
            'commission_balance' => 0,
            'transfer_enable' => 0,
            'u' => 0,
            'd' => 0,
            'banned' => 0,
            'is_admin' => 0,
            'is_staff' => 0,
            'expired_at' => 0,
            'remind_expire' => 1,
            'remind_traffic' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ], $overrides));
    }

    private function makePlan(array $overrides = []): Plan
    {
        return Plan::create(array_merge([
            'group_id' => null,
            'transfer_enable' => 1111,
            'name' => 'Race Test Plan',
            'speed_limit' => null,
            'show' => 1,
            'sort' => 0,
            'renew' => 1,
            'prices' => [
                Plan::PERIOD_MONTHLY => 11,
            ],
            'reset_traffic_method' => Plan::RESET_TRAFFIC_MONTHLY,
            'capacity_limit' => null,
            'sell' => 1,
            'device_limit' => null,
            'created_at' => time(),
            'updated_at' => time(),
        ], $overrides));
    }

    private function makeOrder(User $user, Plan $plan, array $overrides = []): Order
    {
        return Order::create(array_merge([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'type' => Order::TYPE_NEW_PURCHASE,
            'period' => Plan::PERIOD_MONTHLY,
            'trade_no' => uniqid('race_', true),
            'total_amount' => 0,
            'balance_amount' => 0,
            'status' => Order::STATUS_PENDING,
            'commission_status' => 0,
            'commission_balance' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ], $overrides));
    }
}
