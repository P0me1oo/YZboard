<?php

namespace Tests\Feature;

use App\Jobs\NodeUserSyncJob;
use App\Models\GiftCardCode;
use App\Models\GiftCardTemplate;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\User;
use App\Services\AgentLog;
use App\Services\Auth\TotpService;
use App\Services\AuthService;
use App\Services\GiftCardService;
use App\Services\LogSanitizer;
use App\Services\ManagementLogQuery;
use App\Services\NodeReportService;
use App\Services\OrderService;
use App\Services\Plugin\HookManager;
use App\Services\ServerService;
use App\Services\TrafficResetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PanelBugFixTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\IsolatesLogStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->isolateLogStorage();
        Queue::fake([NodeUserSyncJob::class]);
        Http::preventStrayRequests();
        Redis::shouldReceive('publish')->byDefault()->andReturn(1);
    }

    protected function tearDown(): void
    {
        $this->removeLogStorage();
        parent::tearDown();
    }

    private function user(array $extra = []): User
    {
        return User::create(array_merge(['email' => Str::random(12) . '@fixes.example.invalid',
            'password' => password_hash('test-only-password', PASSWORD_DEFAULT),
            'uuid' => Str::uuid()->toString(), 'token' => Str::random(32), 'expired_at' => 0, 'balance' => 0], $extra));
    }

    private function plan(array $extra = []): Plan
    {
        return Plan::create(array_merge(['name' => '修复测试套餐', 'show' => true, 'sell' => true, 'renew' => true,
            'prices' => [Plan::PERIOD_MONTHLY => 10, Plan::PERIOD_RESET_TRAFFIC => 1],
            'transfer_enable' => 10, 'reset_traffic_method' => Plan::RESET_TRAFFIC_MONTHLY], $extra));
    }

    private function order(User $user, Plan $plan, array $extra = []): Order
    {
        return Order::create(array_merge(['user_id' => $user->id, 'plan_id' => $plan->id,
            'type' => Order::TYPE_NEW_PURCHASE, 'period' => Plan::PERIOD_MONTHLY, 'trade_no' => Str::random(20),
            'total_amount' => 1000, 'status' => Order::STATUS_PENDING], $extra));
    }

    private function node(array $extra = []): Server
    {
        return Server::create(array_merge(['name' => '修复测试节点', 'type' => 'vless', 'host' => 'fixes.example.invalid',
            'port' => 443, 'server_port' => 24443, 'rate' => 1, 'group_ids' => [], 'enabled' => true], $extra));
    }

    private function machine(): ServerMachine
    {
        return ServerMachine::create(['name' => '修复测试机器', 'token' => Str::random(32), 'is_active' => true]);
    }

    private function adminUrl(string $path): string
    {
        return '/api/v2/' . hash('crc32b', config('app.key')) . '/' . $path;
    }

    private function gateway(string $code, string $method, array $config): Payment
    {
        \App\Models\Plugin::updateOrCreate(['code' => $code], ['name' => '测试网关', 'version' => '1.0.0',
            'type' => 'payment', 'is_enabled' => true, 'config' => '{}']);
        // Console 启动时可能已加载空插件列表；测试在启动后创建网关，需重新取得插件服务。
        $this->app->forgetInstance(\App\Services\Plugin\PluginManager::class);
        return Payment::create(['name' => '测试网关', 'payment' => $method, 'uuid' => Str::random(8), 'enable' => true, 'config' => $config]);
    }

    public static function nonPaymentEvents(): array
    {
        return [['charge:created'], ['charge:pending'], ['charge:failed'], ['charge:delayed'], ['charge:resolved']];
    }

    #[DataProvider('nonPaymentEvents')]
    public function test_coinbase_non_payment_events_do_not_open_order(string $event): void
    {
        $key = Str::random(24);
        $payment = $this->gateway('coinbase', 'Coinbase', ['coinbase_webhook_key' => $key]);
        $user = $this->user(); $order = $this->order($user, $this->plan(), ['payment_id' => $payment->id]);
        $this->coinbase($payment, $key, ['id' => Str::random(16), 'type' => $event,
            'data' => ['metadata' => ['outTradeNo' => $order->trade_no]]])->assertOk();
        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status);
        $this->assertNull($user->fresh()->plan_id);
    }

    private function coinbase(Payment $payment, string $key, array $event)
    {
        $body = json_encode(['event' => $event]);
        return $this->call('POST', '/api/v1/guest/payment/notify/Coinbase/' . $payment->uuid, [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CC_WEBHOOK_SIGNATURE' => hash_hmac('sha256', $body, $key)], $body);
    }

    public static function confirmedPayments(): array
    {
        return [['10.00', 'CNY', 'CONFIRMED', true], ['0.01', 'CNY', 'CONFIRMED', false],
            ['10.00', 'USD', 'CONFIRMED', false], ['10.00', 'CNY', 'PENDING', false]];
    }

    #[DataProvider('confirmedPayments')]
    public function test_coinbase_requires_confirmed_funds_and_matching_currency(string $amount, string $currency, string $status, bool $paid): void
    {
        $key = Str::random(24); $payment = $this->gateway('coinbase', 'Coinbase', ['coinbase_webhook_key' => $key]);
        $user = $this->user(); $plan = $this->plan(); $order = $this->order($user, $plan, ['payment_id' => $payment->id]);
        $event = ['id' => Str::random(16), 'type' => 'charge:confirmed', 'data' => [
            'metadata' => ['outTradeNo' => $order->trade_no], 'timeline' => [['status' => 'NEW'], ['status' => 'COMPLETED']],
            'pricing' => ['local' => ['amount' => '10.00', 'currency' => $currency]],
            'payments' => [['status' => $status, 'value' => ['local' => ['amount' => $amount, 'currency' => $currency]]]],
        ]];
        $response = $this->coinbase($payment, $key, $event);
        $response->assertStatus($paid ? 200 : 400);
        $this->assertSame($paid ? Order::STATUS_COMPLETED : Order::STATUS_PENDING, $order->fresh()->status);
        $this->assertSame($paid ? $plan->id : null, $user->fresh()->plan_id);
        if ($paid) {
            $expiry = $user->fresh()->expired_at;
            $this->coinbase($payment, $key, $event)->assertOk();
            $this->assertSame($expiry, $user->fresh()->expired_at);
        }
    }

    public static function epayPayments(): array
    {
        return [['TRADE_CLOSED', '10.00', 0, false, 200], ['TRADE_SUCCESS', '0.01', 0, false, 400],
            ['TRADE_SUCCESS', '10.00', 0, true, 200], ['TRADE_SUCCESS', '10.00', 100, false, 400],
            ['TRADE_SUCCESS', '11.00', 100, true, 200], ['TRADE_SUCCESS', '1e1', 0, false, 422]];
    }

    #[DataProvider('epayPayments')]
    public function test_epay_checks_state_amount_and_fee(string $status, string $amount, int $fee, bool $paid, int $responseCode): void
    {
        $key = Str::random(24); $payment = $this->gateway('epay', 'EPay', ['pid' => 'test-only', 'key' => $key]);
        $user = $this->user(); $order = $this->order($user, $this->plan(), ['payment_id' => $payment->id, 'handling_amount' => $fee]);
        $payload = ['pid' => 'test-only', 'out_trade_no' => $order->trade_no, 'trade_no' => Str::random(16),
            'money' => $amount, 'trade_status' => $status];
        ksort($payload); $payload['sign'] = md5(stripslashes(urldecode(http_build_query($payload))) . $key); $payload['sign_type'] = 'MD5';
        $this->post('/api/v1/guest/payment/notify/EPay/' . $payment->uuid, $payload)->assertStatus($responseCode);
        $this->assertSame($paid ? Order::STATUS_COMPLETED : Order::STATUS_PENDING, $order->fresh()->status);
    }

    public function test_payment_callback_cannot_pay_order_of_another_gateway(): void
    {
        $key = Str::random(24); $payment = $this->gateway('epay', 'EPay', ['pid' => 'test-only', 'key' => $key]);
        $other = Payment::create(['name' => '其他网关', 'payment' => 'EPay', 'uuid' => Str::random(8), 'config' => []]);
        $order = $this->order($this->user(), $this->plan(), ['payment_id' => $other->id]);
        $receipt = ['payment_id' => $payment->id, 'amount_cents' => 1000, 'currency' => 'CNY', 'exact_amount' => true];
        $this->assertFalse((new OrderService($order))->paid('test-only', true, $receipt));
        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status);
    }

    public function test_coinbase_rejects_invalid_signature_without_updating_order(): void
    {
        $payment = $this->gateway('coinbase', 'Coinbase', ['coinbase_webhook_key' => Str::random(24)]);
        $order = $this->order($this->user(), $this->plan(), ['payment_id' => $payment->id]);
        $this->coinbase($payment, Str::random(24), ['type' => 'charge:confirmed',
            'data' => ['metadata' => ['outTradeNo' => $order->trade_no]]])->assertStatus(400);
        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status);
    }

    public function test_audit_keeps_safe_scope_and_drops_nested_secrets_and_url_query(): void
    {
        Sanctum::actingAs($this->user(['is_admin' => true]));
        admin_setting(['app_url' => 'https://fixes.example.invalid']);
        $payment = Payment::create(['name' => '测试网关', 'payment' => 'EPay', 'uuid' => Str::random(8), 'config' => []]);
        $secret = Str::random(24);
        $this->postJson($this->adminUrl('payment/save') . '?token=' . $secret, ['id' => $payment->id, 'name' => '测试网关',
            'payment' => 'EPay', 'config' => ['key' => $secret, 'credentials' => ['private_key' => $secret]]])->assertOk();
        $log = DB::table('v2_admin_audit_log')->where('action', 'payment.save')->first();
        $this->assertStringNotContainsString($secret, $log->request_data . $log->uri);
        $this->assertSame(['id' => $payment->id], json_decode($log->request_data, true));
    }

    public function test_bulk_ban_revokes_only_selected_users_and_rolls_back_on_failure(): void
    {
        $admin = $this->user(['is_admin' => true]); $user = $this->user(); $other = $this->user();
        $user->createToken('test-only'); $other->createToken('test-only'); Sanctum::actingAs($admin);
        $this->failNextQueryMatching('/delete from ["`]?personal_access_tokens/i');
        $this->postJson($this->adminUrl('user/ban'), ['scope' => 'selected', 'user_ids' => [$user->id]])->assertStatus(500);
        $this->assertFalse($user->fresh()->banned); $this->assertSame(1, $user->tokens()->count());
        $this->postJson($this->adminUrl('user/ban'), ['scope' => 'selected', 'user_ids' => [$user->id]])->assertOk();
        $this->assertTrue($user->fresh()->banned); $this->assertSame(0, $user->tokens()->count());
        $this->assertFalse($other->fresh()->banned); $this->assertSame(1, $other->tokens()->count());
    }

    public function test_banned_users_and_admins_cannot_use_existing_sessions_or_quick_login(): void
    {
        $user = $this->user(['is_admin' => true]); $session = $user->createToken('test-only');
        $user->update(['banned' => true]);
        $this->getJson('/api/v1/user/info', ['Authorization' => 'Bearer ' . $session->plainTextToken])->assertForbidden();
        $this->getJson($this->adminUrl('logs/user'), ['Authorization' => 'Bearer ' . $session->plainTextToken])->assertForbidden();
        $this->assertNull(AuthService::findUserByBearerToken('Bearer ' . $session->plainTextToken));
    }

    public function test_admin_password_change_revokes_sessions_atomically(): void
    {
        $user = $this->user(); $user->createToken('test-only'); Sanctum::actingAs($this->user(['is_admin' => true]));
        $this->failNextQueryMatching('/delete from ["`]?personal_access_tokens/i');
        $this->postJson($this->adminUrl('user/update'), ['id' => $user->id, 'password' => 'test-new-password'])->assertStatus(500);
        $this->assertTrue(password_verify('test-only-password', $user->fresh()->password));
        $this->assertSame(1, $user->tokens()->count());
        $this->postJson($this->adminUrl('user/update'), ['id' => $user->id, 'password' => 'test-new-password'])->assertOk();
        $this->assertTrue(password_verify('test-new-password', $user->fresh()->password));
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_recovery_codes_reload_locked_user_and_failure_can_retry(): void
    {
        $user = $this->user(['is_admin' => true, 'totp_secret' => \App\Utils\Totp::generateSecret(), 'totp_enabled_at' => time(),
            'totp_recovery_codes' => [password_hash('AAAAA-BBBBB', PASSWORD_DEFAULT)]]);
        $first = $user->fresh(); $second = $user->fresh(); $service = app(TotpService::class);
        $this->failNextQueryMatching('/^update ["`]?v2_user["`]? /i');
        try { $service->verifyCode($first, 'AAAAA-BBBBB'); $this->fail('应报告写入失败'); }
        catch (\Illuminate\Database\QueryException) { $this->assertCount(1, $user->fresh()->totp_recovery_codes); }
        $this->assertTrue($service->verifyCode($first, 'AAAAA-BBBBB'));
        $this->assertFalse($service->verifyCode($second, 'AAAAA-BBBBB'));
    }

    public function test_group_change_captures_old_groups_and_rollback_sends_no_task(): void
    {
        $user = $this->user(['group_id' => 11, 'group_ids' => [11]]); Queue::fake();
        try { DB::transaction(function () use ($user) { $user->update(['group_id' => 22, 'group_ids' => [22]]); throw new \RuntimeException('test rollback'); }); }
        catch (\RuntimeException) {}
        Queue::assertNothingPushed(); $user->refresh();
        DB::transaction(fn () => $user->update(['group_id' => 22, 'group_ids' => [22]]));
        Queue::assertPushed(NodeUserSyncJob::class, fn ($job) => (new \ReflectionProperty($job, 'oldGroupIds'))->getValue($job) === [11]);
    }

    public function test_machine_move_notifies_both_machines_after_commit(): void
    {
        $old = $this->machine(); $new = $this->machine(); $node = $this->node(['machine_id' => $old->id]);
        $messages = []; Redis::shouldReceive('publish')->andReturnUsing(function ($channel, $body) use (&$messages) { $messages[] = json_decode($body, true); return 1; });
        DB::transaction(fn () => $node->update(['machine_id' => $new->id]));
        $this->assertSame([$new->id, $old->id], array_column($messages, 'machine_id'));
    }

    public function test_order_reset_failure_does_not_complete_and_recovery_applies_once(): void
    {
        $plan = $this->plan(); $user = $this->user(['plan_id' => $plan->id, 'expired_at' => time() + 86400,
            'transfer_enable' => 10000, 'u' => 1000, 'd' => 2000]);
        $order = $this->order($user, $plan, ['type' => Order::TYPE_RESET_TRAFFIC, 'period' => Plan::PERIOD_RESET_TRAFFIC]);
        $this->failNextQueryMatching('/insert into ["`]?v2_traffic_reset_logs/i');
        $this->assertFalse((new OrderService($order))->paid('test-only'));
        $this->assertSame(Order::STATUS_PROCESSING, $order->fresh()->status);
        $this->assertSame(1000, $user->fresh()->u); $this->assertSame(2000, $user->fresh()->d);
        $this->assertDatabaseCount('v2_traffic_reset_logs', 0);
        $service = new OrderService($order->fresh()); $service->open(); $service->open();
        $this->assertSame(Order::STATUS_COMPLETED, $order->fresh()->status);
        $this->assertSame(0, $user->fresh()->u); $this->assertSame(1, $user->fresh()->reset_count);
        $this->assertDatabaseCount('v2_traffic_reset_logs', 1);
    }

    public function test_reset_and_recovery_code_do_not_succeed_when_model_save_is_rejected(): void
    {
        $plan = $this->plan();
        $user = $this->user(['is_admin' => true, 'plan_id' => $plan->id, 'expired_at' => time() + 86400, 'u' => 1000,
            'totp_secret' => \App\Utils\Totp::generateSecret(), 'totp_enabled_at' => time(),
            'totp_recovery_codes' => [password_hash('AAAAA-BBBBB', PASSWORD_DEFAULT)]]);
        $reject = true;
        User::updating(fn (User $current) => $reject && $current->id === $user->id ? false : null);
        $this->assertFalse(app(TrafficResetService::class)->performReset($user));
        $this->assertSame(1000, $user->fresh()->u);
        $this->assertDatabaseCount('v2_traffic_reset_logs', 0);
        try { app(TotpService::class)->verifyCode($user, 'AAAAA-BBBBB'); $this->fail('应停止恢复码消费'); }
        catch (\RuntimeException) { $this->assertCount(1, $user->fresh()->totp_recovery_codes); }
    }

    public function test_relay_change_notifies_old_entry_and_new_entry(): void
    {
        $old = $this->node(); $new = $this->node();
        $child = $this->node(['relay_entry_id' => $old->id]);
        \Illuminate\Support\Facades\Cache::put('node_ws_alive:' . $old->id, true);
        \Illuminate\Support\Facades\Cache::put('node_ws_alive:' . $new->id, true);
        $messages = [];
        Redis::shouldReceive('publish')->andReturnUsing(function ($channel, $body) use (&$messages) { $messages[] = json_decode($body, true); return 1; });
        DB::transaction(fn () => $child->update(['relay_entry_id' => $new->id]));
        $this->assertContains($old->id, array_column($messages, 'node_id'));
        $this->assertContains($new->id, array_column($messages, 'node_id'));
    }

    public function test_gift_card_reset_failure_keeps_code_and_rewards_for_retry(): void
    {
        $plan = $this->plan(); $user = $this->user(['plan_id' => $plan->id, 'expired_at' => time() + 86400, 'u' => 1000]);
        $template = GiftCardTemplate::create(['name' => '测试重置卡', 'type' => GiftCardTemplate::TYPE_GENERAL, 'status' => 1,
            'rewards' => ['reset_package' => true, 'balance' => 100], 'admin_id' => 1]);
        $code = GiftCardCode::create(['template_id' => $template->id, 'code' => Str::random(12),
            'status' => GiftCardCode::STATUS_UNUSED, 'usage_count' => 0, 'max_usage' => 1]);
        $this->failNextQueryMatching('/insert into ["`]?v2_traffic_reset_logs/i');
        try { (new GiftCardService($code->code))->setUser($user)->redeem(); $this->fail('应停止兑换'); }
        catch (\App\Exceptions\ApiException) {}
        $this->assertSame(0, $code->fresh()->usage_count); $this->assertSame(0, $user->fresh()->balance); $this->assertSame(1000, $user->fresh()->u);
        (new GiftCardService($code->code))->setUser($user->fresh())->redeem();
        $this->assertSame(1, $code->fresh()->usage_count); $this->assertSame(100, $user->fresh()->balance); $this->assertSame(0, $user->fresh()->u);
    }

    public function test_stale_automatic_reset_preserves_new_traffic(): void
    {
        $plan = $this->plan(); $user = $this->user(['plan_id' => $plan->id, 'expired_at' => time() + 60 * 86400, 'transfer_enable' => 10000, 'u' => 1000]);
        $user->update(['next_reset_at' => time() - 1]);
        $first = $user->fresh(); $second = $user->fresh(); $service = app(TrafficResetService::class);
        $this->assertTrue($service->checkAndReset($first));
        DB::table('v2_user')->where('id', $user->id)->increment('u', 25);
        $this->assertFalse($service->checkAndReset($second));
        $this->assertSame(25, $user->fresh()->u); $this->assertDatabaseCount('v2_traffic_reset_logs', 1);
    }

    public function test_disabled_new_purchase_does_not_block_enabled_renewal(): void
    {
        $plan = $this->plan(['sell' => false]); $user = $this->user(); Sanctum::actingAs($user);
        $this->postJson('/api/v1/user/order/save', ['plan_id' => $plan->id, 'period' => 'month_price'])->assertStatus(400);
        $this->assertDatabaseCount('v2_order', 0);
        $user->update(['plan_id' => $plan->id, 'expired_at' => time() + 86400]);
        $this->postJson('/api/v1/user/order/save', ['plan_id' => $plan->id, 'period' => 'month_price'])->assertOk();
    }

    #[DataProvider('authorizationText')]
    public function test_agent_logs_redact_full_authorization(string $text, string $secret): void
    {
        $machine = $this->machine();
        app(AgentLog::class)->accept(null, $machine, ['batch_id' => str_repeat('a', 32), 'records' => [[
            'source' => 'agent', 'level' => 'error', 'message' => $text, 'occurred_at' => now()->timestamp,
        ]]]);
        $result = app(ManagementLogQuery::class)->fetch(Request::create('/test', 'GET'), 'agent');
        $this->assertStringNotContainsString($secret, $result['list'][0]['message']);
        $this->assertStringNotContainsString($secret, DB::table('v2_agent_log')->value('message'));
    }

    public static function authorizationText(): array
    {
        return [['Authorization: Basic dGVzdDp0ZXN0', 'dGVzdDp0ZXN0'],
            ['Authorization="Digest username=testing, response=test-value"', 'test-value'],
            ['Proxy-Authorization: Bearer test-value', 'test-value']];
    }

    public function test_missing_and_other_users_ticket_are_controlled_errors(): void
    {
        $other = $this->user(); $ticket = \App\Models\Ticket::create(['user_id' => $other->id, 'subject' => '测试', 'level' => 1]);
        Sanctum::actingAs($this->user());
        $this->getJson('/api/v1/user/ticket/fetch?id=999999')->assertStatus(400);
        $this->getJson('/api/v1/user/ticket/fetch?id=' . $ticket->id)->assertStatus(400);
    }

    public function test_limit_receipts_cover_http_retries_no_traffic_and_error_recovery(): void
    {
        $user = $this->user(); $node = $this->node(); $calls = 0; $fail = true;
        HookManager::register('server.limit.exceeded', function () use (&$calls, &$fail) { if ($fail) throw new \RuntimeException('test failure'); $calls++; });
        $events = [['user_id' => $user->id, 'kind' => 'conn', 'limit' => 3, 'observed' => 3, 'count' => 5]];
        try { ServerService::processLimitEvents($node, $events, 'test-retry'); $this->fail('应回滚'); }
        catch (\RuntimeException) {}
        $this->assertDatabaseCount('v2_user_event_log', 0); $this->assertDatabaseCount('v2_node_limit_receipt', 0); $fail = false;
        $request = Request::create('/test', 'POST', ['report_id' => 'test-retry', 'limit_events' => $events]);
        $request->attributes->set('node_info', $node);
        $controller = app(\App\Http\Controllers\V2\Server\ServerController::class);
        $controller->report($request); $controller->report($request);
        $this->assertSame(1, $calls); $this->assertDatabaseCount('v2_user_event_log', 1);
        app(NodeReportService::class)->acceptRealtime($node, ['report_id' => 'realtime-only', 'limit_events' => $events]);
        app(NodeReportService::class)->acceptRealtime($node, ['report_id' => 'realtime-only', 'limit_events' => $events]);
        $this->assertSame(2, $calls); $this->assertDatabaseCount('v2_user_event_log', 2);
    }

    public function test_limit_receipts_survive_service_reload_and_prune_at_retention_boundary(): void
    {
        $user = $this->user(); $node = $this->node();
        $events = [['user_id' => $user->id, 'kind' => 'conn', 'limit' => 3, 'observed' => 3, 'count' => 5]];
        ServerService::processLimitEvents($node, $events, 'test-reloaded');
        app()->forgetInstance(\App\Services\UserEventLog::class);
        ServerService::processLimitEvents($node->fresh(), $events, 'test-reloaded');
        $this->assertDatabaseCount('v2_node_limit_receipt', 1);
        $this->assertDatabaseCount('v2_user_event_log', 1);
        DB::table('v2_node_limit_receipt')->insert(['node_id' => $node->id, 'report_key' => hash('sha256', 'old'),
            'accepted_at' => now()->subDays(31)->timestamp]);
        $this->artisan('management-log:prune')->assertSuccessful();
        $this->assertDatabaseCount('v2_node_limit_receipt', 1);
    }
}
