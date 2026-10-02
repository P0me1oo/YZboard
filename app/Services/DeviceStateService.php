<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Redis;

class DeviceStateService
{
    private const PREFIX = 'user_devices:';
    private const NODE_INDEX_PREFIX = 'node_devices:';
    private const NODE_INDEX_SEEN_PREFIX = 'node_devices_seen:';
    private const TTL = 300;
    private const DB_THROTTLE = 60;

    private function removeRedisPrefix(string $key): string
    {
        $prefix = config('database.redis.options.prefix', '');
        return $prefix ? substr($key, strlen($prefix)) : $key;
    }

    /**
     * 批量设置设备
     * 用于 HTTP /alive 和 WebSocket report.devices
     */
    public function setDevices(int $userId, int $nodeId, array $ips, bool $forceNotify = false): void
    {
        $key = self::PREFIX . $userId;
        $timestamp = time();

        $this->removeNodeDevices($nodeId, $userId);

        // 保留完整公网来源，读取设备数时才过滤名单并合并 IPv6 网段。
        $ips = self::normalizeIPs($ips);

        if (!empty($ips)) {
            $fields = [];
            foreach ($ips as $ip) {
                $fields["{$nodeId}:{$ip}"] = $timestamp;
            }
            Redis::hMset($key, $fields);
            Redis::expire($key, self::TTL);
            Redis::sadd(self::NODE_INDEX_PREFIX . $nodeId, $userId);
            Redis::expire(self::NODE_INDEX_PREFIX . $nodeId, self::TTL * 2);
        }
        Redis::setex(self::NODE_INDEX_SEEN_PREFIX . $nodeId, self::TTL * 2, 1);

        $this->notifyUpdate($userId, $forceNotify);
    }

    /**
     * 获取某节点的所有设备数据
     * 返回: {userId: [ip1, ip2, ...], ...}
     */
    public function getNodeDevices(int $nodeId): array
    {
        if (!Redis::exists(self::NODE_INDEX_SEEN_PREFIX . $nodeId)) {
            return $this->getLegacyNodeDevices($nodeId);
        }

        $userIds = Redis::smembers(self::NODE_INDEX_PREFIX . $nodeId);
        $prefix = "{$nodeId}:";
        $result = [];
        $now = time();
        foreach ($userIds as $userId) {
            $uid = (int) $userId;
            $data = Redis::hgetall(self::PREFIX . $uid);
            foreach ($data as $field => $timestamp) {
                if (str_starts_with($field, $prefix) && $now - (int) $timestamp <= self::TTL) {
                    $ip = substr($field, strlen($prefix));
                    $result[$uid][] = $ip;
                }
            }
        }

        return $result;
    }

    /**
     * 升级后的首次快照兼容旧数据；建立节点索引后不再扫描全量用户设备键。
     */
    private function getLegacyNodeDevices(int $nodeId): array
    {
        $prefix = "{$nodeId}:";
        $result = [];
        $now = time();

        foreach (Redis::keys(self::PREFIX . '*') as $key) {
            $actualKey = $this->removeRedisPrefix($key);
            $userId = (int) substr($actualKey, strlen(self::PREFIX));
            foreach (Redis::hgetall($actualKey) as $field => $timestamp) {
                if (str_starts_with($field, $prefix) && $now - (int) $timestamp <= self::TTL) {
                    $result[$userId][] = substr($field, strlen($prefix));
                }
            }
        }

        return $result;
    }

    /**
     * 删除某节点某用户的设备
     */
    public function removeNodeDevices(int $nodeId, int $userId): void
    {
        $key = self::PREFIX . $userId;
        $prefix = "{$nodeId}:";

        foreach (Redis::hkeys($key) as $field) {
            if (str_starts_with($field, $prefix)) {
                Redis::hdel($key, $field);
            }
        }
        Redis::srem(self::NODE_INDEX_PREFIX . $nodeId, $userId);
    }

