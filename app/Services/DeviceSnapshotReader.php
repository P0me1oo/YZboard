<?php

namespace App\Services;

use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\Redis;

/** 只读核对 Redis 当前有效字段；未变化时不把整份设备记录再传回 PHP。 */
class DeviceSnapshotReader
{
    private const BATCH_SIZE = 32;
    private const MAX_ENTRIES = 512;
    private const MAX_BYTES = 4 * 1024 * 1024;
    private array $entries = [];
    private int $bytes = 0;
    private ?PhpRedisConnection $connection = null;
    private array $hashes = [];
    private int $hashBytes = 0;

    // 不写版本键，不依赖写入方通知；删除、TTL 到期和旧写入路径都在本次读取中生效。
    // 每批至多 32 个用户，每用户至多 1024 个字段；特殊格式沿用原读取和 PHP 过滤。
    private const READ = <<<'LUA'
local result = {}
local now = tonumber(ARGV[1])
local ttl = tonumber(ARGV[2])
if redis.call('EXISTS', unpack(KEYS)) == 0 then return result end
for i, key in ipairs(KEYS) do
    local size = redis.call('HLEN', key)
    if size > 1024 then return {false} end
    local records = size == 0 and {} or redis.call('HGETALL', key)
    local fields = {}
    for j = 1, #records, 2 do
        local field, value = records[j], records[j + 1]
        if #field > 128 or string.find(field, '\0', 1, true)
            or #value > 15 or not string.match(value, '^%-?%d+$') then return {false} end
        if now - tonumber(value) <= ttl then fields[#fields + 1] = field end
    end
    if #fields == 0 then
        result[i] = {0}
    else
        table.sort(fields)
        local signature = redis.sha1hex(table.concat(fields, '\0'))
        if signature == ARGV[i + 2] then
            result[i] = {signature}
        else
            result[i] = {signature, fields}
        end
    end
end
return result
LUA;

    /** @return array<int, list<string>>|null null 表示该连接或数据格式沿用原实现。 */
    public function read(array $userIds, int $now, int $ttl): ?array
    {
        if ($userIds === []) return [];
        $manager = Redis::getFacadeRoot();
        if (!$manager instanceof RedisManager || get_class($manager) !== RedisManager::class) return null;
        $connection = $manager->connection();
        if (get_class($connection) !== PhpRedisConnection::class || $connection->serialized() || $connection->compressed()) {
            return null;
        }
        if ($this->connection !== $connection) {
            $this->entries = [];
            $this->bytes = 0;
            $this->connection = $connection;
        }

        $result = [];
        try {
            $hasPrevious = false;
            foreach ($userIds as $id) {
                if (isset($this->entries[$id])) { $hasPrevious = true; break; }
            }
            // 空闲名单通常没有设备键；一次原生只读命令即可确认，不必进入 Lua。
            if (!$hasPrevious && count($userIds) <= 256) {
                $existing = $connection->exists(...array_map(fn ($id) => 'user_devices:' . $id, $userIds));
                if (!is_int($existing) || $existing < 0 || $existing > count($userIds)) {
                    throw new \RuntimeException('设备快照存在性读取失败');
                }
                if ($existing === 0) return array_fill_keys($userIds, []);
            }
            foreach (array_chunk($userIds, self::BATCH_SIZE) as $chunk) {
                // 保存本批引用，处理回包时淘汰其它条目不能使“相同”回包失去基线。
                $previous = [];
                foreach ($chunk as $id) $previous[$id] = $this->entries[$id] ?? null;
                $reply = $connection->eval(self::READ, count($chunk),
                    ...array_merge(array_map(fn ($id) => 'user_devices:' . $id, $chunk), [$now, $ttl],
                        array_map(fn ($id) => $previous[$id]['signature'] ?? '', $chunk)));
                if ($reply === [false]) {
                    $this->forgetMany($userIds);
                    return null;
                }
                if ($reply === []) {
                    $this->forgetMany($chunk);
                    $result += array_fill_keys($chunk, []);
                    continue;
                }
                if (!is_array($reply) || count($reply) !== count($chunk)) {
                    throw new \RuntimeException('设备快照读取失败');
                }
                foreach ($chunk as $index => $id) {
                    $row = $reply[$index];
                    if ($row === [0]) {
                        $result[$id] = [];
                        $this->forgetMany([$id]);
                        continue;
                    }
                    if (!is_array($row) || !in_array(count($row), [1, 2], true)
                        || !is_string($row[0] ?? null) || !preg_match('/\A[a-f0-9]{40}\z/', $row[0])) {
                        throw new \RuntimeException('设备快照响应无效');
                    }
                    if (count($row) === 1) {
                        $entry = $previous[$id];
                        if ($entry === null || $entry['signature'] !== $row[0]) {
                            throw new \RuntimeException('设备快照缺少复用基线');
                        }
                    } else {
                        $fields = $row[1];
                        if (!is_array($fields) || !array_is_list($fields)
                            || count(array_filter($fields, 'is_string')) !== count($fields)
                            || sha1(implode("\0", $fields)) !== $row[0]) {
                            throw new \RuntimeException('设备快照字段无效');
                        }
                        $entry = ['signature' => $row[0], 'fields' => $fields,
                            'bytes' => strlen(implode('', $fields)) + count($fields) * 128 + 256];
                    }
                    $result[$id] = $entry['fields'];
                    $this->remember($id, $entry);
                }
            }
        } catch (\Throwable $error) {
            $this->forgetMany($userIds);
            throw $error;
        }
        return $result;
    }

    /** 来源新鲜度和排除名单每次重读；相同字段可跨节点复用过滤结果。 */
    public function filtered(int $userId, array $fields, array $freshness, array $exclusions, callable $collect): array
    {
        $entry = $this->entries[$userId] ?? null;
        $context = [$freshness, $exclusions];
        if ($entry !== null && $entry['fields'] === $fields && ($entry['context'] ?? null) === $context) {
            return $entry['devices'];
        }
        $devices = $collect();
        if ($entry !== null && $entry['fields'] === $fields) {
            // 大型自定义设置不放入常驻缓存；估算含 PHP 数组开销的上限。
            $extra = strlen(serialize([$context, $devices])) * 4;
            $entry['bytes'] += $extra - ($entry['extra_bytes'] ?? 0);
            $entry['extra_bytes'] = $extra;
            $entry['context'] = $context;
            $entry['devices'] = $devices;
            $this->remember($userId, $entry);
        }
        return $devices;
    }

    /** 仅用于基础设备服务产生的纯数组；共享版本仍在捕获锁内读取、校验和推进。 */
    public function hash(string $key, array $data): string
    {
        $previous = $this->hashes[$key] ?? null;
        if ($previous !== null && $previous['data'] === $data) return $previous['hash'];
        if ($previous !== null) {
            $this->hashBytes -= $previous['bytes'];
            unset($this->hashes[$key]);
        }
        $json = json_encode($data, JSON_THROW_ON_ERROR);
        $hash = hash('sha256', $json);
        $bytes = strlen($json) * 8 + 256;
        if ($bytes <= self::MAX_BYTES) {
            while (count($this->hashes) >= self::MAX_ENTRIES || $this->hashBytes + $bytes > self::MAX_BYTES) {
                $first = array_key_first($this->hashes);
                $this->hashBytes -= $this->hashes[$first]['bytes'];
                unset($this->hashes[$first]);
            }
            $this->hashes[$key] = ['data' => $data, 'hash' => $hash, 'bytes' => $bytes];
            $this->hashBytes += $bytes;
        }
        return $hash;
    }

    private function remember(int $id, array $entry): void
    {
        $this->forgetMany([$id]);
        if ($entry['bytes'] > self::MAX_BYTES) return;
        while (count($this->entries) >= self::MAX_ENTRIES || $this->bytes + $entry['bytes'] > self::MAX_BYTES) {
            $this->forgetMany([array_key_first($this->entries)]);
        }
        $this->entries[$id] = $entry;
        $this->bytes += $entry['bytes'];
    }

    private function forgetMany(array $ids): void
    {
        foreach ($ids as $id) {
            $this->bytes -= $this->entries[$id]['bytes'] ?? 0;
            unset($this->entries[$id]);
        }
    }
}
