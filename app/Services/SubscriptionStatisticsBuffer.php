<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/** 复用现有 Redis 与 stat 队列，先认领整批，数据库提交后才确认删除。 */
class SubscriptionStatisticsBuffer
{
    private const PENDING = '{subscription-statistics}:pending';
    private const INFLIGHT = '{subscription-statistics}:inflight';

    public function push(array $event): void
    {
        $accepted = Redis::connection()->eval(<<<'LUA'
if redis.call('LLEN', KEYS[1]) >= 200000 then return 0 end
redis.call('RPUSH', KEYS[1], ARGV[1])
return 1
LUA, 1, self::PENDING, json_encode($event, JSON_THROW_ON_ERROR));
        if (!$accepted) throw new \RuntimeException('订阅统计缓冲区已满');
    }

    public function flush(int $batches = 20): int
    {
        $count = 0;
        $redis = Redis::connection();
        for ($i = 0; $i < $batches; $i++) {
            $raw = $redis->eval(<<<'LUA'
local batch = redis.call('GET', KEYS[2])
if batch then return batch end
local events = redis.call('LRANGE', KEYS[1], 0, 499)
if #events == 0 then return nil end
batch = cjson.encode({id = ARGV[1], events = events})
redis.call('SET', KEYS[2], batch)
redis.call('LTRIM', KEYS[1], #events, -1)
return batch
LUA, 2, self::PENDING, self::INFLIGHT, bin2hex(random_bytes(16)));
            if (!$raw) break;
            $batch = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            $events = array_map(fn ($event) => json_decode($event, true, 512, JSON_THROW_ON_ERROR), $batch['events']);
            app(SubscriptionStatisticsWriter::class)->write($batch['id'], $events);
            $redis->eval(<<<'LUA'
if redis.call('GET', KEYS[1]) == ARGV[1] then return redis.call('DEL', KEYS[1]) end
return 0
LUA, 1, self::INFLIGHT, $raw);
            $count += count($events);
        }
        return $count;
    }

    public function failure(string $stage): void
    {
        try {
            $key = '{subscription-statistics}:failures:' . now()->format('Y-m-d') . ':' . $stage;
            Redis::connection()->incr($key);
            Redis::connection()->expire($key, 8 * 86400);
        } catch (\Throwable) {
            // Redis 故障时日志中的 count 仍可累计，不附带请求或异常正文。
        }
        try { Log::warning('subscription_statistics_failure', ['stage' => $stage, 'count' => 1]); } catch (\Throwable) {}
    }
}
