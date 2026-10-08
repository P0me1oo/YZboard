<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** 按账号协调来源名额，旧连接的关闭必须由所属节点的完整快照确认。 */
class DeviceHandoverService
{
    public const COOLDOWN_MS = 60000;
    private const CLAIM_MS = 5000;

    public function __construct(private readonly DeviceStateService $devices) {}

    public function begin(int $nodeId, string $run): array
    {
        return Cache::lock($this->nodeKey($nodeId) . ':lock', 15)->block(2, function () use ($nodeId, $run): array {
            $current = Cache::get($this->nodeKey($nodeId));
            if (($current['run'] ?? null) !== $run) {
                foreach (array_unique(array_column($current['sources'] ?? [], 'user_id')) as $userId) {
                    $this->queue($nodeId, (int) $userId, true);
                }
                Cache::forever($this->nodeKey($nodeId), [
                    'run' => $run, 'sequence' => 0, 'pending' => [], 'sources' => [], 'version' => 1, 'seen_at' => $this->now(),
                ]);
            }
            return ['version' => 1, 'run' => $run];
        });
    }

    /** 旧 Node 握手时明确退出新协议，避免回滚后把旧节点来源误当作已托管来源。 */
    public function legacyNode(int $nodeId): void
    {
        $users = Cache::lock($this->nodeKey($nodeId) . ':lock', 15)->block(2, function () use ($nodeId): array {
            $current = Cache::get($this->nodeKey($nodeId), []);
            Cache::forever($this->nodeKey($nodeId), [
                'run' => '', 'sequence' => 0, 'pending' => [], 'sources' => [], 'version' => 0, 'seen_at' => $this->now(),
            ]);
            return array_unique(array_merge(array_column($current['sources'] ?? [], 'user_id'), Cache::get($this->queueKey($nodeId), [])));
        });
        foreach ($users as $userId) $this->locked((int) $userId, function (array &$state): void { $this->reconcile($state); });
    }

    public function sync(int $nodeId, array $data): array
    {
        Cache::lock($this->nodeKey($nodeId) . ':lock', 15)->block(2, function () use ($nodeId, $data): void {
            $current = $this->node($nodeId, $data['run']);
            if ($data['sequence'] <= $current['sequence']) return;
            $sources = [];
            $now = $this->now();
            foreach ($data['sources'] as $source) {
                $previous = $current['sources'][$source['lease']] ?? null;
                $source['last_new_at'] = $previous !== null && $previous['connect_sequence'] === $source['connect_sequence']
                    ? $previous['last_new_at'] : $now - $source['age_ms'];
                $sources[$source['lease']] = $source;
            }
            Cache::forever($this->nodeKey($nodeId), [
                'run' => $data['run'], 'sequence' => $data['sequence'],
                'pending' => array_fill_keys($data['pending'], true), 'sources' => $sources, 'version' => 1, 'seen_at' => $now,
            ]);
        });

        $revoked = [];
        $users = array_unique(array_merge(Cache::get($this->queueKey($nodeId), []), array_column($data['retired'] ?? [], 'user_id')));
        foreach ($users as $userId) {
            $items = $this->locked((int) $userId, function (array &$state) use ($nodeId, $userId): array {
                $this->reconcile($state);
                $items = $this->revocations($state, $nodeId);
                if ($items === []) $this->queue($nodeId, (int) $userId, false);
                return $items;
            });
            array_push($revoked, ...$items);
        }
        return ['run' => $data['run'], 'sequence' => $data['sequence'], 'revoked' => $revoked];
    }

