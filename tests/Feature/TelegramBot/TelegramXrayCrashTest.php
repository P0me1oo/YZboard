<?php

namespace Tests\Feature\TelegramBot;

use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\TelegramBotBinding;
use App\Models\TelegramBotConfig;
use App\Models\TelegramBusinessNotification;
use App\Models\User;
use App\Services\AgentLog;
use App\Services\TelegramBot\BusinessNotificationService;
use App\Services\TelegramBot\ConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TelegramXrayCrashTest extends TestCase
{
    use RefreshDatabase;

    private int $failure = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
        Queue::fake();
        TelegramBotConfig::findOrFail(1)->update([
            'token' => '123456789:' . Str::random(35), 'bot_id' => 123456789,
            'enabled' => true, 'notify_xray_crash' => true,
        ]);
        Http::preventStrayRequests();
        Http::fake(fn ($request) => $this->failure
            ? Http::response(['ok' => false, 'error_code' => $this->failure, 'parameters' => ['retry_after' => 180]], $this->failure)
            : Http::response(['ok' => true, 'result' => ['message_id' => 100, 'chat' => ['id' => $request['chat_id'], 'type' => 'private']]]));
    }

    private function admin(array $changes = [], int $chat = 900000001): User
    {
        $user = User::create(array_merge(['email' => Str::random(12) . '@example.test', 'password' => Str::random(32),
            'uuid' => (string) Str::uuid(), 'token' => Str::random(32), 'is_admin' => 1, 'is_staff' => 0, 'banned' => 0], $changes));
        TelegramBotBinding::create(['user_id' => $user->id, 'telegram_id' => $chat]);
        return $user;
    }

    private function node(array $changes = []): Server
    {
        return Server::create(array_merge(['name' => '测试节点', 'type' => 'vless', 'host' => 'node.example.test',
            'port' => 443, 'server_port' => 443, 'rate' => 1, 'enabled' => true], $changes));
    }

    private function batch(array $changes = [], string $id = 'a'): array
    {
        return ['batch_id' => str_repeat($id, 32), 'records' => [array_merge([
            'source' => 'xray', 'level' => 'error', 'event' => 'xray.crash',
            'message' => '重载内核失败，内核已停止 error=listener failed password=temporary-test-secret',
            'occurred_at' => now()->timestamp,
        ], $changes)]];
    }

    private function send(): TelegramBusinessNotification
    {
        $notification = TelegramBusinessNotification::where('kind', 'xray_crash')->firstOrFail();
        app(BusinessNotificationService::class)->send($notification->id);
        return $notification->fresh();
    }

    public function test_only_bound_active_admin_receives_crash_and_retries_do_not_repeat(): void
    {
        $this->admin();
        $this->admin(['is_admin' => 0, 'is_staff' => 1], 900000002);
        $this->admin(['is_admin' => 0], 900000003);
        $this->admin(['banned' => 1], 900000004);
        $node = $this->node();
        $batch = $this->batch();
        $logs = app(AgentLog::class);
        $logs->accept($node, null, $batch);
        $logs->accept($node, null, $batch);
        $this->assertDatabaseCount('v2_telegram_business_notifications', 1);
        $this->assertDatabaseCount('v2_agent_log', 1);
        $this->assertSame('sent', $this->send()->status);
        $this->send();
        Cache::flush();
        $logs->accept($node, null, $batch);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['chat_id'] === 900000001
            && str_contains($request['text'], 'Xray 核心崩溃通知') && str_contains($request['text'], '测试节点')
            && str_contains($request['text'], 'listener failed') && str_contains($request['text'], '发生时间：')
            && !str_contains($request['text'], 'temporary-test-secret'));
        $logs->accept($node, null, $this->batch([], 'b'));
        $this->assertDatabaseCount('v2_telegram_business_notifications', 2);
    }

    public function test_settings_default_off_preserve_old_forms_and_cancel_pending_crashes(): void
    {
        $this->admin();
        app(AgentLog::class)->accept($this->node(), null, $this->batch());
        Sanctum::actingAs(User::where('is_admin', 1)->firstOrFail());
        $url = '/api/v2/' . hash('crc32b', config('app.key')) . '/telegram-bot/group-node-settings';
        $this->postJson($url, ['notify_node_offline' => true, 'node_offline_minutes' => 45])->assertOk()
            ->assertJsonPath('data.notify_xray_crash', true);
        $this->postJson($url, ['notify_xray_crash' => false])->assertOk()
            ->assertJsonPath('data.notify_node_offline', true)->assertJsonPath('data.node_offline_minutes', 45);
        $this->assertSame('cancelled', $this->send()->status);
        $this->postJson($url, ['notify_xray_crash' => 'invalid'])->assertUnprocessable();
        $this->postJson($url, [])->assertUnprocessable();
        $this->postJson($url, ['notify_xray_crash' => true])->assertOk();
        app(AgentLog::class)->accept($this->node(), null, $this->batch([], 'b'));
        $this->assertDatabaseCount('v2_telegram_business_notifications', 2);
        Http::assertNothingSent();
    }

    public function test_machine_report_requires_trusted_node_and_normal_errors_do_not_alert(): void
    {
        $this->admin();
        $machine = ServerMachine::create(['name' => '测试服务器', 'token' => Str::random(32), 'is_active' => true]);
        $node = $this->node(['machine_id' => $machine->id]);
        $logs = app(AgentLog::class);
        $normal = $this->batch(['node_id' => $node->id]);
        unset($normal['records'][0]['event']);
        $logs->accept(null, $machine, $normal);
        $logs->accept(null, $machine, $this->batch([], 'b'));
        $this->assertDatabaseCount('v2_telegram_business_notifications', 0);
        $logs->accept(null, $machine, $this->batch(['node_id' => $node->id], 'c'));
        $this->assertSame('sent', $this->send()->status);
        Http::assertSent(fn ($request) => str_contains($request['text'], '测试服务器'));
        $other = ServerMachine::create(['name' => '其他服务器', 'token' => Str::random(32), 'is_active' => true]);
        try {
            $logs->accept(null, $other, $this->batch(['node_id' => $node->id], 'd'));
            $this->fail('必须拒绝其他服务器节点');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
    }

    public function test_disabled_notification_does_not_backfill_and_invalid_events_are_rejected(): void
    {
        $this->admin();
        $node = $this->node();
        $logs = app(AgentLog::class);
        TelegramBotConfig::findOrFail(1)->update(['notify_xray_crash' => false]);
        $batch = $this->batch();
        $logs->accept($node, null, $batch);
        TelegramBotConfig::findOrFail(1)->update(['notify_xray_crash' => true]);
        $logs->accept($node, null, $batch);
        $this->assertDatabaseCount('v2_telegram_business_notifications', 0);
        foreach ([['source' => 'singbox'], ['level' => 'info'], ['event' => 'unknown']] as $invalid) {
            try {
                $logs->accept($node, null, $this->batch($invalid, 'b'));
                $this->fail('必须拒绝无效核心事件');
            } catch (\Illuminate\Validation\ValidationException) {
                $this->assertDatabaseCount('v2_agent_log', 1);
            }
        }
    }

    public function test_handshake_and_authenticated_node_machine_event_endpoints(): void
    {
        $this->admin();
        $machine = ServerMachine::create(['name' => '测试服务器', 'token' => Str::random(32), 'is_active' => true]);
        $node = $this->node(['machine_id' => $machine->id]);
        $token = Str::random(32);
        admin_setting(['server_token' => $token]);
        \Illuminate\Support\Facades\Route::post('/_tests/server/handshake', [\App\Http\Controllers\V2\Server\ServerController::class, 'handshake'])->middleware('server.v2');
        \Illuminate\Support\Facades\Route::post('/_tests/crash/node', [\App\Http\Controllers\V2\Server\AgentLogController::class, 'report'])->middleware('server.v2');
        \Illuminate\Support\Facades\Route::post('/_tests/crash/machine', [\App\Http\Controllers\V2\Server\MachineController::class, 'agentLog']);
        $this->postJson('/_tests/server/handshake', ['node_id' => $node->id, 'token' => $token])->assertOk()
            ->assertJsonPath('agent_log.version', 1)->assertJsonPath('agent_log.events.0', 'xray.crash');
        $this->postJson('/_tests/crash/node', $this->batch() + ['node_id' => $node->id, 'token' => $token])->assertOk()
            ->assertJsonPath('data.batch_id', str_repeat('a', 32));
        $this->postJson('/_tests/crash/machine', $this->batch(['node_id' => $node->id], 'b')
            + ['machine_id' => $machine->id, 'token' => $machine->token])->assertOk();
        $this->assertDatabaseCount('v2_telegram_business_notifications', 2);
        Http::assertNothingSent();
    }

    #[DataProvider('staleChanges')]
    public function test_delivery_rechecks_permissions_node_and_event_age(string $change): void
    {
        $admin = $this->admin();
        $node = $this->node();
        app(AgentLog::class)->accept($node, null, $this->batch());
        match ($change) {
            'disabled' => $node->update(['enabled' => false]),
            'deleted' => $node->delete(),
            'singbox' => $node->update(['kernel_type' => 'singbox']),
            'staff' => $admin->update(['is_admin' => 0, 'is_staff' => 1]),
            'banned' => $admin->update(['banned' => 1]),
            'unbound' => TelegramBotBinding::where('user_id', $admin->id)->delete(),
            'expired' => $this->travel(61)->minutes(),
            'bot' => TelegramBotConfig::findOrFail(1)->update(['bot_id' => 123456788]),
        };
        $this->assertSame('cancelled', $this->send()->status);
        Http::assertNothingSent();
    }

    public static function staleChanges(): array
    {
        return array_map(fn ($change) => [$change], ['disabled', 'deleted', 'singbox', 'staff', 'banned', 'unbound', 'expired', 'bot']);
    }

    public function test_temporary_delivery_failure_pauses_and_retries_independently(): void
    {
        $this->admin();
        app(AgentLog::class)->accept($this->node(), null, $this->batch());
        TelegramBotConfig::findOrFail(1)->update(['enabled' => false]);
        $this->assertSame('pending', $this->send()->status);
        Http::assertNothingSent();
        TelegramBotConfig::findOrFail(1)->update(['enabled' => true]);
        $this->failure = 429;
        $notification = $this->send();
        $this->assertSame(now()->timestamp + 180, $notification->retry_at);
        $this->send();
        Http::assertSentCount(1);
        $this->travel(181)->seconds();
        $this->failure = 0;
        $this->assertSame('sent', $this->send()->status);
        Http::assertSentCount(2);
    }

    public function test_notification_failure_rolls_back_log_and_retry_registers_once(): void
    {
        $this->admin();
        $node = $this->node();
        $logs = app(AgentLog::class);
        $this->failNextQueryMatching('/insert into ["`]?v2_telegram_business_notifications/i');
        try {
            $logs->accept($node, null, $this->batch());
            $this->fail('应保留日志重试机会');
        } catch (\Illuminate\Database\QueryException) {
            $this->assertDatabaseCount('v2_agent_log', 0);
            $this->assertDatabaseCount('v2_telegram_business_notifications', 0);
        }
        $logs->accept($node, null, $this->batch());
        $this->assertDatabaseCount('v2_telegram_business_notifications', 1);
    }

    public function test_migration_default_and_rollback_preserve_configuration_and_logs(): void
    {
        $migration = require database_path('migrations/2026_10_21_000002_add_telegram_xray_crash_notification.php');
        app(AgentLog::class)->accept($this->node(), null, $this->batch());
        $migration->down();
        $this->assertDatabaseCount('v2_agent_log', 1);
        $migration->up();
        $this->assertFalse(TelegramBotConfig::findOrFail(1)->notify_xray_crash);
        $this->assertTrue(TelegramBotConfig::findOrFail(1)->enabled);
        $this->assertNull(DB::table('v2_agent_log')->value('event'));
    }
}
