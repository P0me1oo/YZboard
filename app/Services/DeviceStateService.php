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

        // Normalize: strip port suffix and deduplicate
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
        foreach (array_diff(array_keys($oldDevices), array_keys($normalized)) as $userId) {
            $this->removeNodeDevices($nodeId, (int) $userId);
            $this->notifyUpdate((int) $userId, true);
        }

        foreach ($normalized as $userId => $ips) {
            $newIps = self::normalizeIPs($ips);
            $oldIps = $oldDevices[$userId] ?? [];
            sort($newIps);
            sort($oldIps);
            $this->setDevices($userId, $nodeId, $newIps, $newIps !== $oldIps);
        }

        if ($normalized === []) {
            Redis::del(self::NODE_INDEX_PREFIX . $nodeId);
        }
        Redis::setex(self::NODE_INDEX_SEEN_PREFIX . $nodeId, self::TTL * 2, 1);
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
        $data = Redis::hgetall(self::PREFIX . $userId);
        $now = time();
        $ips = [];

        foreach ($data as $field => $timestamp) {
            if ($now - $timestamp <= self::TTL) {
                $ips[] = substr($field, strpos($field, ':') + 1);
            }
        }

        return count(array_unique($ips));
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
        $result = [];
        $now = time();
        foreach ($userIds as $userId) {
            $data = Redis::hgetall(self::PREFIX . $userId);
            if (!empty($data)) {
                $ips = [];
                foreach ($data as $field => $timestamp) {
                    if ($now - $timestamp <= self::TTL) {
                        $ips[] = substr($field, strpos($field, ':') + 1);
                    }
                }
                if (!empty($ips)) {
                    $result[$userId] = array_values(array_unique($ips));
                }
            }
        }

        return $result;
    }

    /**
     * Strip port from IP address: "1.2.3.4:12345" → "1.2.3.4", "[::1]:443" → "::1"
     */
    private static function normalizeIP(string $ip): string
    {
        // [IPv6]:port
        if (preg_match('/^\[(.+)\]:\d+$/', $ip, $m)) {
            return $m[1];
        }
        // IPv4:port
        if (preg_match('/^(\d+\.\d+\.\d+\.\d+):\d+$/', $ip, $m)) {
            return $m[1];
        }
        return $ip;
    }

    private static function normalizeIPs(array $ips): array
    {
        return array_values(array_unique(array_filter(
            array_map(fn ($ip) => self::normalizeIP((string) $ip), $ips),
            fn (string $ip) => $ip !== ''
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
