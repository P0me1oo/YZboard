<?php

namespace App\Services;

use App\Models\Server;
use Illuminate\Cache\RedisStore;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;

/** 按账号协调来源名额，旧连接的关闭必须由所属节点的完整快照确认。 */
class DeviceHandoverService
{
    use DeviceLeaseCache;

    public const COOLDOWN_MS = 60000;
    private const CLAIM_MS = 5000;

    public function __construct(private readonly DeviceStateService $devices) {}

    /** 删除提交后清理已知用户；遗漏的旧记录由下一次协调时核对数据库兜底。 */
    public function deletedNode(int $nodeId): void
    {
        if (Server::query()->whereKey($nodeId)->exists()) return;
        $users = Cache::lock($this->nodeKey($nodeId) . ':lock', 15)->betweenBlockedAttemptsSleepFor(25)->block(2, function () use ($nodeId): array {
            $node = Cache::get($this->nodeKey($nodeId), []);
            return array_unique(array_merge(array_column($node['sources'] ?? [], 'user_id'), Cache::get($this->queueKey($nodeId), [])));
        });
        foreach ($users as $userId) {
            $this->locked((int) $userId, function (array &$state, array $reads): void { $this->reconcile($state, $reads); });
        }
        Cache::forget($this->nodeKey($nodeId));
        Cache::forget($this->progressKey($nodeId));
        Cache::forget($this->queueKey($nodeId));
    }

    public function begin(int $nodeId, string $run, bool $leaseCache = false): array
    {
        return Cache::lock($this->nodeKey($nodeId) . ':lock', 15)->betweenBlockedAttemptsSleepFor(25)->block(2, function () use ($nodeId, $run, $leaseCache): array {
            $current = Cache::get($this->nodeKey($nodeId));
            if (($current['run'] ?? null) !== $run) {
                foreach (array_unique(array_column($current['sources'] ?? [], 'user_id')) as $userId) {
                    $this->queue($nodeId, (int) $userId, true);
                }
                if (!Cache::forever($this->nodeKey($nodeId), [
                    'run' => $run, 'sequence' => 0, 'pending' => [], 'sources' => [], 'version' => 1, 'seen_at' => $this->now(),
                    'lease_cache' => $leaseCache,
                ])) throw new \RuntimeException('设备来源会话写入失败');
                Cache::forget($this->progressKey($nodeId));
            }
            return ['version' => 1, 'run' => $run, 'lease_cache' => (int) (($current['run'] ?? null) === $run ? ($current['lease_cache'] ?? false) : $leaseCache)];
        });
    }

    /** 旧 Node 握手时明确退出新协议，避免回滚后把旧节点来源误当作已托管来源。 */
    public function legacyNode(int $nodeId): void
    {
        $users = Cache::lock($this->nodeKey($nodeId) . ':lock', 15)->betweenBlockedAttemptsSleepFor(25)->block(2, function () use ($nodeId): array {
            $current = Cache::get($this->nodeKey($nodeId), []);
            Cache::forever($this->nodeKey($nodeId), [
                'run' => '', 'sequence' => 0, 'pending' => [], 'sources' => [], 'version' => 0, 'seen_at' => $this->now(),
            ]);
            Cache::forget($this->progressKey($nodeId));
            return array_unique(array_merge(array_column($current['sources'] ?? [], 'user_id'), Cache::get($this->queueKey($nodeId), [])));
        });
        foreach ($users as $userId) $this->locked((int) $userId, function (array &$state, array $reads): void { $this->reconcile($state, $reads); });
    }

    public function sync(int $nodeId, array $data): array
    {
        if (!empty($data['lease_cache'])) return $this->syncLeases($nodeId, $data);
        Cache::lock($this->nodeKey($nodeId) . ':lock', 15)->betweenBlockedAttemptsSleepFor(25)->block(2, function () use ($nodeId, $data): void {
            if (!empty($data['unchanged'])) {
                $this->renew($nodeId, $data);
                return;
            }
            $this->storeSnapshot($nodeId, $data);
        });

        $revoked = [];
        $users = array_unique(array_merge(Cache::get($this->queueKey($nodeId), []),
            array_column($data['sources'] ?? [], 'user_id'), array_column($data['retired'] ?? [], 'user_id')));
        foreach ($users as $userId) {
            $items = $this->locked((int) $userId, function (array &$state, array &$reads) use ($nodeId, $userId): array {
                $this->reconcile($state, $reads);
                $items = $this->revocations($state, $nodeId);
                if ($items === []) {
                    $queueKey = $this->queueKey($nodeId);
                    if (!array_key_exists($queueKey, $reads['queues']) || in_array((int) $userId, $reads['queues'][$queueKey] ?? [], true)) {
                        $this->queue($nodeId, (int) $userId, false);
                    }
                    unset($reads['queues'][$queueKey]);
                }
                return $items;
            });
            array_push($revoked, ...$items);
        }
        return ['run' => $data['run'], 'sequence' => $data['sequence'], 'revoked' => $revoked];
    }

