<?php

namespace App\Services;

use App\Models\Server;
use Illuminate\Support\Facades\Cache;

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

    private function capture(string $name, callable $collect): array
    {
        $key = 'realtime:control:' . $name;
        return Cache::lock($key . ':lock', 30)->block(2, function () use ($key, $collect): array {
            $data = $collect();
            $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
            $current = Cache::get($key);
            if (!is_array($current)) {
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
}
