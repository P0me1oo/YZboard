<?php

namespace App\Services;

use Illuminate\Cache\RedisStore;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Facades\Cache;

/** 仅在一轮同步中复用解码结果；每个账号取得锁后仍从 Redis 核对当前内容。 */
class DeviceCoordinationReadContext
{
    private const MAX_KEYS = 2048;
    private const MAX_BYTES = 8 * 1024 * 1024;
    private array $entries = [];
    private int $bytes = 0;

    public function many(array $keys): array
    {
        if ($keys === []) return [];
        $store = Cache::getStore();
        // 自定义缓存、客户端序列化和压缩继续走原读取，不能误解其格式或绕过扩展行为。
        if (get_class($store) !== RedisStore::class || count($keys) > self::MAX_KEYS) return Cache::many($keys);
        $connection = $store->connection();
        if (get_class($connection) !== PhpRedisConnection::class || $connection->serialized() || $connection->compressed()) {
            return Cache::many($keys);
        }

        $reply = $connection->mget(array_map(fn ($key) => $store->getPrefix() . $key, $keys));
        if (!is_array($reply) || count($reply) !== count($keys)) {
            throw new \RuntimeException('设备协调状态读取失败');
        }

        $result = [];
        foreach (array_values($keys) as $index => $key) {
            $item = $reply[$index];
            if ($item === null) {
                $this->forget($key);
                $result[$key] = null;
            } elseif (isset($this->entries[$key]) && $item === $this->entries[$key]['raw']) {
                $result[$key] = $this->entries[$key]['value'];
            } elseif (is_string($item)) {
                $value = $this->decode($item);
                $result[$key] = $value;
                $this->forget($key);
                $length = strlen($item);
                if (count($this->entries) < self::MAX_KEYS && $this->bytes + $length <= self::MAX_BYTES) {
                    $this->entries[$key] = ['raw' => $item, 'value' => $value, 'bytes' => $length];
                    $this->bytes += $length;
                }
            } else {
                throw new \RuntimeException('设备协调状态响应无效');
            }
        }
        return $result;
    }

    protected function decode(string $raw): array
    {
        $value = @unserialize($raw, ['allowed_classes' => false]);
        if (!is_array($value)) throw new \RuntimeException('设备协调状态格式无效');
        return $value;
    }

    private function forget(string $key): void
    {
        $this->bytes -= $this->entries[$key]['bytes'] ?? 0;
        unset($this->entries[$key]);
    }
}
