<?php

namespace App\Services;

use App\Models\BalancerPool;
use App\Models\LandingPool;
use App\Models\Server;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** 主备复用成员自己的内部凭据，订阅、权限和计费始终归属原逻辑线路。 */
class LandingPoolService
{
    public static function entries(Server $node): Collection
    {
        if ($node->relay_pool_id) return BalancerPool::find($node->relay_pool_id)?->members()->with('runtimeNode.machine')->get()->pluck('runtimeNode')->filter() ?? collect();
        return collect([$node->relayEntry])->filter();
    }

    public static function compatibleAgent(Server $entry): bool
    {
        $version = ltrim((string) data_get($entry->machine?->agent_runtime, 'version', ''), 'v');
        return preg_match('/\A\d+\.\d+\.\d+(?:[-+].*)?\z/', $version) && version_compare($version, '2.30.0', '>=');
    }

    public static function compatible(Server $primary, Server $member, ?Server $entry = null): bool
    {
        return $primary->isRelayChild() && $member->isRelayChild() && !$member->parent_id
            && (int) $primary->relay_pool_id === (int) $member->relay_pool_id
            && (int) $primary->relay_entry_id === (int) $member->relay_entry_id
            && !ServerRelayService::validateTransitSettings($member->type, $member->protocol_settings, $member->host, $member->kernel_type)
            && ($entry ? collect([$entry]) : self::entries($primary))->every(fn ($entry) => !ServerRelayService::validateTransitSettings($member->type, $member->protocol_settings, $member->host, $entry->kernel_type));
    }

    public function save(array $data): LandingPool
    {
        return DB::transaction(function () use ($data) {
            $pool = !empty($data['id']) ? LandingPool::query()->lockForUpdate()->findOrFail($data['id']) : new LandingPool();
            if ($pool->exists && $pool->node_id !== (int) $data['node_id']) $this->invalid('node_id', '更换订阅线路请新建落地池');
            $primary = Server::with('machine')->findOrFail($data['node_id']);
            $ids = array_values(array_map('intval', $data['members']));
            if (($ids[0] ?? null) !== (int) $primary->id || count(array_unique($ids)) !== count($ids)) $this->invalid('members', '第一成员必须是订阅线路原落地，成员不能重复');
            $nodes = Server::with('machine')->whereIn('id', $ids)->get()->keyBy('id');
            foreach ($ids as $id) if (!$nodes->has($id) || !self::compatible($primary, $nodes[$id])) $this->invalid('members', '落地成员必须绑定相同前置，且协议与前置内核兼容');
            if ($nodes->pluck('machine_id')->filter()->unique()->count() !== count($nodes)) $this->invalid('members', '每台服务器只能选择一个落地成员');
            if ($nodes->contains(fn ($node) => LandingPool::where('node_id', $node->id)->when($pool->exists, fn ($q) => $q->where('id', '!=', $pool->id))->exists())) $this->invalid('members', '成员不能是另一落地池的订阅线路');
            if (LandingPool::where('id', '!=', $pool->id ?? 0)->get()->contains(fn ($other) => in_array($primary->id, $other->members, true))) $this->invalid('node_id', '该线路已是另一落地池的成员');
            $entries = self::entries($primary);
            if ($entries->isEmpty()) $this->invalid('node_id', '请选择已经绑定前置节点或前置池的落地线路');
            if ($data['enabled'] && $entries->contains(fn ($entry) => !self::compatibleAgent($entry))) $this->invalid('enabled', '前置服务器需要先运行 Agent v2.30.0 或更新版本');
            $pool->fill($data);
            $pool->members = $ids;
            // 编辑成员时保留启动时间，防止反复保存延长失效测量的宽限期。
            $state = $pool->state ?? ['started_at' => now()->timestamp, 'active' => [], 'members' => []];
            if ($data['enabled'] && (!$pool->exists || !$pool->getOriginal('enabled'))) $state['started_at'] = now()->timestamp;
            $pool->state = $state;
            $pool->save();
            if ($pool->enabled && $entries->contains(fn ($entry) => count(SchedulingProbeService::targets($entry, false)) > 256)) $this->invalid('members', '前置的探测目标超过 256 个，请减少该前置关联的主备线路');
            DB::afterCommit(function () use ($pool, $entries) {
                foreach ($entries as $entry) { Cache::forget('scheduling:targets:' . $entry->id); NodeSyncService::notifyConfigUpdated($entry->id); }
                $this->sync($pool);
            });
            return $pool->fresh();
        });
    }

