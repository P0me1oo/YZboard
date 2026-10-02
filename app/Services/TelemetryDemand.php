<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

/** 页面只续租需要的展示采样；租约过期后自动恢复低频，不依赖浏览器正常退出。 */
class TelemetryDemand
{
    private const KEY = 'realtime:telemetry:demand:v1';
    private const LEASE_SECONDS = 15;
    private array $active = [];
    private int $readAt = 0;
    private array $renewed = [];

    public function renew(array $sources): void
    {
        $now = now()->getTimestampMs();
        $sources = array_values(array_filter(array_unique($sources), fn ($source) =>
            is_string($source) && preg_match('/\A(users|(?:node|machine):[1-9][0-9]*)\z/', $source)
            && $now - ($this->renewed[$source] ?? 0) >= 2000));
        if ($sources === []) return;
        $until = $now + self::LEASE_SECONDS * 1000;
        if (config('cache.default') === 'redis') {
            Redis::eval(<<<'LUA'
for i = 3, #ARGV do
    local old = redis.call('ZSCORE', KEYS[1], ARGV[i])
    if not old or tonumber(old) < tonumber(ARGV[2]) then
        redis.call('ZADD', KEYS[1], ARGV[2], ARGV[i])
    end
end
redis.call('ZREMRANGEBYSCORE', KEYS[1], '-inf', ARGV[1])
redis.call('EXPIRE', KEYS[1], 60)
return 1
LUA, 1, self::KEY, $now, $until, ...$sources);
        } else {
            Cache::lock(self::KEY . ':lock', 5)->block(2, function () use ($sources, $now, $until): void {
                $active = array_filter(Cache::get(self::KEY, []), fn ($expiry) => $expiry > $now);
                foreach ($sources as $source) $active[$source] = max($active[$source] ?? 0, $until);
                Cache::put(self::KEY, $active, 60);
            });
        }
        $this->renewed = array_filter($this->renewed, fn ($at) => $now - $at < 2000);
        foreach ($sources as $source) $this->renewed[$source] = $now;
        $this->readAt = 0;
    }

    public function forSource(string $source): array
    {
        $now = now()->getTimestampMs();
        try {
            if ($this->readAt === 0 || $now - $this->readAt >= 1000) {
                $this->active = config('cache.default') === 'redis'
                    ? Redis::zrangebyscore(self::KEY, '(' . $now, '+inf', ['withscores' => true])
                    : Cache::get(self::KEY, []);
                $this->readAt = $now;
            }
            return [
                'detail_interval' => ($this->active[$source] ?? 0) > $now ? 1 : 60,
                'user_speeds' => str_starts_with($source, 'node:') && ($this->active['users'] ?? 0) > $now,
                'lease_seconds' => 30,
            ];
        } catch (\Throwable) {
            // 无法读取订阅时保持原有完整采样，不能把故障当作无人查看。
            return ['detail_interval' => 1, 'user_speeds' => true, 'lease_seconds' => 30];
        }
    }
}