    /** 调用方持有节点锁；先写入完整状态，后续名额判断才能看到真实关闭与连接排序。 */
    private function storeSnapshot(int $nodeId, array $data): array
    {
        $current = $this->node($nodeId, $data['run']);
        if ($data['sequence'] <= $current['sequence']) return $current;
        $sources = [];
        $now = $this->now();
        foreach ($data['sources'] as $source) {
            $previous = $current['sources'][$source['lease']] ?? null;
            $source['last_new_at'] = $previous !== null && $previous['connect_sequence'] === $source['connect_sequence']
                ? $previous['last_new_at'] : $now - $source['age_ms'];
            $sources[$source['lease']] = $source;
        }
        $state = [
            'run' => $data['run'], 'sequence' => $data['sequence'],
            'pending' => array_fill_keys($data['pending'], true), 'sources' => $sources, 'version' => 1, 'seen_at' => $now,
            'lease_cache' => $current['lease_cache'] ?? false,
        ];
        if (!Cache::forever($this->nodeKey($nodeId), $state)) {
            throw new \RuntimeException('设备来源完整快照写入失败');
        }
        $this->saveProgress($nodeId, $data['run'], $data['sequence'], $data['sequence'], $now);
        return $state;
    }

    /** 未变化的快照只更新小记录，不反复读取、序列化和写入全部来源。 */
    private function renew(int $nodeId, array $data, ?int $snapshotSequence = null): array
    {
        $progress = Cache::get($this->progressKey($nodeId));
        if (!is_array($progress) || $progress['run'] !== $data['run']) {
            $this->node($nodeId, $data['run']);
            throw new PreconditionFailedHttpException('设备来源需要完整快照');
        }
        if ($progress['base_sequence'] !== (int) $data['base_sequence']
            || ($snapshotSequence !== null && $progress['base_sequence'] !== $snapshotSequence)
            || !$this->snapshotExists($nodeId)) {
            throw new PreconditionFailedHttpException('设备来源快照基线已失效');
        }
        if ($data['sequence'] > $progress['sequence']) {
            $progress['sequence'] = (int) $data['sequence'];
            $progress['seen_at'] = $this->now();
            $this->saveProgress($nodeId, $data['run'], $progress['sequence'], $progress['base_sequence'], $progress['seen_at']);
        }
        return $progress;
    }

    private function snapshotExists(int $nodeId): bool
    {
        return $this->cacheKeyExists($this->nodeKey($nodeId));
    }

    private function cacheKeyExists(string $key): bool
    {
        $store = Cache::getStore();
        // 框架的 has() 会读取并反序列化全部内容，Redis 直接检查键即可。
        if ($store instanceof RedisStore) {
            return (bool) $store->connection()->exists($store->getPrefix() . $key);
        }
        return Cache::has($key);
    }

    private function saveProgress(int $nodeId, string $run, int $sequence, int $baseSequence, int $seenAt): void
    {
        if (!Cache::forever($this->progressKey($nodeId), [
            'run' => $run, 'sequence' => $sequence, 'base_sequence' => $baseSequence, 'seen_at' => $seenAt,
        ])) {
            throw new \RuntimeException('设备来源续期写入失败');
        }
    }

    private function withProgress(?array $state, ?array $progress): ?array
    {
        // 完整快照与续期可能被并发读取；只有同一基线才能合并，不能跨代确认关闭。
        if ($state !== null && $progress !== null && $state['run'] === $progress['run']
            && (int) $state['sequence'] === $progress['base_sequence']) {
            $state['sequence'] = $progress['sequence'];
            $state['seen_at'] = $progress['seen_at'];
        }
        return $state;
    }

