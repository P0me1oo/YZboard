<?php

namespace Tests\Feature\Server;

use App\Models\BalancerDnsPreset;
use App\Models\PowerDnsRecord;
use App\Services\PowerDnsClient;
use App\Services\PowerDnsService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class PowerDnsLockTest extends TestCase
{
    use RefreshDatabase;

    private BalancerDnsPreset $preset;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initializeCache();
        $this->travelTo(now()->startOfSecond());
        Sleep::fake(true, true);
        Http::preventStrayRequests();
        $this->preset = new BalancerDnsPreset();
        $this->preset->forceFill(['id' => 7, 'provider' => 'powerdns', 'domain' => 'example.test',
            'api_url' => 'https://dns-api.example.test', 'server_id' => 'localhost', 'api_token' => 'test-only']);
    }

    protected function initializeCache(): void {}

    protected function tearDown(): void
    {
        Sleep::fake(false);
        parent::tearDown();
    }

    private function lock(): \Illuminate\Contracts\Cache\Lock
    {
        $key = hash('sha256', $this->preset->api_url . '/' . $this->preset->server_id . '/' . $this->preset->domain);
        return Cache::lock('powerdns:zone:' . $key, 180);
    }

    public function test_short_zone_contention_recovers_in_the_same_sync_before_any_remote_read(): void
    {
        $lock = $this->lock();
        $this->assertTrue($lock->get());
        $waited = 0;
        Sleep::whenFakingSleep(function ($duration) use ($lock, &$waited): void {
            Http::assertNothingSent();
            $waited += $duration->totalMilliseconds;
            if ($waited >= 3000) $lock->release();
        });
        Http::fake(['*' => Http::response(['name' => 'example.test.', 'rrsets' => []])]);
        try {
            app(PowerDnsService::class)->reconcile($this->preset, 'pool', 3, [], 60);
        } finally {
            $lock->release();
        }
        $this->assertGreaterThanOrEqual(3000, $waited);
        Http::assertSentCount(1);
        $next = $this->lock();
        $this->assertTrue($next->get());
        $next->release();
    }

    public function test_persistent_contention_is_bounded_and_cannot_touch_remote_or_local_records(): void
    {
        $lock = $this->lock();
        $this->assertTrue($lock->get());
        $started = now()->getTimestampMs();
        try {
            app(PowerDnsService::class)->reconcile($this->preset, 'pool', 3, [], 60);
            $this->fail('区域持续占用时必须退出');
        } catch (LockTimeoutException) {
            $this->assertGreaterThanOrEqual(9000, now()->getTimestampMs() - $started);
            $this->assertLessThanOrEqual(10000, now()->getTimestampMs() - $started);
            Http::assertNothingSent();
            $this->assertSame(0, PowerDnsRecord::count());
            $this->assertFalse($this->lock()->get(), '失败请求不能释放其他任务的锁');
        } finally {
            $lock->release();
        }
    }

    public function test_lock_wait_does_not_restart_the_remote_request_budget(): void
    {
        $lock = $this->lock();
        $this->assertTrue($lock->get());
        $deadlineDuringWait = null;
        Sleep::whenFakingSleep(function () use ($lock, &$deadlineDuringWait): void {
            $deadlineDuringWait = microtime(true) + 25;
            $lock->release();
        });
        $client = $this->mock(PowerDnsClient::class);
        $client->shouldReceive('request')->once()->withArgs(function ($preset, $method, $data, $baseline, $deadline) use (&$deadlineDuringWait): bool {
            $this->assertNotNull($deadlineDuringWait);
            $this->assertLessThan($deadlineDuringWait, $deadline, '请求预算必须在等锁之前建立');
            return $preset === $this->preset && $method === 'get';
        })->andReturn(['rrsets' => []]);
        try {
            app(PowerDnsService::class)->reconcile($this->preset, 'pool', 3, [], 60);
        } finally {
            $lock->release();
        }
    }

    public function test_other_zone_is_not_blocked_and_remote_failure_releases_its_own_lock(): void
    {
        $lock = $this->lock();
        $this->assertTrue($lock->get());
        $other = clone $this->preset;
        $other->domain = 'other.test';
        $client = $this->mock(PowerDnsClient::class);
        $client->shouldReceive('request')->once()->andThrow(new \RuntimeException('test remote failure'));
        try {
            app(PowerDnsService::class)->reconcile($other, 'pool', 4, [], 60);
            $this->fail('远端失败不能确认成功');
        } catch (\RuntimeException $error) {
            $this->assertSame('test remote failure', $error->getMessage());
            Sleep::assertNeverSlept();
            $this->assertFalse($this->lock()->get());
        } finally {
            $lock->release();
        }
        $this->preset = $other;
        $next = $this->lock();
        $this->assertTrue($next->get());
        $next->release();
    }

    public function test_scheduled_front_pools_finish_before_background_ddns_starts(): void
    {
        $events = app(Schedule::class)->events();
        $front = $ddns = null;
        foreach ($events as $index => $event) {
            if (str_contains($event->command ?? '', 'balancer:sync')) $front = $index;
            if (str_contains($event->command ?? '', 'ddns:sync')) $ddns = $index;
        }
        $this->assertNotNull($front);
        $this->assertNotNull($ddns);
        $this->assertLessThan($ddns, $front);
        $this->assertFalse($events[$front]->runInBackground);
        $this->assertTrue($events[$ddns]->runInBackground);
        $this->assertSame('* * * * *', $events[$front]->expression);
        $this->assertSame('* * * * *', $events[$ddns]->expression);
    }
}
