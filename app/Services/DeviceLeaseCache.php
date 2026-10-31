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
        return $this->leaseReply($holder);
    }

    private function leaseReply(array $holder): array
    {
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
        foreach ($snapshot['retired'] ?? [] as $item) {
            if (!isset($state['sources'][$item['ip']])) continue;
            $source = &$state['sources'][$item['ip']];
            $run = $item['run'] ?? $snapshot['run'];
            $key = $nodeId . ':' . $run;
            $holder = $source['holders'][$key] ?? null;
            // 跨会话序号不能比较。新客户端携带被归还的原会话与核验序号，
            // 只删除该次许可；旧客户端只能确认当前会话且必须晚于原核验。
            $matches = isset($item['confirmed_sequence'])
                ? $holder !== null && (int) $holder['sequence'] === (int) $item['confirmed_sequence']
                : $run === $snapshot['run'] && $holder !== null && $holder['sequence'] < $snapshot['sequence'];
            // 已撤销的来源或本节点许可不会被同步重新授予。即使最后一次续期
            // 回包丢失，较新的完整关闭快照仍能确认退出。重新准入会移除 holder
            // 撤销标记并产生新核验，届时仍必须精确匹配，不能误删恢复后的许可。
            if (($source['revoked'] || !empty($holder['revoked'])) && $run === $snapshot['run'] && $holder !== null
                && $holder['sequence'] < $snapshot['sequence']) $matches = true;
            if ($source['user_id'] === $item['user_id'] && $source['lease'] === $item['lease'] && $matches
                && ($run !== $snapshot['run'] || !isset($live[$item['lease']]))) {
                unset($source['holders'][$key]);
                if (($source['owners'][$nodeId]['run'] ?? null) === $run) unset($source['owners'][$nodeId]);
            }
            unset($source);
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
            $leases = $revoked = $idle = [];
            $existingNodes = [$nodeId => (bool) $server->enabled];
            $readContext = new DeviceCoordinationReadContext();
            foreach ($ids as $id) {
                $reported = $reportedByUser[$id] ?? [];
                if (!isset($coordinated[$id]) && $reported === [] && !isset($queued[$id])) continue;
                $result = $this->locked((int) $id, function (array &$state, array &$reads) use ($nodeId, $data, $node, $available, $id, $reported): array {
                    if (empty($data['unchanged']) && (int) $node['sequence'] === (int) $data['sequence']) {
                        $this->retireLeases($state, $nodeId, $data);
                    }
                    $this->reconcile($state, $reads);
                    $grants = $denied = $returns = [];
                    $canUse = $available->has($id);
                    foreach ($reported as $item) {
                        $known = $state['sources'][$item['ip']] ?? null;
                        if (!$canUse || $known === null || $known['lease'] !== $item['lease'] || $known['revoked']
                            || !isset($known['holders'][$nodeId . ':' . $data['run']])
                            || !empty($known['holders'][$nodeId . ':' . $data['run']]['revoked'])) {
                            $denied[$item['lease']] = array_intersect_key($item, array_flip(['user_id', 'ip', 'lease']));
                        }
                    }
                    foreach ($state['sources'] as &$source) {
                        $holderKey = $nodeId . ':' . $data['run'];
                        if (!$canUse && isset($source['holders'][$holderKey])) $source['holders'][$holderKey]['revoked'] = true;
                        if (!$canUse || $source['revoked'] || !empty($source['holders'][$holderKey]['revoked'])) {
                            if (isset($source['owners'][$nodeId]) || $this->nodeHoldsLease($source, $nodeId)) {
                                $denied[$source['lease']] = array_intersect_key($source, array_flip(['user_id', 'ip', 'lease']));
                            }
                            continue;
                        }
                        $holder = $source['holders'][$nodeId . ':' . $data['run']] ?? null;
                        // 没有实际申请的节点不再预发许可。空闲只返回原固定期限，
                        // 由 Agent 停用后确认归还；一次空快照本身不能释放占位。
                        if ($holder === null) continue;
                        $item = array_intersect_key($source, array_flip(['user_id', 'ip', 'lease']));
                        $active = $reported[$source['lease']] ?? null;
                        if ($active !== null && $active['ip'] === $source['ip']) {
                            $grants[] = $item + $this->confirmLease($source, $nodeId, $data['run'], (int) $data['sequence']);
                            $source['owners'][$nodeId] = ['run' => $data['run'], 'sequence' => $node['sequence'],
                                'connect_sequence' => $active['connect_sequence'], 'last_new_at' => $active['last_new_at']];
                        } else {
                            $grants[] = $item + $this->leaseReply($holder);
                            $returns[] = $item;
                        }
                    }
                    unset($source);
                    // 在账号锁内确认无下发或撤销任务后清理；过期协调记录也需经过此处。
                    if ($grants === [] && $denied === []) {
                        // 读取与移除均在账号锁内；队列中已无本账号时无需再抢队列锁。
                        if (in_array((int) $id, $reads['queues'][$this->queueKey($nodeId)] ?? [], true)) {
                            $this->queue($nodeId, (int) $id, false);
                        }
                        unset($reads['queues'][$this->queueKey($nodeId)]);
                    }
                    return [$grants, array_values($denied), $returns];
                }, [$nodeId => $node], $existingNodes, $readContext);
                array_push($leases, ...$result[0]);
                array_push($revoked, ...$result[1]);
                array_push($idle, ...$result[2]);
            }
            return ['run' => $data['run'], 'sequence' => $data['sequence'], 'revoked' => $revoked,
                'lease_cache' => 1, 'server_time' => $this->now(), 'leases' => $leases, 'idle' => $idle];
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