    public function admit(int $nodeId, int $userId, int $limit, array $data): array
    {
        return Cache::lock($this->nodeKey($nodeId) . ':lock', 15)->betweenBlockedAttemptsSleepFor(25)->block(2, function () use ($nodeId, $userId, $limit, $data): array {
            $node = isset($data['snapshot']) ? $this->storeSnapshot($nodeId, $data['snapshot']) : $this->node($nodeId, $data['run']);
            if (!empty($data['lease_cache'])) $this->requireLeaseCache($node);
            // 较新状态已确认申请结束，迟到的旧快照不能复活该申请。
            if (isset($data['snapshot']) && !isset($node['pending'][$data['sequence']])) {
                throw new PreconditionFailedHttpException('设备来源申请已结束');
            }
            return $this->locked($userId, function (array &$state, array $reads) use ($nodeId, $userId, $limit, $data): array {
                // 合并请求只协调本次账号，其他账号仍由周期同步处理。
                return $this->decide($state, $nodeId, $userId, $limit, $data, $reads);
            }, [$nodeId => $node]);
        });
    }

    private function decide(array &$state, int $nodeId, int $userId, int $limit, array $data, array $reads): array
    {
        if (!empty($data['lease_cache']) && isset($data['snapshot'])
            && ($reads['nodes'][$nodeId]['sequence'] ?? null) === (int) $data['snapshot']['sequence']) {
            $this->retireLeases($state, $nodeId, $data['snapshot']);
        }
        $reply = $this->decideAdmission($state, $nodeId, $userId, $limit, $data, $reads);
        if (isset($data['snapshot'])) {
            $reply['sync'] = ['run' => $data['snapshot']['run'], 'sequence' => $data['snapshot']['sequence'],
                'revoked' => $this->revocations($state, $nodeId)];
        }
        return $reply;
    }

