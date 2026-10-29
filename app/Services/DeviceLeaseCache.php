<?php

namespace App\Services;

use App\Models\Server;
use Illuminate\Cache\RedisStore;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** 共享许可的确认与占位；所有来源变更都在原账号锁内完成。 */
trait DeviceLeaseCache
{
    public const LEASE_GRACE_MS = 3600000;

    private function requireLeaseCache(array $node): void
    {
        if (empty($node['lease_cache'])) throw new ConflictHttpException('设备许可缓存需要重新握手');
    }

    private function confirmLease(array &$source, int $nodeId, string $run, int $sequence): array
    {
        $key = $nodeId . ':' . $run;
        $holder = $source['holders'][$key] ?? null;
        // 重传同一业务序号不移动期限。先保存占位，调用方成功写入后才能返回许可。
        if ($holder === null || $sequence > $holder['sequence']) {
            $now = $this->now();
            $holder = ['node_id' => $nodeId, 'run' => $run, 'sequence' => $sequence,
                'confirmed_at' => $now, 'expires_at' => $now + self::LEASE_GRACE_MS];
            $source['holders'][$key] = $holder;
        }
        return ['confirmed_at' => $holder['confirmed_at'], 'expires_at' => $holder['expires_at'],
            'confirmed_sequence' => $holder['sequence']];
    }

    private function nodeHoldsLease(array $source, int $nodeId): bool
    {
        foreach ($source['holders'] ?? [] as $holder) {
            if ($holder['node_id'] === $nodeId) return true;
        }
        return false;
    }

    private function expireLeaseHolders(array &$source, int $now): void
    {
        foreach ($source['holders'] ?? [] as $key => $holder) {
            if ($holder['expires_at'] > $now) continue;
            unset($source['holders'][$key]);
            if (($source['owners'][$holder['node_id']]['run'] ?? null) === $holder['run']) {
                unset($source['owners'][$holder['node_id']]);
            }
        }
    }

    private function retireLeases(array &$state, int $nodeId, array $snapshot): void
    {
        $live = array_fill_keys(array_column($snapshot['sources'] ?? [], 'lease'), true);
        $key = $nodeId . ':' . $snapshot['run'];
        foreach ($snapshot['retired'] ?? [] as $item) {
            $source = &$state['sources'][$item['ip']];
            if ($source !== null && $source['user_id'] === $item['user_id'] && $source['lease'] === $item['lease']
                && !isset($live[$item['lease']]) && ($source['holders'][$key]['sequence'] ?? PHP_INT_MAX) < $snapshot['sequence']) {
                unset($source['holders'][$key]);
            }
            unset($source);
            // 对未知许可的确认只能拒绝，不能从节点上报内容重建许可。
            if (($state['sources'][$item['ip']] ?? null) === null) unset($state['sources'][$item['ip']]);
        }
    }

    private function syncLeases(int $nodeId, array $data): array
    {
        return Cache::lock($this->nodeKey($nodeId) . ':lock', 15)->betweenBlockedAttemptsSleepFor(25)->block(2, function () use ($nodeId, $data): array {
            $node = $this->node($nodeId, $data['run'], false);
            $this->requireLeaseCache($node);
            if (!empty($data['unchanged'])) {
                $progress = $this->renew($nodeId, $data, (int) $node['sequence']);
                // 已持有节点锁，复用刚校验的完整快照，只合并成功保存的续期进度。
                $node['sequence'] = $progress['sequence'];
                $node['seen_at'] = $progress['seen_at'];
            } else {
                $node = $this->storeSnapshot($nodeId, $data);
            }
            $server = Server::query()->findOrFail($nodeId);
            $available = $server->enabled ? ServerService::getAvailableUsers($server)->keyBy('id') : collect();
            $queued = array_fill_keys(Cache::get($this->queueKey($nodeId), []), true);
            $ids = array_unique(array_merge($available->keys()->all(), array_keys($queued),
                array_column($node['sources'], 'user_id'), array_column($data['retired'] ?? [], 'user_id')));
            // 批量找出已有许可的账号；不为整个用户名单创建空协调记录。
            $coordinated = $this->coordinatedUsers($ids);
            $reportedByUser = [];
            foreach ($node['sources'] as $key => $source) {
                $reportedByUser[$source['user_id']][$key] = $source;
            }
            $leases = $revoked = [];
            foreach ($ids as $id) {
                $reported = $reportedByUser[$id] ?? [];
                if (!isset($coordinated[$id]) && $reported === [] && !isset($queued[$id])) continue;
                $result = $this->locked((int) $id, function (array &$state) use ($nodeId, $data, $node, $available, $id, $reported): array {
                    if (empty($data['unchanged'])) $this->retireLeases($state, $nodeId, $data);
                    $this->reconcile($state);
                    $grants = $denied = [];
                    $canUse = $available->has($id);
                    foreach ($reported as $item) {
                        $known = $state['sources'][$item['ip']] ?? null;
                        if (!$canUse || $known === null || $known['lease'] !== $item['lease'] || $known['revoked']) {
                            $denied[$item['lease']] = array_intersect_key($item, array_flip(['user_id', 'ip', 'lease']));
                        }
                    }
                    foreach ($state['sources'] as &$source) {
                        if (!$canUse || $source['revoked']) {
                            if (isset($source['owners'][$nodeId]) || $this->nodeHoldsLease($source, $nodeId)) {
                                $denied[$source['lease']] = array_intersect_key($source, array_flip(['user_id', 'ip', 'lease']));
                            }
                            continue;
                        }
                        // 账号/节点权限和权威许可仍有效才确认。上报或心跳本身不是续期依据。
                        $grants[] = array_intersect_key($source, array_flip(['user_id', 'ip', 'lease']))
                            + $this->confirmLease($source, $nodeId, $data['run'], (int) $data['sequence']);
                    }
                    unset($source);
                    // 在账号锁内确认无下发或撤销任务后清理；过期协调记录也需经过此处。
                    if ($grants === [] && $denied === []) $this->queue($nodeId, (int) $id, false);
                    return [$grants, array_values($denied)];
                });
                array_push($leases, ...$result[0]);
                array_push($revoked, ...$result[1]);
            }
            return ['run' => $data['run'], 'sequence' => $data['sequence'], 'revoked' => $revoked,
                'lease_cache' => 1, 'server_time' => $this->now(), 'leases' => $leases];
        });
    }

    /** 筛选只需要知道记录是否存在；完整许可仍在取得账号锁后读取。 */
    private function coordinatedUsers(array $ids): array
    {
        $present = [];
        $store = Cache::getStore();
        foreach (array_chunk(array_values($ids), 256) as $chunk) {
            $keys = array_map(fn ($id) => 'device_handover:user:' . $id, $chunk);
            if ($store instanceof RedisStore) {
                $script = 'local found = {} for i, key in ipairs(KEYS) do found[i] = redis.call("EXISTS", key) end return found';
                $found = $store->connection()->eval($script, count($keys),
                    ...array_map(fn ($key) => $store->getPrefix() . $key, $keys));
                if (!is_array($found) || count($found) !== count($keys)) {
                    throw new \RuntimeException('设备许可存在状态读取失败');
                }
                foreach ($chunk as $index => $id) {
                    if (!in_array($found[$index], [0, 1], true)) {
                        throw new \RuntimeException('设备许可存在状态无效');
                    }
                    if ($found[$index] === 1) $present[$id] = true;
                }
            } else {
                $records = Cache::many($keys);
                foreach ($chunk as $index => $id) {
                    if (($records[$keys[$index]] ?? null) !== null) $present[$id] = true;
                }
            }
        }
        return $present;
    }
}
