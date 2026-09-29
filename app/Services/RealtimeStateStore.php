<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/** 两种通道共用的当前状态；版本与内容保存在同一条缓存记录中。 */
class RealtimeStateStore
{
    public const FRESH_SECONDS = 35;

    public function begin(string $source, string $run): array
    {
        if (!preg_match('/\A[a-f0-9]{32,64}\z/', $run)) {
            throw new UnprocessableEntityHttpException('Invalid runtime identity');
        }

        return Cache::lock($this->key($source) . ':lock', 10)->block(2, function () use ($source, $run): array {
            $current = Cache::get($this->key($source));
            if (is_array($current) && hash_equals($current['run'], $run)) {
                return $this->session($current);
            }
            if (Cache::has($this->retiredKey($source, $run))) {
                throw new ConflictHttpException('Runtime has been superseded');
            }
            if (is_array($current)) {
                Cache::forever($this->retiredKey($source, $current['run']), true);
            }
            $current = [
                'run' => $run,
                'epoch' => bin2hex(random_bytes(16)),
                'sequence' => 0,
                'received_at' => null,
                'data' => null,
            ];
            Cache::forever($this->key($source), $current);
            return $this->session($current);
        });
    }

    public function accept(string $source, string $epoch, int $sequence, array $data): array
    {
        if ($sequence <= 0 || $sequence > 9007199254740991) {
            throw new UnprocessableEntityHttpException('Invalid state sequence');
        }
        return Cache::lock($this->key($source) . ':lock', 10)->block(2, function () use ($source, $epoch, $sequence, $data): array {
            $current = Cache::get($this->key($source));
            if (!is_array($current) || !hash_equals($current['epoch'], $epoch)) {
                // 缓存丢失也必须重新握手，不能让迟到的旧包自行建立新基线。
                throw new ConflictHttpException('Runtime session must be synchronized');
            }
            if ($sequence <= $current['sequence']) {
                if ($sequence === $current['sequence'] && $data !== $current['data']) {
                    throw new ConflictHttpException('State sequence has different contents');
                }
                return $this->session($current) + ['accepted' => false];
            }
            $current['sequence'] = $sequence;
            $current['data'] = $data;
            $current['received_at'] = now()->getTimestampMs();
            // 单次替换同时提交序号、数据和接收时间。锁内不执行数据库或插件操作。
            Cache::forever($this->key($source), $current);
            if (config('cache.default') === 'redis') {
                try {
                    \Illuminate\Support\Facades\Redis::publish('realtime:changed', $source);
                } catch (\Throwable) {
                    // 状态已经可靠替换；广播丢失由订阅端的定时快照检查恢复。
                }
            }
            return $this->session($current) + ['accepted' => true];
        });
    }

    public function read(string $source): ?array
    {
        $current = Cache::get($this->key($source));
        if (!is_array($current)) {
            return null;
        }
        unset($current['run']);
        $current['fresh'] = $current['received_at'] !== null
            && now()->getTimestampMs() - $current['received_at'] <= self::FRESH_SECONDS * 1000;
        return $current;
    }

    public function readMany(array $sources): array
    {
        $records = Cache::many(array_map($this->key(...), $sources));
        $result = [];
        $now = now()->getTimestampMs();
        foreach ($sources as $source) {
            $record = $records[$this->key($source)] ?? null;
            if (is_array($record)) {
                unset($record['run']);
                $record['fresh'] = $record['received_at'] !== null
                    && $now - $record['received_at'] <= self::FRESH_SECONDS * 1000;
            }
            $result[$source] = $record;
        }
        return $result;
    }

    private function session(array $current): array
    {
        return ['epoch' => $current['epoch'], 'sequence' => $current['sequence']];
    }

    private function key(string $source): string
    {
        if (!preg_match('/\A(node|machine):[1-9][0-9]*\z/', $source)) {
            throw new \InvalidArgumentException('Invalid state source');
        }
        return 'realtime:v1:' . $source;
    }

    private function retiredKey(string $source, string $run): string
    {
        return $this->key($source) . ':retired:' . hash('sha256', $run);
    }
}