    /**
     * 用节点上报的权威快照整体替换该节点的设备状态，空数组表示节点当前没有设备。
     */
    public function replaceNodeDevices(int $nodeId, array $devices): void
    {
        $normalized = [];
        foreach ($devices as $userId => $ips) {
            if (is_numeric($userId) && is_array($ips)) {
                $normalized[(int) $userId] = $ips;
            }
        }

        $oldDevices = $this->getNodeDevices($nodeId);
        $changed = array_diff(array_keys($oldDevices), array_keys($normalized)) !== [];
        foreach (array_diff(array_keys($oldDevices), array_keys($normalized)) as $userId) {
            $this->removeNodeDevices($nodeId, (int) $userId);
            $this->notifyUpdate((int) $userId, true);
        }

        $unchanged = [];
        // 扩展服务覆盖单用户写入时，继续调用其方法，保留插件行为。
        $canRefresh = (new \ReflectionMethod($this, 'setDevices'))->getDeclaringClass()->getName() === self::class;
        foreach ($normalized as $userId => $ips) {
            $newIps = self::normalizeIPs($ips);
            $oldIps = $oldDevices[$userId] ?? [];
            sort($newIps);
            sort($oldIps);
            $changed = $changed || $newIps !== $oldIps;
            if ($canRefresh && $newIps !== [] && $newIps === $oldIps) {
                $unchanged[$userId] = $newIps;
            } else {
                $this->setDevices($userId, $nodeId, $newIps, $newIps !== $oldIps);
            }
        }
        $this->refreshUnchangedDevices($nodeId, $unchanged);

        if ($normalized === []) {
            Redis::del(self::NODE_INDEX_PREFIX . $nodeId);
        }
        Redis::setex(self::NODE_INDEX_SEEN_PREFIX . $nodeId, self::TTL * 2, 1);
        if ($changed) {
            // 零是全体在线节点的通知标记，兼容 HTTP 与长连接处于不同进程。
            // 集合自动合并高频变化，接收时仍按各节点的可用用户范围生成快照。
            Redis::sadd('device:push_pending_nodes', 0);
        }
    }

    /** 未变化的设备只续期，不删除重建；来源时间和数据库更新节流保持原样。 */
    private function refreshUnchangedDevices(int $nodeId, array $users): void
    {
        $timestamp = time();
        foreach (array_chunk($users, 128, true) as $chunk) {
            $results = Redis::pipeline(function ($pipe) use ($nodeId, $chunk, $timestamp): void {
                foreach ($chunk as $userId => $ips) {
                    $fields = [];
                    foreach ($ips as $ip) $fields["{$nodeId}:{$ip}"] = $timestamp;
                    $key = self::PREFIX . $userId;
                    $pipe->hMset($key, $fields);
                    $pipe->expire($key, self::TTL);
                    $pipe->sadd(self::NODE_INDEX_PREFIX . $nodeId, $userId);
                }
                $pipe->expire(self::NODE_INDEX_PREFIX . $nodeId, self::TTL * 2);
            });
            if (!is_array($results) || count($results) !== count($chunk) * 3 + 1 || in_array(false, $results, true)) {
                throw new \RuntimeException('设备有效期更新失败');
            }
            foreach ($chunk as $userId => $_) {
                $this->notifyUpdate((int) $userId);
            }
        }
    }

    /**
     * 清除节点所有设备数据（用于节点断开连接）
     */
    public function clearAllNodeDevices(int $nodeId): array
    {
        $oldDevices = $this->getNodeDevices($nodeId);
        $prefix = "{$nodeId}:";

        foreach ($oldDevices as $userId => $ips) {
            $key = self::PREFIX . $userId;
            foreach (Redis::hkeys($key) as $field) {
                if (str_starts_with($field, $prefix)) {
                    Redis::hdel($key, $field);
                }
            }
            $this->notifyUpdate($userId, true);
        }
        Redis::setex(self::NODE_INDEX_SEEN_PREFIX . $nodeId, self::TTL * 2, 1);

        return array_keys($oldDevices);
    }

    /**
     * get user device count (deduplicated by IP, filter expired data)
     */
    public function getDeviceCount(int $userId): int
    {
        return count($this->getDeviceIPs($userId));
    }

    /** 获取跨节点去重后占用设备名额的在线来源 IP：公网且不在排除名单内。 */
    public function getDeviceIPs(int $userId, bool $legacyOnly = false): array
    {
        $records = Redis::hgetall(self::PREFIX . $userId);
        $states = app(RealtimeStateStore::class)->readMany($this->deviceSources([$records]));
        return $this->filterDeviceIPs($records, $states, time(), $legacyOnly);
    }

