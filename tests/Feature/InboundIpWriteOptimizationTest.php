<?php

namespace Tests\Feature;

use App\Services\InboundIpRecorder;
use App\Services\ServerService;
use App\Services\DeviceStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InboundIpWriteOptimizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'Asia/Shanghai']);
        $this->travelTo(\Carbon\Carbon::parse('2026-10-16 12:00:00', 'Asia/Shanghai'));
    }

    private function writes(callable $work): array
    {
        DB::flushQueryLog(); DB::enableQueryLog();
        try {
            $work();
            return array_values(array_filter(DB::getQueryLog(), fn ($row) => preg_match('/^(insert|update|delete)\b/i', $row['query'])));
        } finally {
            DB::disableQueryLog(); DB::flushQueryLog();
        }
    }

    public function test_cross_node_duplicate_does_not_write_but_keeps_device_updates(): void
    {
        $this->mock(DeviceStateService::class, fn ($mock) => $mock->shouldReceive('replaceNodeDevices')->times(3));
        $alive = [101 => ['8.8.8.8', '::ffff:8.8.8.8', '2400:CB00:1:2::1', '2400:cb00:0001:0002::1']];
        ServerService::processAlive(1, $alive);
        $this->assertSame([], $this->writes(fn () => ServerService::processAlive(2, $alive)));
        $this->travel(1)->seconds();
        $writes = $this->writes(fn () => ServerService::processAlive(1, $alive));
        $this->assertCount(1, $writes);
        $this->assertStringContainsString('v2_stat_user_inbound_ip', $writes[0]['query']);
        $this->assertDatabaseCount('v2_inbound_ip', 2);
        $this->assertDatabaseCount('v2_stat_user_inbound_ip', 2);
    }

    public function test_out_of_order_days_users_and_full_ipv6_addresses_stay_separate(): void
    {
        $recorder = app(InboundIpRecorder::class); $at = now()->timestamp;
        $alive = [101 => ['8.8.8.8', '2400:cb00:1:2::1', '2400:cb00:1:2::2'], 102 => ['8.8.8.8']];
        $recorder->record($alive, $at - 60);
        $recorder->record($alive, $at - 120);
        $recorder->record($alive, $at);
        $this->assertSame([], $this->writes(fn () => $recorder->record($alive, $at - 90)));
        $recorder->record($alive, now()->subDay()->timestamp);
        $recorder->record([101 => ['1.1.1.1']], $at + 1);
        $recorder->record([101 => ['1.1.1.1']], now()->subDays(30)->timestamp);
        $this->assertDatabaseCount('v2_stat_user_inbound_ip', 8);
        $this->assertDatabaseCount('v2_inbound_ip', 3);
        $this->assertSame(4, DB::table('v2_stat_user_inbound_ip')->where('record_at', now()->startOfDay()->timestamp)
            ->where('first_seen_at', $at - 120)->where('last_seen_at', $at)->count());
    }

    public function test_missing_addresses_or_history_are_rebuilt_after_cleanup_and_restore(): void
    {
        $recorder = app(InboundIpRecorder::class); $alive = [101 => ['8.8.8.8']];
        $recorder->record($alive);
        DB::table('v2_inbound_ip')->delete();
        Cache::flush();
        $recorder->record($alive);
        $this->assertDatabaseCount('v2_inbound_ip', 1);
        DB::table('v2_stat_user_inbound_ip')->delete();
        $this->artisan('reset:log')->assertSuccessful();
        $recorder->record($alive);
        $this->assertDatabaseCount('v2_inbound_ip', 1);
        $this->assertDatabaseCount('v2_stat_user_inbound_ip', 1);
        $this->travel(1)->days();
        $recorder->record($alive);
        $this->assertDatabaseCount('v2_stat_user_inbound_ip', 2);
    }

    public function test_loaded_rules_need_no_dedup_cache_when_cache_fails(): void
    {
        $recorder = app(InboundIpRecorder::class); $alive = [101 => ['8.8.8.8']];
        $recorder->record($alive); // 原有设置服务已加载排除规则。
        Cache::shouldReceive('get')->andThrow(new \RuntimeException('测试缓存故障'));
        Cache::shouldReceive('put')->andThrow(new \RuntimeException('测试缓存故障'));
        $this->travel(1)->seconds();
        $recorder->record($alive);
        $this->assertDatabaseHas('v2_stat_user_inbound_ip', ['last_seen_at' => now()->timestamp]);
        $recorder->record([101 => ['1.1.1.1']]);
        $this->assertDatabaseCount('v2_stat_user_inbound_ip', 2);
    }

    public function test_chunk_failure_rolls_back_every_address_and_retry_rebuilds(): void
    {
        $alive = [];
        for ($i = 1; $i <= 270; $i++) $alive[$i] = ['8.8.8.' . (1 + $i % 250)];
        $queries = 0; $fail = true;
        DB::connection()->beforeExecuting(function ($sql, $bindings, $connection) use (&$queries, &$fail): void {
            if ($fail && preg_match('/^insert\b.*v2_stat_user_inbound_ip/i', $sql) && ++$queries === 2) {
                $fail = false;
                throw new \Illuminate\Database\QueryException($connection->getName(), $sql, $bindings, new \RuntimeException('分块失败'));
            }
        });
        try { app(InboundIpRecorder::class)->record($alive); $this->fail('应回滚'); }
        catch (\Illuminate\Database\QueryException) {
            $this->assertDatabaseCount('v2_inbound_ip', 0);
            $this->assertDatabaseCount('v2_stat_user_inbound_ip', 0);
        }
        app(InboundIpRecorder::class)->record($alive);
        $this->assertDatabaseCount('v2_stat_user_inbound_ip', 270);
        $this->assertSame([], $this->writes(fn () => app(InboundIpRecorder::class)->record($alive)));
    }
}
