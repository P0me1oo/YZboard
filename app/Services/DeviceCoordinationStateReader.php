<?php

namespace App\Services;

use Illuminate\Cache\RedisStore;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\Repository;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\RetrievingKey;
use Illuminate\Cache\Events\RetrievingManyKeys;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Facades\Cache;

/** 每次读取最新原值；批读仅筛选关联，账号锁内仍须单独读取当前权威状态。 */
class DeviceCoordinationStateReader
{
    private const MIN_BYTES = 512;
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
        $events = $repository->getEventDispatcher();
        $storeName = $repository->getName();
        $events?->dispatch(new RetrievingKey($storeName, $key));
        $raw = $connection->get($store->getPrefix() . $key);
        $value = $this->value($store->getPrefix() . $key, $raw);
        $events?->dispatch($value === null ? new CacheMissed($storeName, $key) : new CacheHit($storeName, $key, $value));
        return $value;
    }

    /** 调用方持有节点锁；每批最多 256 个账号，解码备忘受原容量限制。 */
    public function many(array $keys): array
    {
        if ($keys === []) return [];
        $repository = Cache::getFacadeRoot();
        if ($repository instanceof CacheManager) $repository = $repository->store();
        if (get_class($repository) !== Repository::class || !array_is_list($keys) || count($keys) > 256) return Cache::many($keys);
        $store = $repository->getStore();
        if (get_class($store) !== RedisStore::class) return Cache::many($keys);
        $connection = $store->connection();
        if (get_class($connection) !== PhpRedisConnection::class || $connection->serialized() || $connection->compressed()) {
            return Cache::many($keys);
        }

        $events = $repository->getEventDispatcher();
        $storeName = $repository->getName();
        $events?->dispatch(new RetrievingManyKeys($storeName, $keys));
        /** @var mixed $raw 外部响应先校验形状，不能仅依赖客户端的返回类型声明。 */
        $raw = $connection->mget(array_map(fn ($key) => $store->getPrefix() . $key, $keys));
        if (!is_array($raw) || count($raw) !== count($keys)) throw new \RuntimeException('设备协调状态读取失败');
        $values = [];
        foreach ($keys as $index => $key) {
            $values[$key] = $this->value($store->getPrefix() . $key, $raw[$index]);
        }
        foreach ($values as $key => $value) {
            $events?->dispatch($value === null ? new CacheMissed($storeName, $key) : new CacheHit($storeName, $key, $value));
        }
        return $values;
    }

    private function value(string $identity, mixed $raw): ?array
    {
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
