<?php

namespace Tests\Feature;

use App\Services\SubscriptionStatisticsBuffer;
use App\Services\SubscriptionStatisticsWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\Support\SubscriptionRedisConnection;
use Tests\TestCase;

class SubscriptionStatisticsRedisTest extends TestCase
{
    use RefreshDatabase;
    private ?SubscriptionRedisConnection $connection = null;

    protected function setUp(): void
    {
        parent::setUp();
        $port = getenv('YZ_TEST_SUBSCRIPTION_REDIS_PORT');
        if (!$port) $this->markTestSkipped('需要专用本机 Redis 实例');
        $this->connection = new SubscriptionRedisConnection((int) $port, 'subscription-test:' . bin2hex(random_bytes(8)) . ':');
        Redis::shouldReceive('connection')->andReturn($this->connection);
    }

    protected function tearDown(): void
    {
        $this->connection?->close();
        parent::tearDown();
    }

    private function event(): array
    {
        return ['user_id' => 101, 'ip' => '203.0.113.10', 'client' => 'Clash', 'client_version' => '1.0', 'format' => 'Clash', 'status' => 200, 'at' => now()->timestamp];
    }

    public function test_batching_and_restart_after_database_failure_keep_pending_requests(): void
    {
        $buffer = app(SubscriptionStatisticsBuffer::class);
        for ($i = 0; $i < 503; $i++) $buffer->push($this->event());
        $this->failNextQueryMatching('/insert into [`"]v2_subscription_block[`"] /i');
        try { $buffer->flush(); $this->fail('应当写入失败'); } catch (\Illuminate\Database\QueryException) {}
        $this->assertSame(3, $this->connection->llen('{subscription-statistics}:pending'));
        $this->assertDatabaseCount('v2_subscription_stat', 0);
        $this->assertSame(503, (new SubscriptionStatisticsBuffer())->flush());
        $this->assertSame(0, $buffer->flush());
        $this->assertSame(503, (int) DB::table('v2_subscription_stat')->sum('count'));
        $this->assertDatabaseCount('v2_subscription_batch', 2);
    }

    public function test_crash_after_commit_and_before_ack_does_not_count_twice_even_after_compaction(): void
    {
        $buffer = app(SubscriptionStatisticsBuffer::class);
        $buffer->push($this->event());
        $this->connection->failAck = true;
        try { $buffer->flush(); $this->fail('应模拟确认失败'); } catch (\RuntimeException) {}
        $buffer->push($this->event());
        app(SubscriptionStatisticsWriter::class)->compact();
        (new SubscriptionStatisticsBuffer())->flush();
        $this->assertSame(2, (int) DB::table('v2_subscription_stat')->sum('count'));
        $this->assertNull($this->connection->get('{subscription-statistics}:inflight'));
    }

    public function test_buffer_limit_rejects_new_data_and_preserves_existing_requests(): void
    {
        $key = '{subscription-statistics}:pending';
        $this->connection->eval("for i=1,200000 do redis.call('RPUSH', KEYS[1], 'x') end return 1", 1, $key);
        try { app(SubscriptionStatisticsBuffer::class)->push($this->event()); $this->fail('应拒绝超量数据'); } catch (\RuntimeException) {}
        $this->assertSame(200000, $this->connection->llen($key));
    }
}