    public function admit(int $nodeId, int $userId, int $limit, array $data): array
    {
        return Cache::lock($this->nodeKey($nodeId) . ':lock', 15)->block(2, function () use ($nodeId, $userId, $limit, $data): array {
            $this->node($nodeId, $data['run']);
            return $this->locked($userId, function (array &$state) use ($nodeId, $userId, $limit, $data): array {
                $this->reconcile($state);
                $raw = PublicDeviceIp::normalize($data['ip']);
                if ($raw === null) return $this->answer('denied', $limit, 0, 'invalid_source');
                $key = DeviceIpExclusion::countKey($raw);
                $groups = $this->groups($state);
                $legacy = $this->legacyGroups($userId);
                $count = count($groups + $legacy);
                $now = $this->now();

                $existing = $state['sources'][$raw] ?? null;
                if ($existing !== null && !$existing['revoked']) {
                    return $this->grant($state, $nodeId, $userId, $raw, $data, $limit, $count);
                }
                if ($key === null || $limit <= 0) {
                    if ($existing !== null) return $this->waiting($state, $nodeId, $limit, $count);
                    return $this->grant($state, $nodeId, $userId, $raw, $data, $limit, $count);
                }

                $pending = $state['pending'];
                if ($pending !== null) {
                    if ($pending['target'] !== '' && $pending['target'] !== $key) {
                        return $this->answer('denied', $limit, $count, 'replacement_pending');
                    }
                    $state['pending']['target'] = $key;
                    $state['pending']['until'] = $now + self::CLAIM_MS;
                    if (!$pending['closed'] || $count >= $limit) {
                        return $this->waiting($state, $nodeId, $limit, $count);
                    }
                    $state['pending'] = null;
                    return $this->grant($state, $nodeId, $userId, $raw, $data, $limit, $count);
                }

                if (isset($groups[$key]) || isset($legacy[$key]) || $count < $limit) {
                    unset($state['cooldown'][$key]);
                    return $this->grant($state, $nodeId, $userId, $raw, $data, $limit, $count);
                }
                if (($state['cooldown'][$key] ?? 0) > $now) {
                    return $this->answer('denied', $limit, $count, 'cooldown');
                }
                // 旧节点无法确认关闭，也没有可比较的连接时间，混用时保留原来的拒绝规则。
                if ($legacy !== []) return $this->answer('denied', $limit, $count, 'legacy_node');

                uasort($groups, fn (array $a, array $b) => ($a['last_new_at'] <=> $b['last_new_at']) ?: strcmp($a['key'], $b['key']));
                $victim = reset($groups);
                if ($victim === false) return $this->answer('denied', $limit, $count, 'unknown_state');
                $leases = [];
                foreach ($victim['ips'] as $ip) {
                    $state['sources'][$ip]['revoked'] = true;
                    $leases[] = $state['sources'][$ip]['lease'];
                }
                $state['pending'] = [
                    'victim' => $victim['key'], 'leases' => $leases, 'target' => $key,
                    'until' => $now + self::CLAIM_MS, 'closed' => false,
                ];
                return $this->waiting($state, $nodeId, $limit, $count);
            });
        });
    }

    private function grant(array &$state, int $nodeId, int $userId, string $raw, array $data, int $limit, int $count): array
    {
        if (!isset($state['sources'][$raw])) {
            $state['sources'][$raw] = [
                'lease' => bin2hex(random_bytes(16)), 'user_id' => $userId, 'ip' => $raw,
                'revoked' => false, 'owners' => [],
            ];
        }
        $source = &$state['sources'][$raw];
        $owner = $source['owners'][$nodeId] ?? null;
        if ($owner === null || $owner['run'] !== $data['run']) {
            $owner = ['run' => $data['run'], 'sequence' => 0, 'connect_sequence' => 0, 'last_new_at' => $this->now()];
        }
        $owner['sequence'] = max($owner['sequence'], $data['sequence']);
        $source['owners'][$nodeId] = $owner;
        return $this->answer('allowed', $limit, $count) + ['lease' => $source['lease']];
    }

    private function reconcile(array &$state): void
    {
        $now = $this->now();
        $nodeIds = [];
        foreach ($state['sources'] as $source) {
            foreach ($source['owners'] as $nodeId => $_) $nodeIds[$nodeId] = true;
        }
        $keys = array_map(fn ($id) => $this->nodeKey((int) $id), array_keys($nodeIds));
        $nodes = $keys === [] ? [] : Cache::many($keys);
        foreach ($state['sources'] as $ip => &$source) {
            foreach ($source['owners'] as $nodeId => &$owner) {
                $node = $nodes[$this->nodeKey((int) $nodeId)] ?? null;
                // 缺失状态不能证明连接已经关闭，保留名额等待节点恢复或明确换代。
                if ($node === null) continue;
                if ($node['run'] !== $owner['run']) {
                    unset($source['owners'][$nodeId]);
                    continue;
                }
                $reported = $node['sources'][$source['lease']] ?? null;
                if ($reported !== null && $reported['user_id'] === $source['user_id'] && $reported['ip'] === $ip) {
                    if ($reported['connect_sequence'] > $owner['connect_sequence']) {
                        $owner['connect_sequence'] = $reported['connect_sequence'];
                        $owner['last_new_at'] = $reported['last_new_at'];
                    }
                } elseif ($node['sequence'] >= $owner['sequence'] && !isset($node['pending'][$owner['sequence']])) {
                    unset($source['owners'][$nodeId]);
                }
            }
            unset($owner);
            if ($source['owners'] === []) unset($state['sources'][$ip]);
        }
        unset($source);
        if ($state['pending'] !== null && !$state['pending']['closed']) {
            $remaining = array_intersect($state['pending']['leases'], array_column($state['sources'], 'lease'));
            if ($remaining === []) {
                $state['pending']['closed'] = true;
                $state['cooldown'][$state['pending']['victim']] = $now + self::COOLDOWN_MS;
            }
        }
        if ($state['pending'] !== null && $state['pending']['until'] <= $now) {
            if ($state['pending']['closed']) {
                $state['pending'] = null;
            } else {
                // 替换仍在关闭阶段，过期候选不能长期挡住后来的新来源。
                $state['pending']['target'] = '';
            }
        }
        $state['cooldown'] = array_filter($state['cooldown'], fn (int $until) => $until > $now);
    }

