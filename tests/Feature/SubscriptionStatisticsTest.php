<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SubscriptionStatisticsBuffer;
use App\Services\SubscriptionStatisticsCapture;
use App\Services\SubscriptionStatisticsWriter;
use App\Services\SubscriptionTimeCodec;
use App\Services\SubscriptionProtection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SubscriptionStatisticsTest extends TestCase
{
    use RefreshDatabase;

    private SubscriptionStatisticsBuffer $buffer;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'Asia/Shanghai', 'v2board' => []]);
        $this->travelTo(\Carbon\Carbon::parse('2026-10-17 12:00:00', 'Asia/Shanghai'));
        $this->buffer = new class extends SubscriptionStatisticsBuffer {
            public array $events = [];
            public array $failures = [];
            public bool $fail = false;
            public function push(array $event): void {
                if ($this->fail) throw new \RuntimeException('故障注入');
                $this->events[] = $event;
            }
            public function failure(string $stage): void { $this->failures[] = $stage; }
        };
        $this->app->instance(SubscriptionStatisticsBuffer::class, $this->buffer);
    }

    private function user(array $extra = []): User
    {
        return User::create($extra + ['email' => Str::random(12) . '@example.test', 'password' => Str::random(32),
            'token' => Str::random(32), 'uuid' => (string) Str::uuid(), 'transfer_enable' => 1024, 'u' => 0, 'd' => 0, 'banned' => false, 'expired_at' => null]);
    }

    private function event(array $extra = []): array
    {
        return $extra + ['user_id' => 101, 'ip' => '2001:db8::1', 'client' => 'Clash', 'client_version' => '1.0',
            'format' => 'Clash', 'status' => 200, 'at' => now()->timestamp];
    }

    private function write(array $events, ?string $id = null): string
    {
        $id ??= bin2hex(random_bytes(16));
        app(SubscriptionStatisticsWriter::class)->write($id, $events);
        return $id;
    }

    private function getStats(string $endpoint, array $params = [])
    {
        return $this->getJson('/api/v2/' . hash('crc32b', config('app.key')) . '/statistics/subscription' . $endpoint . '?' . http_build_query($params));
    }

    public function test_all_download_routes_record_once_and_do_not_confuse_format_with_client(): void
    {
        $user = $this->user();
        foreach (['/s/' . $user->token . '?flag=clash', '/api/v1/client/subscribe?' . http_build_query(['token' => $user->token, 'flag' => 'clash']),
            '/api/v1/client/app/getConfig?' . http_build_query(['token' => $user->token])] as $url) {
            $this->withServerVariables(['REMOTE_ADDR' => '2001:db8::abcd'])->get($url, ['User-Agent' => 'curl/8.0'])->assertOk();
        }
        $this->assertCount(3, $this->buffer->events);
        foreach ($this->buffer->events as $event) {
            $this->assertSame($user->id, $event['user_id']);
            $this->assertSame('curl', $event['client']);
            $this->assertSame('8.0', $event['client_version']);
            $this->assertSame('Clash', $event['format']);
            $this->assertSame('2001:db8::abcd', $event['ip']);
            $this->assertSame(200, $event['status']);
        }
        $serialized = json_encode($this->buffer->events);
        $this->assertStringNotContainsString($user->token, $serialized);
        $this->assertStringNotContainsString($user->uuid, $serialized);
    }

    public function test_known_user_denials_validation_errors_and_generator_errors_are_recorded(): void
    {
        $user = $this->user();
        admin_setting([SubscriptionProtection::SETTING => ['block_browser' => true]]);
        $this->get('/s/' . $user->token, ['User-Agent' => 'Mozilla/5.0'])->assertNotFound();
        admin_setting([SubscriptionProtection::SETTING => []]);
        $this->getJson('/s/' . $user->token . '?flag[]=bad')->assertStatus(422);
        $user->update(['banned' => true]);
        $this->get('/s/' . $user->token)->assertForbidden();
        Route::get('/_tests/subscription-error/{token}', fn () => throw new \RuntimeException('测试生成错误'))
            ->middleware('client')->name('client.subscribe.legacy');
        $this->get('/_tests/subscription-error/' . $user->token)->assertStatus(500);
        $this->assertSame([404, 422, 403, 500], array_column($this->buffer->events, 'status'));
    }

    public function test_invalid_credentials_are_not_attributed_to_previous_authenticated_user(): void
    {
        $user = $this->user();
        $this->get('/s/' . $user->token)->assertOk();
        $this->get('/s/' . Str::random(32))->assertForbidden();
        $this->assertCount(1, $this->buffer->events);
    }

    public function test_statistics_failure_keeps_download_available_and_counts_failure(): void
    {
        $this->buffer->fail = true;
        $this->get('/s/' . $this->user()->token)->assertOk();
        $this->assertSame(['capture'], $this->buffer->failures);
    }

    public function test_client_parser_is_bounded_and_unknown_headers_are_not_saved(): void
    {
        $capture = app(SubscriptionStatisticsCapture::class);
        $this->assertSame(['client' => 'Mihomo', 'client_version' => '1.19.0'], $capture->client('mihomo/v1.19.0'));
        $this->assertSame(['client' => 'unknown', 'client_version' => ''], $capture->client(Str::random(200)));
        $request = Request::create('/');
        $request->attributes->set('subscription_statistics_user_id', 101);
        $capture->record($request, 200);
        $capture->record($request, 500);
        $this->assertCount(1, $this->buffer->events);
    }

    public function test_batch_retry_and_same_second_requests_preserve_exact_counts(): void
    {
        $events = array_fill(0, 60, $this->event());
        $batch = $this->write($events);
        $this->write($events, $batch);
        $this->write([$this->event()]);
        $this->assertDatabaseCount('v2_subscription_stat', 1);
        $this->assertSame(61, (int) DB::table('v2_subscription_stat')->sum('count'));
        $this->assertSame(61, (int) DB::table('v2_subscription_block')->sum('count'));
    }

    public function test_failed_transaction_rolls_back_summary_blocks_and_dedup_marker(): void
    {
        $id = bin2hex(random_bytes(16));
        $this->failNextQueryMatching('/insert into [`"]v2_subscription_block[`"] /i');
        try { $this->write([$this->event()], $id); $this->fail('应当回滚'); } catch (\Illuminate\Database\QueryException) {}
        $this->assertDatabaseCount('v2_subscription_stat', 0);
        $this->assertDatabaseCount('v2_subscription_batch', 0);
        $this->write([$this->event()], $id);
        $this->assertSame(1, (int) DB::table('v2_subscription_stat')->sum('count'));
    }

    public function test_compaction_verifies_roundtrip_keeps_counts_and_retries_do_not_restore_old_blocks(): void
    {
        $id = $this->write([$this->event(['at' => now()->subSecond()->timestamp]), $this->event()]);
        $this->write(array_fill(0, 300, $this->event()));
        $writer = app(SubscriptionStatisticsWriter::class);
        $this->assertSame(2, $writer->compact());
        $this->assertSame(0, $writer->compact());
        $this->assertDatabaseCount('v2_subscription_block', 1);
        $block = (array) DB::table('v2_subscription_block')->first();
        $times = app(SubscriptionTimeCodec::class)->decode($block);
        $this->assertCount(302, $times);
        $this->assertSame(now()->subSecond()->timestamp, $times[0]);
        $this->assertLessThan(150, strlen($block['payload']));
        $this->write([$this->event()], $id);
        $this->assertSame(302, (int) DB::table('v2_subscription_stat')->sum('count'));
    }

    public function test_corrupt_block_aborts_compaction_without_deleting_original_data(): void
    {
        $this->write([$this->event()]); $this->write([$this->event()]);
        DB::table('v2_subscription_block')->where('id', DB::table('v2_subscription_block')->min('id'))->update(['payload' => 'broken']);
        try { app(SubscriptionStatisticsWriter::class)->compact(); $this->fail('应拒绝损坏块'); } catch (\RuntimeException) {}
        $this->assertDatabaseCount('v2_subscription_block', 2);
        $this->assertSame(2, (int) DB::table('v2_subscription_stat')->sum('count'));
        $this->artisan('subscription-statistics:maintain')->assertFailed();
        $this->assertSame(['maintenance'], $this->buffer->failures);
    }

    public function test_retention_boundaries_late_arrivals_and_midnight_recovery(): void
    {
        foreach ([0, 6, 7, 89, 90] as $days) $this->write([$this->event(['at' => now()->startOfDay()->subDays($days)->timestamp])]);
        $this->assertDatabaseCount('v2_subscription_stat', 4);
        $this->assertDatabaseCount('v2_subscription_block', 2);
        $this->travel(1)->days();
        $writer = app(SubscriptionStatisticsWriter::class);
        $writer->prune(); $writer->prune();
        $this->assertDatabaseCount('v2_subscription_stat', 3);
        $this->assertDatabaseCount('v2_subscription_block', 1);
        $this->write([$this->event(['at' => now()->startOfDay()->subDays(7)->timestamp])]);
        $this->assertDatabaseCount('v2_subscription_block', 1);
    }

    public function test_admin_api_cross_day_dedup_filters_daily_totals_and_request_pagination(): void
    {
        $user = $this->user();
        $base = ['user_id' => $user->id];
        $this->write(array_fill(0, 25, $this->event($base)));
        $this->write([$this->event($base + ['at' => now()->subDay()->timestamp, 'status' => 403]),
            $this->event($base + ['ip' => '2001:db8::2', 'client' => 'curl']), $this->event(['user_id' => 4000000000])]);
        $this->getStats('Users')->assertForbidden();
        Sanctum::actingAs($this->user(['is_admin' => true]));
        $this->getStats('Users', ['period' => '7d', 'search' => $user->email])->assertOk()
            ->assertJsonPath('data.total', 1)->assertJsonPath('data.list.0.count', 27)
            ->assertJsonPath('data.list.0.failure_count', 1)->assertJsonPath('data.list.0.ip_count', 2)->assertJsonPath('data.list.0.client_count', 2);
        $this->getStats('Users', ['period' => '7d', 'ip' => '2001:0db8:0:0:0:0:0:1', 'search' => (string) $user->id])
            ->assertOk()->assertJsonPath('data.list.0.count', 26)->assertJsonPath('data.list.0.ip_count', 1);
        $this->getStats('User', $base + ['period' => '7d'])->assertOk()->assertJsonPath('data.total', 2);
        $params = $base + ['period' => '7d', 'ip' => '2001:db8::1', 'client' => 'Clash', 'client_version' => '1.0', 'page_size' => 10];
        $this->getStats('Days', $params)->assertOk()->assertJsonPath('data.total', 2);
        $this->getStats('Events', $params + ['page' => 1])->assertOk()->assertJsonPath('data.total', 26)->assertJsonCount(10, 'data.list');
        app(SubscriptionStatisticsWriter::class)->compact();
        $this->getStats('Events', $params + ['page' => 3])->assertOk()->assertJsonCount(6, 'data.list')->assertJsonPath('data.list.5.status', 403);
        $this->getStats('Users', ['search' => '4000000000'])->assertOk()->assertJsonPath('data.list.0.name', '#4000000000');
        $this->getStats('Users', ['search' => '%'])->assertOk()->assertJsonPath('data.total', 0);
    }

    public function test_range_validation_and_expired_details_do_not_depend_on_cleanup(): void
    {
        $this->write([$this->event()]);
        $this->travel(7)->days();
        Sanctum::actingAs($this->user(['is_admin' => true]));
        $this->getStats('Events', ['period' => '90d', 'user_id' => 101, 'ip' => '2001:db8::1', 'client' => 'Clash', 'client_version' => '1.0'])
            ->assertOk()->assertJsonPath('data.total', 0);
        $this->getStats('Users', ['period' => '90d'])->assertOk()->assertJsonPath('data.list.0.count', 1);
        foreach ([['period' => 'custom', 'start_date' => '2026-01-01', 'end_date' => '2026-10-17'],
            ['period' => 'custom', 'start_date' => '2026-10-20', 'end_date' => '2026-10-18'], ['page_size' => 10000], ['start_time' => '12:00']] as $params) {
            $this->getStats('Users', $params)->assertStatus(422);
        }
    }

    public function test_overlapping_blocks_merge_in_time_order_across_pages(): void
    {
        $expected = [];
        for ($batch = 0; $batch < 5; $batch++) {
            $events = [];
            foreach ([90, 80, 70, 50, 40, 20, 10] as $seconds) {
                $at = now()->subSeconds($seconds + $batch)->timestamp;
                $events[] = $this->event(['at' => $at, 'status' => 500 + $batch]);
                $expected[] = $at;
            }
            $this->write($events);
        }
        rsort($expected, SORT_NUMERIC);
        Sanctum::actingAs($this->user(['is_admin' => true]));
        $actual = [];
        for ($page = 1; $page <= 4; $page++) {
            $response = $this->getStats('Events', ['user_id' => 101, 'ip' => '2001:db8::1', 'client' => 'Clash', 'client_version' => '1.0', 'page_size' => 10, 'page' => $page])
                ->assertOk()->assertJsonPath('data.total', 35);
            foreach ($response->json('data.list') as $row) $actual[] = strtotime($row['at']);
        }
        $this->assertSame($expected, $actual);
    }
}
