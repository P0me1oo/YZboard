<?php

namespace Tests\Unit\Services;

use App\Models\Plan;
use App\Models\TelegramBotBinding;
use App\Models\User;
use App\Services\TelegramBot\MessagePresenter;
use App\Support\Setting;
use App\Utils\Helper;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TelegramBotMessagePresenterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::store('redis')->forever(Setting::CACHE_KEY, ['subscribe_url' => 'https://sub.example.test']);
    }

    #[DataProvider('subscriptionStates')]
    public function test_subscription_status_respects_expiry_boundaries_and_priority(
        ?int $expiresIn,
        array $attributes,
        string $expectedStatus,
    ): void {
        $this->freezeTime();
        $user = (new User())->forceFill(array_merge([
            'banned' => false,
            'token' => Str::random(32),
            'transfer_enable' => 1024,
            'u' => 128,
            'd' => 128,
            'expired_at' => $expiresIn === null ? null : now()->timestamp + $expiresIn,
        ], $attributes));
        $user->setRelation('plan', null);
        $binding = (new TelegramBotBinding())->setRelation('user', $user);

        $reply = app(MessagePresenter::class)->render('subscription', $binding);

        $this->assertContains('状态：' . $expectedStatus, explode("\n", $reply['text']));
    }

    public static function subscriptionStates(): array
    {
        return [
            '超过三天一秒' => [3 * 86400 + 1, [], '正常'],
            '恰好三天' => [3 * 86400, [], '即将到期'],
            '三天内一秒' => [3 * 86400 - 1, [], '即将到期'],
            '到期前一秒' => [1, [], '即将到期'],
            '恰好到期' => [0, [], '已过期'],
            '已经过期' => [-1, [], '已过期'],
            '长期有效' => [null, [], '正常'],
            '封禁优先于即将到期' => [86400, ['banned' => true], '封禁中'],
            '封禁优先于其他异常' => [-1, ['banned' => true, 'transfer_enable' => 0], '封禁中'],
            '未开通优先于即将到期' => [86400, ['transfer_enable' => 0], '未开通'],
            '负流量额度未开通' => [86400, ['transfer_enable' => -1], '未开通'],
            '过期优先于未开通' => [-1, ['transfer_enable' => 0], '已过期'],
            '流量耗尽优先于即将到期' => [86400, ['u' => 512, 'd' => 512], '流量耗尽'],
            '流量超额' => [86400, ['u' => 800, 'd' => 800], '流量耗尽'],
            '长期有效但流量耗尽' => [null, ['u' => 800, 'd' => 800], '流量耗尽'],
            '过期优先于流量耗尽' => [-1, ['u' => 512, 'd' => 512], '已过期'],
        ];
    }

    public function test_repeated_queries_recalculate_status_after_time_passes_and_renewal(): void
    {
        $this->freezeTime();
        $user = (new User())->forceFill([
            'banned' => false,
            'token' => Str::random(32),
            'transfer_enable' => 1024,
            'expired_at' => now()->timestamp + 3 * 86400 + 1,
        ]);
        $user->setRelation('plan', null);
        $binding = (new TelegramBotBinding())->setRelation('user', $user);
        $presenter = app(MessagePresenter::class);
        $original = $user->getAttributes();

        $reply = $presenter->render('subscription', $binding);
        $this->assertContains('状态：正常', explode("\n", $reply['text']));
        $this->assertSame($reply, $presenter->render('subscription', $binding));

        $this->travel(1)->seconds();
        $this->assertContains('状态：即将到期', explode("\n", $presenter->render('subscription', $binding)['text']));

        $this->travel(3 * 86400)->seconds();
        $this->assertContains('状态：已过期', explode("\n", $presenter->render('subscription', $binding)['text']));
        $this->assertSame($original, $user->getAttributes());

        $user->expired_at = now()->timestamp + 4 * 86400;
        $this->assertContains('状态：正常', explode("\n", $presenter->render('subscription', $binding)['text']));
    }

    public function test_subscription_matches_the_combined_template_and_keeps_queries_read_only(): void
    {
        $this->freezeTime();
        $binding = $this->binding([
            'transfer_enable' => (int) round(101.51 * 1073741824),
            'u' => (int) round(20 * 1073741824),
            'd' => (int) round(30.46 * 1073741824),
        ], new Plan(['name' => 'Coding_Plan_Test']));
        $user = $binding->user;
        $before = $user->getAttributes();
        $presenter = app(MessagePresenter::class);

        $reply = $presenter->render('subscription', $binding);

        $this->assertSame(implode("\n", [
            '订阅信息',
            '套餐：Coding_Plan_Test',
            '状态：正常',
            '到期时间：' . date('Y-m-d H:i', $user->expired_at),
            '已用：50.46 GB / 101.51 GB',
            '剩余：51.05 GB',
            '███████░░░░░░░ 49.7%',
            '流量重置时间：16天',
            '',
            '订阅链接',
            Helper::getSubscribeUrl($user->token),
        ]), $reply['text']);
        $this->assertSame([
            [['text' => '重置订阅', 'callback_data' => 'reset_subscription']],
            [['text' => '返回主菜单', 'callback_data' => 'menu']],
        ], $reply['keyboard']);
        $this->assertSame($reply, $presenter->render('link', $binding));
        $this->assertSame($reply, $presenter->render('subscription', $binding));
        $this->assertSame($before, $user->getAttributes());
    }

    #[DataProvider('trafficProgress')]
    public function test_traffic_progress_handles_empty_full_and_invalid_quotas(
        int $totalGb,
        int $usedGb,
        int $filled,
        string $percentage,
    ): void {
        $binding = $this->binding([
            'transfer_enable' => $totalGb * 1073741824,
            'u' => $usedGb * 1073741824,
        ]);

        $lines = explode("\n", app(MessagePresenter::class)->render('subscription', $binding)['text']);

        $this->assertSame(str_repeat('█', $filled) . str_repeat('░', 14 - $filled) . ' ' . $percentage, $lines[6]);
        $this->assertSame('已用：' . number_format($usedGb, 2, '.', '') . ' GB / ' . number_format(max(0, $totalGb), 2, '.', '') . ' GB', $lines[4]);
        $this->assertSame('剩余：' . number_format(max(0, $totalGb - $usedGb), 2, '.', '') . ' GB', $lines[5]);
    }

    public static function trafficProgress(): array
    {
        return [
            '未使用' => [100, 0, 0, '0.0%'],
            '使用一半' => [100, 50, 7, '50.0%'],
            '刚好用尽' => [100, 100, 14, '100.0%'],
            '流量超额' => [100, 150, 14, '100.0%'],
            '零额度' => [0, 10, 0, '0.0%'],
            '负额度' => [-1, 10, 0, '0.0%'],
        ];
    }

    #[DataProvider('resetCountdowns')]
    public function test_reset_countdown_uses_the_saved_schedule_without_resetting_traffic(?int $seconds, string $expected): void
    {
        $this->freezeTime();
        $binding = $this->binding(['next_reset_at' => $seconds === null ? null : now()->timestamp + $seconds]);
        $before = $binding->user->getAttributes();

        $reply = app(MessagePresenter::class)->render('subscription', $binding);

        $this->assertSame($expected, explode("\n", $reply['text'])[7]);
        $this->assertSame($before, $binding->user->getAttributes());
    }

    public static function resetCountdowns(): array
    {
        return [
            '恰好十六天' => [16 * 86400, '流量重置时间：16天'],
            '不足十六天向上取整' => [15 * 86400 + 1, '流量重置时间：16天'],
            '还剩一秒' => [1, '流量重置时间：1天'],
            '恰好到达重置时间' => [0, '已到流量重置时间'],
            '已超过重置时间' => [-1, '已到流量重置时间'],
            '没有自动重置安排' => [null, '不自动重置流量'],
        ];
    }

    public function test_reset_countdown_refreshes_after_time_passes_and_a_new_schedule_is_saved(): void
    {
        $this->freezeTime();
        $binding = $this->binding(['next_reset_at' => now()->timestamp + 86401]);
        $presenter = app(MessagePresenter::class);

        $this->assertContains('流量重置时间：2天', explode("\n", $presenter->render('subscription', $binding)['text']));
        $this->travel(1)->seconds();
        $this->assertContains('流量重置时间：1天', explode("\n", $presenter->render('subscription', $binding)['text']));
        $this->travel(86400)->seconds();
        $this->assertContains('已到流量重置时间', explode("\n", $presenter->render('subscription', $binding)['text']));
        $binding->user->next_reset_at = now()->timestamp + 16 * 86400;
        $this->assertContains('流量重置时间：16天', explode("\n", $presenter->render('subscription', $binding)['text']));
    }

    #[DataProvider('accountPlans')]
    public function test_account_displays_the_current_plan_and_keeps_existing_details(?string $planName): void
    {
        $binding = $this->binding([], $planName === null ? null : new Plan(['name' => $planName]));

        $reply = app(MessagePresenter::class)->render('account', $binding);

        $this->assertContains('当前套餐：' . ($planName ?? '未订购套餐'), explode("\n", $reply['text']));
        $this->assertContains('邮箱：account@example.test', explode("\n", $reply['text']));
        $this->assertContains('账户状态：正常', explode("\n", $reply['text']));
        $this->assertContains('余额：123.45 元', explode("\n", $reply['text']));
        $this->assertSame('unbind', $reply['keyboard'][0][0]['callback_data']);
    }

    public static function accountPlans(): array
    {
        return [
            '已有套餐' => ['Coding_Plan_Test'],
            '未订购套餐' => [null],
        ];
    }

    private function binding(array $attributes = [], ?Plan $plan = null): TelegramBotBinding
    {
        $user = (new User())->forceFill(array_merge([
            'email' => 'account@example.test',
            'token' => Str::random(32),
            'banned' => false,
            'transfer_enable' => 100 * 1073741824,
            'u' => 0,
            'd' => 0,
            'expired_at' => now()->timestamp + 30 * 86400,
            'next_reset_at' => now()->timestamp + 16 * 86400,
            'balance' => 12345,
            'created_at' => now()->timestamp,
        ], $attributes));
        $user->setRelation('plan', $plan);

        return (new TelegramBotBinding())->setRelation('user', $user);
    }
}