    /** 同批用户共享节点状态，避免每个用户重复读取和解码同一份完整快照。 */
    private function deviceSources(array $users): array
    {
        $sources = [];
        foreach ($users as $records) {
            foreach ($records as $field => $timestamp) {
                $nodeId = (int) strstr((string) $field, ':', true);
                if ($nodeId > 0) $sources['node:' . $nodeId] = true;
            }
        }
        return array_keys($sources);
    }

    private function filterDeviceIPs(array $records, array $states, int $now, bool $legacyOnly = false): array
    {
        $ips = [];
        foreach ($records as $field => $timestamp) {
            $nodeId = (int) strstr((string) $field, ':', true);
            $state = $states['node:' . $nodeId] ?? null;
            if ($legacyOnly && $state !== null) continue;
            if ($state !== null && !$state['fresh']) continue;
            if ($now - (int) $timestamp > self::TTL || !str_contains($field, ':')) {
                continue;
            }
            $public = DeviceIpExclusion::countKey(substr($field, strpos($field, ':') + 1));
            if ($public !== null) {
                $ips[$public] = true;
            }
        }
        $result = array_keys($ips);
        sort($result);
        return $result;
    }

    /**
     * get user device count (for alivelist interface)
     */
    public function getAliveList(Collection $users): array
    {
        if ($users->isEmpty()) {
            return [];
        }

        $result = [];
        foreach ($users as $user) {
            $count = $this->getDeviceCount($user->id);
            if ($count > 0) {
                $result[$user->id] = $count;
            }
        }

        return $result;
    }

    /**
     * get devices of multiple users (for sync.devices, filter expired data)
     */
    public function getUsersDevices(array $userIds): array
    {
        // 保留扩展服务对单用户读取的定制，不能用批量路径绕过插件覆盖的方法。
        if (get_class($this) !== self::class
            && (new \ReflectionMethod($this, 'getDeviceIPs'))->getDeclaringClass()->getName() !== self::class) {
            $result = [];
            foreach ($userIds as $userId) {
                $ips = $this->getDeviceIPs((int) $userId);
                if ($ips !== []) $result[$userId] = $ips;
            }
            return $result;
        }
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        if ($userIds === []) return [];
        if (count($userIds) === 1) {
            $ips = $this->getDeviceIPs($userIds[0]);
            return $ips === [] ? [] : [$userIds[0] => $ips];
        }

        $records = [];
        foreach (array_chunk($userIds, 256) as $chunk) {
            $rows = Redis::pipeline(function ($pipe) use ($chunk): void {
                foreach ($chunk as $userId) {
                    $pipe->hgetall(self::PREFIX . $userId);
                }
            });
            if (!is_array($rows) || count($rows) !== count($chunk)) {
                throw new \RuntimeException('批量读取设备状态失败');
            }
            foreach ($chunk as $index => $userId) {
                if (!is_array($rows[$index])) {
                    throw new \RuntimeException('设备状态读取结果无效');
                }
                $records[$userId] = $rows[$index];
            }
        }

        // 只在本次调用中复用，下一次同步仍读取最新状态，并按当前时间检查过期。
        $states = app(RealtimeStateStore::class)->readMany($this->deviceSources($records));
        $now = time();
        $result = [];
        foreach ($records as $userId => $userRecords) {
            $ips = $this->filterDeviceIPs($userRecords, $states, $now);
            if ($ips !== []) {
                $result[$userId] = $ips;
            }
        }

        return $result;
    }

    private static function normalizeIPs(array $ips): array
    {
        return array_values(array_unique(array_filter(
            array_map(fn ($ip) => PublicDeviceIp::normalize((string) $ip), $ips)
        )));
    }

    /**
     * notify update (throttle control)
     */
    public function notifyUpdate(int $userId, bool $force = false): void
    {
        $throttleKey = "device:db_throttle:{$userId}";
        if ($force) {
            Redis::setex($throttleKey, self::DB_THROTTLE, 1);
        } else {
            if (!Redis::setnx($throttleKey, 1)) {
                return;
            }
            Redis::expire($throttleKey, self::DB_THROTTLE);
        }

        User::query()
            ->whereKey($userId)
            ->update([
                'online_count' => $this->getDeviceCount($userId),
                'last_online_at' => now(),
            ]);
    }
}