    private function decideAdmission(array &$state, int $nodeId, int $userId, int $limit, array $data, array $reads): array
    {
        $this->reconcile($state, $reads);
        $raw = PublicDeviceIp::normalize($data['ip']);
        if ($raw === null) return $this->answer('denied', $limit, 0, 'invalid_source');
        $key = DeviceIpExclusion::countKey($raw);
        $groups = $this->groups($state);
        $legacy = $this->legacyGroups($userId);
        $count = count($groups + $legacy);
        $now = $this->now();
        $deny = fn (string $reason) => $this->denied($limit, $count, $reason, $raw, $groups, $legacy);

        $existing = $state['sources'][$raw] ?? null;
        if ($existing !== null && !$existing['revoked']) {
            return $this->grant($state, $nodeId, $userId, $raw, $data, $limit, $count);
        }
        // 已撤销但仍有离线持有者的来源不能在同 IP 重试时复活。
        if ($existing !== null) return $this->waiting($state, $nodeId, $limit, $count);
        if ($key === null || $limit <= 0) {
            if ($existing !== null) return $this->waiting($state, $nodeId, $limit, $count);
            return $this->grant($state, $nodeId, $userId, $raw, $data, $limit, $count);
        }

        $pending = $state['pending'];
        if ($pending !== null) {
            if ($pending['target'] !== '' && $pending['target'] !== $key) {
                return $deny('replacement_pending');
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
            return $deny('cooldown');
        }
        // 旧节点无法确认关闭，也没有可比较的连接时间，混用时保留原来的拒绝规则。
        if ($legacy !== []) return $deny('legacy_node');

        uasort($groups, fn (array $a, array $b) => ($a['last_new_at'] <=> $b['last_new_at']) ?: strcmp($a['key'], $b['key']));
        $victim = reset($groups);
        if ($victim === false) return $deny('unknown_state');
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
        $reply = $this->answer('allowed', $limit, $count) + ['lease' => $source['lease']];
        if (!empty($data['lease_cache'])) {
            // 等待中的同一申请会携带更新快照；按本次核验序号确认，不能返回撤销前的旧期限。
            $reply += $this->confirmLease($source, $nodeId, $data['run'], $data['snapshot']['sequence'] ?? $data['sequence']);
            $reply['server_time'] = $this->now();
        }
        return $reply;
    }

    private function reconcile(array &$state, array $reads): void
    {
        $now = $this->now();
        foreach ($state['sources'] as $ip => &$source) {
            $this->expireLeaseHolders($source, $now);
            foreach ($source['owners'] as $nodeId => &$owner) {
                if (!isset($reads['existing_nodes'][$nodeId])) {
                    unset($source['owners'][$nodeId]);
                    continue;
                }
                // 停用不是连接已关闭的证明；进入定向撤销并保留占位等待确认。
                if (!$reads['existing_nodes'][$nodeId]) $owner['retiring'] = true;
                $node = $reads['nodes'][$nodeId] ?? null;
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
            if ($source['owners'] === [] && empty($source['holders'])) unset($state['sources'][$ip]);
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
        $sources = $this->devices->getDeviceSources($userId);
        $nodeIds = array_unique(array_merge([], ...array_values($sources)));
        $existingNodes = $nodeIds === [] ? [] : Server::query()->whereIn('id', $nodeIds)->pluck('id')->flip()->all();
        foreach ($sources as $key => $nodes) {
            foreach ($nodes as $nodeId) {
                if (!isset($existingNodes[$nodeId])) continue;
                $state = $this->withProgress(Cache::get($this->nodeKey((int) $nodeId)), Cache::get($this->progressKey((int) $nodeId)));
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
            if (($source['revoked'] && (isset($source['owners'][$nodeId]) || $this->nodeHoldsLease($source, $nodeId)))
                || !empty($source['owners'][$nodeId]['retiring'])) {
                $result[] = ['user_id' => $source['user_id'], 'ip' => $source['ip'], 'lease' => $source['lease']];
            }
        }
        return $result;
    }

    private function locked(int $userId, callable $action, array $knownNodes = [], ?array &$existingNodes = null, ?DeviceCoordinationReadContext $readContext = null): mixed
    {
        $key = 'device_handover:user:' . $userId;
        return Cache::lock($key . ':lock', 15)->betweenBlockedAttemptsSleepFor(25)->block(2, function () use ($key, $userId, $action, $knownNodes, &$existingNodes, $readContext): mixed {
            $previous = Cache::get($key);
            $state = $previous ?? ['sources' => [], 'cooldown' => [], 'pending' => null];
            $reads = $this->coordinationReads($state, $knownNodes, $existingNodes, $readContext);
            $result = $action($state, $reads);
            // 空记录按原期限退出协调，不能被每轮同步无限续期；重复确认也不重写权威状态。
            $reserved = $state['sources'] !== [] || $state['pending'] !== null;
            if ($state !== $previous || ($reserved && !$this->cacheKeyExists($key))) {
                $saved = $state['sources'] === [] && $state['pending'] === null
                    ? Cache::put($key, $state, 120) : Cache::forever($key, $state);
                if (!$saved) throw new \RuntimeException('设备来源名额写入失败');
            }
            // 状态未变也核对队列，保留缓存丢失与部分失败后的修复能力。
            $this->ensureQueued($userId, $state, $reads['queues']);
            // 已退出的离线节点可能永远不会再同步；保存成功后按账号移除其旧队列项。
            // 当前请求节点仍可能需要接收未知来源的撤销，由其同步路径决定退出。
            foreach (array_diff_key($this->queueNodes($previous ?? ['sources' => []]), $this->queueNodes($state), $knownNodes) as $nodeId => $_) {
                if (in_array($userId, $reads['queues'][$this->queueKey($nodeId)] ?? [], true)) {
                    $this->queue((int) $nodeId, $userId, false);
                }
            }
            return $result;
        });
    }

    /** 只在当前账号锁内复用读取；knownNodes 必须来自调用方仍持有的节点锁。 */
    private function coordinationReads(array $state, array $knownNodes, ?array &$existingNodes, ?DeviceCoordinationReadContext $readContext): array
    {
        $owners = [];
        foreach ($state['sources'] as $source) {
            foreach ($source['owners'] as $nodeId => $_) $owners[(int) $nodeId] = true;
        }
        $queueNodes = $this->queueNodes($state);
        foreach (array_keys($knownNodes) as $nodeId) $queueNodes[$nodeId] = true;
        $queueKeys = array_map(fn ($id) => $this->queueKey($id), array_keys($queueNodes));
        $keys = $queueKeys;
        foreach (array_keys($owners) as $nodeId) {
            if (array_key_exists($nodeId, $knownNodes)) continue;
            $keys[] = $this->nodeKey($nodeId);
            $keys[] = $this->progressKey($nodeId);
        }
        $values = $readContext === null ? Cache::many($keys) : $readContext->many($keys);
        $nodes = $knownNodes;
        foreach (array_keys($owners) as $nodeId) {
            if (array_key_exists($nodeId, $nodes)) continue;
            $nodes[$nodeId] = $this->withProgress($values[$this->nodeKey($nodeId)] ?? null, $values[$this->progressKey($nodeId)] ?? null);
        }
        // 本轮只复用“存在”的结论；删除至多延至下一轮核对，缺失结果不缓存，避免漏掉并发创建。
        $existing = $existingNodes ?? [];
        $unknown = array_diff_key($owners, $existing);
        if ($unknown !== []) {
            foreach (Server::query()->whereIn('id', array_keys($unknown))->pluck('enabled', 'id') as $nodeId => $enabled) {
                $existing[(int) $nodeId] = (bool) $enabled;
            }
        }
        if ($existingNodes !== null) $existingNodes = $existing;
        return ['nodes' => $nodes, 'existing_nodes' => $existing, 'queues' => array_intersect_key($values, array_flip($queueKeys))];
    }

    private function queueNodes(array $state): array
    {
        $nodes = [];
        foreach ($state['sources'] as $source) {
            foreach ($source['holders'] ?? [] as $holder) $nodes[(int) $holder['node_id']] = true;
            foreach ($source['owners'] as $nodeId => $owner) {
                if ($source['revoked'] || $owner['connect_sequence'] === 0 || !empty($owner['retiring'])) $nodes[(int) $nodeId] = true;
            }
        }
        return $nodes;
    }

    /** 队列读取来自同一账号锁；其他账号的并发变更仍由原队列锁合并。 */
    private function ensureQueued(int $userId, array $state, array $queues = []): void
    {
        $nodes = $this->queueNodes($state);
        if ($nodes === []) return;
        $keys = array_map(fn ($nodeId) => $this->queueKey($nodeId), array_keys($nodes));
        $missing = array_values(array_diff($keys, array_keys($queues)));
        if ($missing !== []) $queues += Cache::many($missing);
        foreach (array_keys($nodes) as $nodeId) {
            // 同账号的增删都受账号锁保护；其他账号仍可并发改变同一节点队列。
            if (!in_array($userId, $queues[$this->queueKey($nodeId)] ?? [], true)) {
                $this->queue($nodeId, $userId, true);
            }
        }
    }

    private function queue(int $nodeId, int $userId, bool $add): void
    {
        $key = $this->queueKey($nodeId);
        Cache::lock($key . ':lock', 15)->betweenBlockedAttemptsSleepFor(25)->block(2, function () use ($key, $userId, $add): void {
            $users = array_fill_keys(Cache::get($key, []), true);
            if (isset($users[$userId]) === $add) return;
            if ($add) $users[$userId] = true;
            else unset($users[$userId]);
            Cache::forever($key, array_keys($users));
        });
    }

    private function node(int $nodeId, string $run, bool $withProgress = true): array
    {
        $state = Cache::get($this->nodeKey($nodeId));
        if (!is_array($state) || ($state['run'] ?? null) !== $run) throw new ConflictHttpException('设备来源会话已失效');
        return $withProgress ? $this->withProgress($state, Cache::get($this->progressKey($nodeId))) : $state;
    }

    private function answer(string $status, int $limit, int $count, string $reason = ''): array
    {
        return ['status' => $status, 'limit' => max(0, $limit), 'observed' => $count, 'reason' => $reason];
    }

    /** 在名额锁内保存拒绝所依据的来源；旧节点的 IPv6 只掌握网段时明确标明 /64。 */
    private function denied(int $limit, int $count, string $reason, string $source, array $groups, array $legacy): array
    {
        $ips = [];
        foreach ($groups as $group) {
            foreach ($group['ips'] as $ip) $ips[$ip] = true;
        }
        foreach ($legacy as $key => $_) {
            if (isset($groups[$key])) continue;
            $ips[str_contains($key, ':') ? $key . '/64' : $key] = true;
        }
        $active = array_keys($ips);
        sort($active, SORT_STRING);
        return $this->answer('denied', $limit, $count, $reason) + ['limit_snapshot' => [
            'occurred_at' => now()->timestamp, 'limit' => $limit, 'observed' => $count, 'source_ip' => $source,
            'active_ips' => array_slice($active, 0, 5), 'active_total' => count($active), 'active_known' => true,
        ]];
    }

    private function now(): int { return now()->getTimestampMs(); }
    private function nodeKey(int $nodeId): string { return 'device_handover:node:' . $nodeId; }
    private function progressKey(int $nodeId): string { return $this->nodeKey($nodeId) . ':progress'; }
    private function queueKey(int $nodeId): string { return 'device_handover:queue:' . $nodeId; }
}