    public static function targetFor(Server $child, Server $entry, LandingPool|false|null $pool = null): ?Server
    {
        if ($child->enabled === false) return null;
        $pool ??= LandingPool::where('node_id', $child->id)->first();
        if (!$pool || !$pool->enabled) return $child;
        if (!self::compatibleAgent($entry)) return null;
        $id = $pool->state['active'][(string) $entry->id] ?? null;
        if (!$id || !in_array((int) $id, $pool->members, true)) return null;
        $target = Server::with('machine')->find($id);
        if (!$target || !self::compatible($child, $target, $entry) || !self::available($target)) return null;
        $probe = SchedulingProbeService::target($target) ? SchedulingProbeService::result($entry, $target) : null;
        if ($probe && !$probe['healthy']) return null;
        if (SchedulingProbeService::target($target) && !$probe && now()->timestamp - ($pool->state['started_at'] ?? 0) > 90) return null;
        return $target;
    }

    private static function available(Server $node): bool
    {
        return $node->enabled !== false && $node->machine?->is_active === true
            && $node->available_status !== Server::STATUS_OFFLINE && !MachineTrafficService::blocked($node->machine);
    }

    public function sync(LandingPool $pool): void
    {
        Cache::lock('scheduling:landing:' . $pool->id, 30)->get(function () use ($pool) {
            $pool = $pool->fresh(['node.machine']);
            if (!$pool) return;
            if (!$pool->node) {
                SchedulingEvents::record('landing', $pool->id, $pool->name, 'landing_changed', $pool->state['active'] ?? [], []);
                $pool->update(['state' => ['active' => [], 'members' => [], 'checked_at' => now()->timestamp]]);
                return;
            }
            $members = Server::with('machine')->whereIn('id', $pool->members)->get()->keyBy('id');
            $state = ['started_at' => $pool->state['started_at'] ?? now()->timestamp, 'active' => [], 'members' => [], 'checked_at' => now()->timestamp];
            $entries = self::entries($pool->node);
            foreach ($entries as $entry) {
                foreach ($pool->members as $id) {
                    $node = $members->get($id);
                    $reason = !$pool->enabled || $pool->node->enabled === false ? 'disabled' : (!$node || !self::compatible($pool->node, $node, $entry) ? 'incompatible'
                        : (!self::available($node) ? 'offline' : (!self::compatibleAgent($entry) ? 'agent_required' : 'healthy')));
                    $probe = $node && SchedulingProbeService::target($node) ? SchedulingProbeService::result($entry, $node) : null;
                    if ($reason === 'healthy') {
                        if (!SchedulingProbeService::target($node)) $reason = 'heartbeat_only';
                        elseif (!$probe) $reason = 'probe_pending';
                        elseif (!$probe['healthy']) $reason = 'port_failed';
                    }
                    $state['members'][(string) $entry->id][] = ['node_id' => $id, 'name' => $node?->name ?? '', 'reason' => $reason,
                        'latency_ms' => $probe && $probe['healthy'] ? $probe['rtt_ms'] : null];
                    $eligible = in_array($reason, ['healthy', 'heartbeat_only'], true)
                        || ($reason === 'probe_pending' && now()->timestamp - $state['started_at'] <= 90);
                    if ($pool->enabled && $eligible && !isset($state['active'][(string) $entry->id])) $state['active'][(string) $entry->id] = $id;
                }
            }
            $before = $pool->state['active'] ?? []; $after = $state['active'];
            $pool->update(['state' => $state]);
            SchedulingEvents::record('landing', $pool->id, $pool->name, 'landing_changed', $before, $after);
            if ($before !== $after) foreach ($entries as $entry) NodeSyncService::notifyConfigUpdated($entry->id);
        });
    }

    public function syncForEntry(Server $entry): void
    {
        foreach (LandingPool::with('node')->where('enabled', true)->get() as $pool) {
            if ($pool->node && ServerRelayService::ownsChild($entry, $pool->node)) $this->sync($pool);
        }
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