    private function groups(array $state): array
    {
        $groups = [];
        foreach ($state['sources'] as $ip => $source) {
            $key = DeviceIpExclusion::countKey($ip);
            if ($key === null) continue;
            $groups[$key] ??= ['key' => $key, 'last_new_at' => 0, 'ips' => []];
            $groups[$key]['ips'][] = $ip;
            foreach ($source['owners'] as $owner) {
                $groups[$key]['last_new_at'] = max($groups[$key]['last_new_at'], $owner['last_new_at']);
            }
        }
        return $groups;
    }

    private function legacyGroups(int $userId): array
    {
        $result = [];
        foreach ($this->devices->getDeviceSources($userId) as $key => $nodes) {
            foreach ($nodes as $nodeId) {
                $state = Cache::get($this->nodeKey((int) $nodeId));
                if (($state['version'] ?? 0) !== 1 || $this->now() - $state['seen_at'] > 15000) $result[$key] = true;
            }
        }
        return $result;
    }

    private function waiting(array $state, int $nodeId, int $limit, int $count): array
    {
        return $this->answer('waiting', $limit, $count) + ['revoked' => $this->revocations($state, $nodeId)];
    }

    private function revocations(array $state, int $nodeId): array
    {
        $result = [];
        foreach ($state['sources'] as $source) {
            if ($source['revoked'] && isset($source['owners'][$nodeId])) {
                $result[] = ['user_id' => $source['user_id'], 'ip' => $source['ip'], 'lease' => $source['lease']];
            }
        }
        return $result;
    }

    private function locked(int $userId, callable $action): mixed
    {
        $key = 'device_handover:user:' . $userId;
        return Cache::lock($key . ':lock', 15)->block(2, function () use ($key, $userId, $action): mixed {
            $state = Cache::get($key, ['sources' => [], 'cooldown' => [], 'pending' => null]);
            $result = $action($state);
            if ($state['sources'] === [] && $state['pending'] === null) {
                Cache::put($key, $state, 120);
            } else {
                Cache::forever($key, $state);
            }
            foreach ($state['sources'] as $source) {
                foreach ($source['owners'] as $nodeId => $owner) {
                    if ($source['revoked'] || $owner['connect_sequence'] === 0) $this->queue((int) $nodeId, $userId, true);
                }
            }
            return $result;
        });
    }

    private function queue(int $nodeId, int $userId, bool $add): void
    {
        $key = $this->queueKey($nodeId);
        Cache::lock($key . ':lock', 15)->block(2, function () use ($key, $userId, $add): void {
            $users = array_fill_keys(Cache::get($key, []), true);
            if ($add) $users[$userId] = true;
            else unset($users[$userId]);
            Cache::forever($key, array_keys($users));
        });
    }

    private function node(int $nodeId, string $run): array
    {
        $state = Cache::get($this->nodeKey($nodeId));
        if (!is_array($state) || ($state['run'] ?? null) !== $run) throw new ConflictHttpException('设备来源会话已失效');
        return $state;
    }

    private function answer(string $status, int $limit, int $count, string $reason = ''): array
    {
        return ['status' => $status, 'limit' => max(0, $limit), 'observed' => $count, 'reason' => $reason];
    }

    private function now(): int { return now()->getTimestampMs(); }
    private function nodeKey(int $nodeId): string { return 'device_handover:node:' . $nodeId; }
    private function queueKey(int $nodeId): string { return 'device_handover:queue:' . $nodeId; }
}
