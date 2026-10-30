<?php

namespace App\Services;

use Illuminate\Cache\RedisStore;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\Repository;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Facades\Cache;

/** 调用方必须持有账号锁；每次先读取最新原值，再考虑复用解码结果。 */
class DeviceCoordinationStateReader
{
    private const MIN_BYTES = 4096;
    private const MAX_BYTES = 4 * 1024 * 1024;
    private const MAX_ENTRIES = 128;
    private array $entries = [];
    private int $bytes = 0;

    public function get(string $key): ?array
    {
        $repository = Cache::getFacadeRoot();
        if ($repository instanceof CacheManager) $repository = $repository->store();
        if (get_class($repository) !== Repository::class) return Cache::get($key);
        $store = $repository->getStore();
        if (get_class($store) !== RedisStore::class) return Cache::get($key);
        $connection = $store->connection();
        if (get_class($connection) !== PhpRedisConnection::class || $connection->serialized() || $connection->compressed()) {
            return Cache::get($key);
        }
        $raw = $connection->get($store->getPrefix() . $key);
        $identity = $store->getPrefix() . $key;
        if ($raw === null) {
            $this->forget($identity);
            return null;
        }
        if (!is_string($raw)) throw new \RuntimeException('设备协调状态响应无效');
        $entry = $this->entries[$identity] ?? null;
        if ($entry !== null && $entry['raw'] === $raw) {
            // 移到末尾；返回数组按值隔离，调用方不能修改备忘中的权限状态。
            unset($this->entries[$identity]);
            $this->entries[$identity] = $entry;
            return $entry['value'];
        }
        $this->forget($identity);
        $value = $this->decode($raw);
        $length = strlen($raw);
        if ($length >= self::MIN_BYTES && $length <= self::MAX_BYTES) {
            while ($this->bytes + $length > self::MAX_BYTES || count($this->entries) >= self::MAX_ENTRIES) {
                $this->forget(array_key_first($this->entries));
            }
            $this->entries[$identity] = ['raw' => $raw, 'value' => $value, 'bytes' => $length];
            $this->bytes += $length;
        }
        return $value;
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
