<?php

namespace Tests\Feature\TelegramBot;

use App\Models\Server;
use App\Models\TelegramBotBinding;
use App\Models\TelegramBotConfig;
use App\Models\TelegramBotUpdate;
use App\Models\User;
use App\Services\DeviceIpLocationService;
use App\Services\InboundIpRecorder;
use App\Services\IpLocation;
use App\Services\ServerNameHistory;
use App\Services\TelegramBot\ConfigService;
use App\Services\UserRouteTraffic;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class TelegramBotStatisticsTest extends TestCase
{
    use RefreshDatabase;

    private const BOT = 123456789;
    private const SENDER = 900000001;
    private string $secret;
    private int $sequence = 1;
    private array $replies = [];
    private bool $failReply = false;
    private bool $unknownLocation = false;
    private string $region = '测试省测试市';
    private string $network = '测试网络';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'Asia/Shanghai']);
        $this->travelTo(Carbon::parse('2026-10-11 00:15:00', 'Asia/Shanghai'));
        $this->secret = Str::random(48);
        TelegramBotConfig::findOrFail(1)->update([
            'token' => self::BOT . ':' . Str::random(35), 'webhook_secret' => $this->secret,
            'bot_id' => self::BOT, 'bot_username' => 'yz_test_bot', 'enabled' => true,
        ]);
        DB::table('v2_settings')->where('name', 'user_route_statistics_started_at')
            ->update(['value' => (string) now()->subDays(30)->timestamp]);
        $this->mock(IpLocation::class)->shouldReceive('lookup')->andReturnUsing(
            fn () => ['region' => $this->unknownLocation ? '未知' : $this->region,
                'province' => $this->unknownLocation ? '未知' : '测试省']
        );
        $this->mock(DeviceIpLocationService::class)->shouldReceive('lookupMany')->andReturnUsing(
            fn ($ips) => array_fill_keys($ips, [
                'region' => $this->unknownLocation ? '未知' : $this->region,
                'asn' => $this->unknownLocation ? null : 'AS64500',
                'as_name' => $this->unknownLocation ? null : $this->network,
                'location_source' => $this->unknownLocation ? 'local' : 'ip2location',
                'country_code' => 'CN', 'region_name' => 'Guangdong',
                'expires_at' => now()->addDays(30)->timestamp,
            ])
        );
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/*' => function ($request) {
            $method = basename(parse_url($request->url(), PHP_URL_PATH));
            if (in_array($method, ['sendMessage', 'editMessageText'], true)) {
                if ($this->failReply) {
                    $this->failReply = false;
                    return Http::response(['ok' => false], 500);
                }
                $this->replies[] = $request->data();
            }
            return Http::response(['ok' => true, 'result' => true]);
        }]);
    }

    private function user(?int $sender = self::SENDER): User
    {
        $user = User::create(['email' => Str::random(12) . '@example.test', 'password' => Str::random(32),
            'uuid' => (string) Str::uuid(), 'token' => Str::random(32), 'u' => 123, 'd' => 456,
            'balance' => 1000, 'transfer_enable' => 1024 ** 3, 'next_reset_at' => now()->addDays(15)->timestamp]);
        if ($sender !== null) TelegramBotBinding::create(['user_id' => $user->id, 'telegram_id' => $sender]);
        return $user;
    }

    private function node(string $name = '测试节点'): Server
    {
        return Server::create(['name' => $name, 'type' => 'vless', 'host' => 'example.test',
            'port' => 443, 'server_port' => 443, 'rate' => 2, 'group_ids' => [], 'enabled' => true]);
    }

    private function traffic(User $user, Server $node, int $days = 0, float $rate = 2): void
    {
        $day = now()->startOfDay()->subDays($days);
        app(UserRouteTraffic::class)->record($user->id, $node->id, $node->id, 'entry', $rate,
            $day->timestamp, 262144, 786432, (int) (262144 * $rate), (int) (786432 * $rate), $day->addMinute()->timestamp);
    }

    private function ips(User $user, array $ips, int $days = 0): void
    {
        app(InboundIpRecorder::class)->record([$user->id => $ips], now()->subDays($days)->timestamp);
    }

    private function update(string $action, int $sender = self::SENDER): array
    {
        $id = $this->sequence++;
        $message = ['message_id' => $id, 'date' => time(), 'from' => ['id' => $sender, 'is_bot' => false],
            'chat' => ['id' => $sender, 'type' => 'private']];
        if (str_starts_with($action, '/')) return ['update_id' => $id, 'message' => $message + ['text' => $action]];
        $from = $message['from'];
        $message['from'] = ['id' => self::BOT, 'is_bot' => true];
        return ['update_id' => $id, 'callback_query' => ['id' => Str::random(20), 'data' => $action,
            'from' => $from, 'message' => $message]];
    }

    private function webhook(array $update)
    {
        return $this->postJson(ConfigService::WEBHOOK_PATH, $update, ['X-Telegram-Bot-Api-Secret-Token' => $this->secret]);
    }

    private function query(string $action, int $sender = self::SENDER): string
    {
        $this->webhook($this->update($action, $sender))->assertOk();
        return $this->reply()['text'];
    }

    private function reply(): array
    {
        return $this->replies[array_key_last($this->replies)];
    }

    private function buttons(): array
    {
        return array_column(array_merge(...$this->reply()['reply_markup']['inline_keyboard']), 'callback_data', 'text');
    }

    public function test_commands_and_buttons_only_query_the_current_bound_user(): void
    {
        $own = $this->user(); $other = $this->user(self::SENDER + 1);
        $this->ips($own, ['8.8.8.8']); $this->ips($other, ['1.1.1.1']);
        $this->traffic($own, $this->node('本人节点')); $this->traffic($other, $this->node('其他用户节点'));
        foreach (['/ips', '/ips@yz_test_bot', 'inbound:today:1', 'inbound:7d:1', 'inbound:30d:1'] as $action) {
            $text = $this->query($action);
            $this->assertStringContainsString('8.8.8.8', $text);
            $this->assertStringNotContainsString('1.1.1.1', $text);
        }
        foreach (['/traffic', '/traffic@yz_test_bot', 'traffic:today:1', 'traffic:7d:1', 'traffic:30d:1'] as $action) {
            $text = $this->query($action);
            $this->assertStringContainsString('本人节点', $text);
            $this->assertStringNotContainsString('其他用户节点', $text);
        }
        $this->assertStringContainsString('1.1.1.1', $this->query('/ips', self::SENDER + 1));
        $this->assertStringContainsString('其他用户节点', $this->query('/traffic', self::SENDER + 1));
        $stored = TelegramBotUpdate::all()->toJson();
        foreach (['8.8.8.8', '1.1.1.1', '本人节点', $own->token] as $value) $this->assertStringNotContainsString($value, $stored);
    }

    public function test_unbound_group_and_mismatched_private_queries_cannot_read_statistics(): void
    {
        $this->user();
        foreach (['/ips', '/traffic', 'inbound:30d:1', 'traffic:30d:1'] as $action) {
            $this->assertStringContainsString('订阅链接', $this->query($action, self::SENDER + 1));
            $group = $this->update($action);
            if (isset($group['message'])) $group['message']['chat'] = ['id' => -10001, 'type' => 'group'];
            else $group['callback_query']['message']['chat'] = ['id' => -10001, 'type' => 'supergroup'];
            $this->webhook($group)->assertOk();
            $this->assertSame('请私聊机器人后发送 /start。', $this->reply()['text']);
        }
        $count = count($this->replies);
        $mismatch = $this->update('inbound:today:1');
        $mismatch['callback_query']['message']['chat']['id'] = self::SENDER + 1;
        $this->webhook($mismatch)->assertOk();
        $this->assertCount($count, $this->replies);
    }

    public function test_malformed_callbacks_do_not_accept_user_ids_dates_or_invalid_pages(): void
    {
        $this->user();
        foreach (['traffic:today:0', 'inbound:today:-1', 'traffic:today:1000000', 'traffic:today:01',
            'inbound:custom:1', 'inbound:7d:1:2', 'traffic:30d:1?user_id=2', "inbound:to\nday:1"] as $action) {
            $this->assertSame('请发送 /start 打开菜单。', $this->query($action));
        }
    }

    public function test_inbound_dates_deduplicate_full_addresses_and_follow_panel_timezone(): void
    {
        $user = $this->user();
        $this->ips($user, ['8.8.8.8', '2400:cb00:1:2::1', '2400:cb00:1:2::2']);
        $this->ips($user, ['8.8.8.8'], 1);
        $this->ips($user, ['1.1.1.1'], 6); $this->ips($user, ['9.9.9.9'], 7);
        $this->ips($user, ['1.0.0.1'], 29); $this->ips($user, ['8.8.4.4'], 30);
        $today = $this->query('/ips');
        $this->assertStringContainsString('范围：2026-10-11 至 2026-10-11', $today);
        $this->assertStringContainsString('时区：Asia/Shanghai', $today);
        $this->assertStringContainsString('共 3 个 IP', $today);
        $this->assertStringContainsString('首次出现：2026-10-11 00:15:00', $today);
        foreach (['2400:cb00:1:2::1', '2400:cb00:1:2::2'] as $ip) $this->assertStringContainsString($ip, $today);
        $week = $this->query('inbound:7d:1');
        $this->assertStringContainsString('共 4 个 IP', $week);
        $this->assertStringContainsString('首次出现：2026-10-10 00:15:00', $week);
        $this->assertStringNotContainsString('9.9.9.9', $week);
        $month = $this->query('inbound:30d:1');
        $this->assertStringContainsString('共 6 个 IP', $month);
        $this->assertStringNotContainsString('8.8.4.4', $month);
    }

    public function test_inbound_pagination_exclusions_empty_pages_and_location_failure(): void
    {
        $user = $this->user();
        $ips = array_map(fn ($n) => '11.1.0.' . $n, range(1, 11));
        $this->ips($user, $ips);
        $this->assertStringContainsString('第 1 / 3 页', $this->query('inbound:7d:1'));
        $this->assertSame('inbound:7d:2', $this->buttons()['下一页']);
        $this->assertArrayNotHasKey('上一页', $this->buttons());
        $this->assertStringContainsString('第 2 / 3 页', $this->query($this->buttons()['下一页']));
        $this->assertSame('inbound:7d:1', $this->buttons()['上一页']);
        $this->assertSame('inbound:today:1', $this->buttons()['今天']);
        $this->assertStringContainsString('第 3 / 3 页', $this->query('inbound:7d:999999'));
        admin_setting(['device_ip_exclude' => ['11.1.0.0/24']]);
        $this->assertStringContainsString('暂无入站 IP 记录', $this->query('inbound:7d:3'));
        $this->assertArrayNotHasKey('下一页', $this->buttons());
        $this->unknownLocation = true;
        $this->ips($user, ['2400:cb00:1:2::1']);
        $text = $this->query('/ips');
        $this->assertStringContainsString('2400:cb00:1:2::1', $text);
        $this->assertStringContainsString('归属地：未知', $text);
    }

    public function test_traffic_keeps_recorded_rates_and_does_not_count_transit_twice_or_modify_user(): void
    {
        $user = $this->user(); $entry = $this->node('前置节点'); $relay = $this->node('落地节点');
        $this->traffic($user, $entry);
        app(UserRouteTraffic::class)->record($user->id, $entry->id, $relay->id, 'relay', 2,
            now()->startOfDay()->timestamp, 131072, 393216, 0, 0, now()->timestamp);
        $entry->update(['rate' => 9]); $relay->update(['rate' => 9]);
        $before = $user->fresh()->getRawOriginal();
        $rows = DB::table(UserRouteTraffic::TABLE)->get()->toJson();
        $text = $this->query('/traffic');
        $this->assertStringContainsString('实际用量：1.00 MB（上传 256.00 KB / 下载 768.00 KB）', $text);
        $this->assertStringContainsString('扣费流量：2.00 MB（上传 512.00 KB / 下载 1.50 MB）', $text);
        $this->assertStringContainsString('共 2 条明细', $text);
        $this->assertStringContainsString('直连 · 倍率 2', $text);
        $this->assertStringContainsString('中转 · 倍率 2', $text);
        $this->assertSame(2, substr_count($text, '实际：512.00 KB'));
        $this->assertSame(2, substr_count($text, '扣费：1.00 MB'));
        $this->assertSame($text, $this->query('traffic:today:1'));
        Cache::flush();
        $this->assertSame($text, $this->query('traffic:today:1'));
        $this->assertSame($before, $user->fresh()->getRawOriginal());
        $this->assertSame($rows, DB::table(UserRouteTraffic::TABLE)->get()->toJson());
    }

    public function test_traffic_date_ranges_pagination_and_deleted_node_names_preserve_totals(): void
    {
        $user = $this->user(); $node = $this->node('历史节点');
        foreach ([0, 1, 2, 3, 4, 6, 7, 29, 30] as $days) $this->traffic($user, $node, $days, 0.5);
        ServerNameHistory::remember(collect([$node]));
        DB::table('v2_server')->where('id', $node->id)->delete();
        $today = $this->query('/traffic');
        $this->assertStringContainsString('实际用量：1.00 MB', $today);
        $this->assertStringContainsString('历史节点（已删除）', $today);
        $week = $this->query('traffic:7d:1');
        $this->assertStringContainsString('实际用量：6.00 MB', $week);
        $this->assertStringContainsString('扣费流量：3.00 MB', $week);
        $this->assertStringContainsString('第 1 / 2 页', $week);
        $this->assertStringContainsString('倍率 0.5', $week);
        $next = $this->query($this->buttons()['下一页']);
        $this->assertStringContainsString('第 2 / 2 页', $next);
        $this->assertStringContainsString('2026-10-05 · 历史节点', $next);
        $this->assertStringContainsString('实际用量：6.00 MB', $next);
        $this->assertSame($next, $this->query('traffic:7d:999999'));
        $this->assertStringContainsString('实际用量：8.00 MB', $this->query('traffic:30d:1'));
        DB::table(UserRouteTraffic::TABLE)->delete();
        $this->assertStringContainsString('暂无流量记录', $this->query('traffic:7d:2'));
        $this->assertSame('traffic:7d:1', $this->buttons()['刷新']);
    }

    public function test_rate_changes_and_free_traffic_remain_separate_daily_rows(): void
    {
        $user = $this->user(); $node = $this->node();
        foreach ([0, 0.5, 2] as $rate) $this->traffic($user, $node, rate: $rate);
        $text = $this->query('/traffic');
        $this->assertStringContainsString('共 3 条明细', $text);
        $this->assertStringContainsString('实际用量：3.00 MB', $text);
        $this->assertStringContainsString('扣费流量：2.50 MB', $text);
        $this->assertStringContainsString('扣费：0 B（上传 0 B / 下载 0 B）', $text);
        $this->assertStringContainsString('倍率 0.5', $text);
    }

    public function test_old_unnamed_node_traffic_is_not_misreported_as_zero(): void
    {
        $user = $this->user(); $node = $this->node(); $this->traffic($user, $node);
        DB::table('v2_server')->where('id', $node->id)->delete();
        $text = $this->query('/traffic');
        $this->assertStringContainsString('实际用量：1.00 MB', $text);
        $this->assertStringContainsString('有流量，暂无可展示的节点明细', $text);
    }

    public function test_reply_failure_retries_and_duplicate_updates_do_not_repeat_messages(): void
    {
        $user = $this->user(); $this->traffic($user, $this->node());
        $update = $this->update('traffic:7d:1');
        $this->failReply = true;
        $this->webhook($update)->assertStatus(503);
        $this->assertNull(TelegramBotUpdate::first()->sent_at);
        Cache::flush();
        $this->webhook($update)->assertOk();
        $this->webhook($update)->assertOk();
        $this->assertCount(1, $this->replies);
        $this->assertDatabaseCount('v2_telegram_bot_updates', 1);
        $this->assertSame('traffic:7d:1', TelegramBotUpdate::first()->action);
    }

    public function test_database_query_failure_can_be_retried_without_losing_the_requested_page(): void
    {
        $this->user();
        $update = $this->update('inbound:30d:2');
        $this->failNextQueryMatching('/select.*v2_stat_user_inbound_ip/i');
        $this->webhook($update)->assertStatus(503);
        $this->assertNull(TelegramBotUpdate::first()->sent_at);
        $this->webhook($update)->assertOk();
        $this->assertStringContainsString('范围：2026-09-12 至 2026-10-11', $this->reply()['text']);
        $this->assertSame('inbound:30d:2', TelegramBotUpdate::first()->action);
    }

    public function test_rebinding_or_user_deletion_invalidates_pending_statistics_replies(): void
    {
        $old = $this->user(); $new = $this->user(null);
        $this->ips($old, ['8.8.8.8']); $this->ips($new, ['1.1.1.1']);
        $update = $this->update('inbound:today:1');
        $this->failReply = true;
        $this->webhook($update)->assertStatus(503);
        TelegramBotBinding::query()->delete();
        TelegramBotBinding::create(['user_id' => $new->id, 'telegram_id' => self::SENDER]);
        $this->webhook($update)->assertOk();
        $this->assertStringContainsString('操作已经失效', $this->reply()['text']);
        $this->assertStringContainsString('1.1.1.1', $this->query('inbound:today:1'));
        $this->assertStringNotContainsString('8.8.8.8', $this->reply()['text']);
        DB::table('v2_user')->where('id', $new->id)->delete();
        $this->assertStringContainsString('订阅链接', $this->query('/ips'));
    }

    public function test_long_labels_keep_complete_ips_and_messages_within_telegram_limits(): void
    {
        $user = $this->user();
        $this->region = str_repeat('𠀀', 250); $this->network = str_repeat('𠀀', 200);
        $ips = array_map(fn ($n) => '2400:cb00:1234:5678:abcd:ef01:2345:678' . $n, range(1, 5));
        $this->ips($user, $ips);
        $text = $this->query('/ips');
        foreach ($ips as $ip) $this->assertStringContainsString($ip, $text);
        $this->assertLessThanOrEqual(4096, strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')) / 2);
        for ($i = 0; $i < 5; $i++) $this->traffic($user, $this->node(str_repeat('𠀀', 200) . "\n" . $i));
        $text = $this->query('/traffic');
        $this->assertStringContainsString('共 5 条明细', $text);
        $this->assertLessThanOrEqual(4096, strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')) / 2);
        foreach ($this->buttons() as $data) $this->assertLessThanOrEqual(64, strlen($data));
        $this->assertArrayNotHasKey('parse_mode', $this->reply());
        $this->assertTrue($this->reply()['link_preview_options']['is_disabled']);
    }
}
