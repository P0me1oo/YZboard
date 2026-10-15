<?php

namespace App\Services;

use App\Models\Server;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/** HTTP 与长连接从同一处取得有序配置，不使用通知队列里可能已过时的配置内容。 */
class NodeControlStateService
{
    public function snapshot(Server $node): array
    {
        return $this->capture('control:' . $node->id, function () use ($node): array {
            $node = $node->fresh() ?? $node;
            return [
                'node_id' => (int) $node->id,
                'config' => ServerService::buildNodeConfig($node),
                'users' => ServerService::getAvailableUsers($node)->values()->toArray(),
            ];
        });
    }

    public function devices(Server $node): array
    {
        return $this->capture('devices:' . $node->id, function () use ($node): array {
            $users = ServerService::getAvailableUsers($node)->pluck('id')->all();
            return [
                'node_id' => (int) $node->id,
                'users' => app(DeviceStateService::class)->getUsersDevices($users),
            ];
        });
    }

    /** 完整同步复用本次配置中已筛选的名单；插件仍保留原有两次筛选调用。 */
    public function fullSnapshot(Server $node, ?callable $sendControl = null): array
    {
        if (get_class($this) !== self::class || isset(\App\Services\Plugin\HookManager::getFilters()['server.users.get'])) {
            $control = $this->snapshot($node);
            if ($sendControl !== null) $sendControl($control);
            return ['control' => $control, 'devices' => $this->devices($node)];
        }

        $control = null;
        // 先持有设备版本锁，再读取资格；不能把锁外的旧名单排在并发新快照之后。
        // 配置锁只在 snapshot 内持有，其他入口不会同时按相反顺序持有这两把锁。
        $devices = $this->capture('devices:' . $node->id, function () use ($node, $sendControl, &$control): array {
            $control = $this->snapshot($node);
            if ($sendControl !== null) $sendControl($control);
            return [
                'node_id' => (int) $node->id,
                'users' => app(DeviceStateService::class)->getUsersDevices(array_column($control['users'], 'id')),
            ];
        });
        return ['control' => $control, 'devices' => $devices];
    }

    private function capture(string $name, callable $collect): array
    {
        $key = 'realtime:control:' . $name;
        return Cache::lock($key . ':lock', 30)->block(2, function () use ($key, $collect): array {
            $data = $collect();
            $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
            $current = Cache::get($key);
            if (!$this->validVersion($current)) {
                if ($current !== null) {
                    // 只记录缓存位置，不记录可能混入的后台设置或配置内容。
                    Log::warning('节点配置版本缓存无效，重新建立版本记录', ['cache_key' => $key]);
                }
                $current = ['epoch' => bin2hex(random_bytes(16)), 'sequence' => 0];
            }
            if (($current['hash'] ?? null) !== $hash) {
                $current['sequence']++;
                $current['hash'] = $hash;
                Cache::forever($key, $current);
            }
            return $data + ['epoch' => $current['epoch'], 'sequence' => $current['sequence']];
        });
    }

    /** 损坏的版本记录必须换代，不能带着旧序号或其它缓存内容继续下发。 */
    private function validVersion(mixed $current): bool
    {
        return is_array($current)
            && count($current) === 3
            && is_string($current['epoch'] ?? null)
            && preg_match('/\A[a-f0-9]{32}\z/', $current['epoch']) === 1
            && is_int($current['sequence'] ?? null)
            && $current['sequence'] >= 1
            && $current['sequence'] < 9007199254740991
            && is_string($current['hash'] ?? null)
            && preg_match('/\A[a-f0-9]{64}\z/', $current['hash']) === 1;
    }
}
