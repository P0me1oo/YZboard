<?php

namespace App\Services;

use App\Models\BalancerMember;
use App\Models\LandingPool;
use App\Models\Server;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** 预览和删除使用同一份影响计算，确认只对管理员实际看到的关联关系有效。 */
class ServerDeletionService
{
    public function preview(array $ids): array
    {
        return $this->impact($this->nodes($ids, false), LandingPool::orderBy('id')->get(), $ids);
    }

    public function delete(array $ids, ?string $confirmation = null, bool $single = false): void
    {
        DB::transaction(function () use ($ids, $confirmation, $single) {
            // 与落地池保存保持相同锁顺序，健康检查也在池行锁内更新状态。
            $pools = LandingPool::orderBy('id')->lockForUpdate()->get();
            $nodes = $this->nodes($ids, true);
            $selected = $nodes->whereIn('id', $ids);
            if ($single && $selected->isEmpty()) {
                throw ValidationException::withMessages(['id' => '节点不存在，可能已被删除']);
            }
            $impact = $this->impact($nodes, $pools, $ids);
            if ($impact['blocked'] !== []) {
                throw ValidationException::withMessages(['ids' => array_column($impact['blocked'], 'message')]);
            }
            $hasReferences = $impact['dependents'] !== [] || $impact['landing_pools'] !== [];
            if (($confirmation !== null && !hash_equals($impact['confirmation'], $confirmation))
                || ($hasReferences && $confirmation === null)) {
                throw ValidationException::withMessages(['confirmation' => '节点关联关系需要确认，请刷新删除影响后再次确认']);
            }

            ServerNameHistory::remember($selected);
            $entryIds = [];
            foreach ($impact['landing_pools'] as $item) {
                $pool = $pools->firstWhere('id', $item['id']);
                if ($pool->node) {
                    foreach (LandingPoolService::entries($pool->node) as $entry) $entryIds[$entry->id] = true;
                }
                if ($item['primary_deleted']) $pool->node_id = null;
                $pool->members = $item['remaining_members'];
                if ($item['disable']) $pool->enabled = false;
                $before = $pool->state['active'] ?? [];
                $pool->state = ['started_at' => $pool->state['started_at'] ?? now()->timestamp,
                    'active' => [], 'members' => [], 'checked_at' => now()->timestamp];
                $pool->save();
                SchedulingEvents::record('landing', $pool->id, $pool->name, 'landing_changed', $before, []);
            }
            foreach ($impact['dependents'] as $item) {
                $node = $nodes->firstWhere('id', $item['id']);
                if ($item['shared']) $node->parent_id = null;
                if ($item['relay']) $node->relay_entry_id = null;
                $node->setRunning(false);
                $node->save();
            }
            foreach ($selected as $node) {
                if ($node->delete() === false) throw new \RuntimeException('删除节点失败');
            }
            DB::afterCommit(function () use ($entryIds, $impact) {
                foreach ($impact['landing_pools'] as $item) {
                    $pool = LandingPool::find($item['id']);
                    if ($pool?->enabled) app(LandingPoolService::class)->sync($pool);
                }
                foreach (array_keys($entryIds) as $id) NodeSyncService::notifyFullSync((int) $id);
                NodeRuntimeMetadata::invalidate(Server::class);
            });
        }, 3);
    }

    private function nodes(array $ids, bool $lock): Collection
    {
        $query = Server::where(fn ($query) => $query->whereIn('id', $ids)
            ->orWhereIn('parent_id', $ids)->orWhereIn('relay_entry_id', $ids))->orderBy('id');
        return ($lock ? $query->lockForUpdate() : $query)->get();
    }

    private function impact(Collection $nodes, Collection $pools, array $ids): array
    {
        $selected = $nodes->whereIn('id', $ids);
        $deleted = array_map('intval', $selected->modelKeys());
        $dependents = $nodes->whereNotIn('id', $ids)
            ->filter(fn (Server $node) => in_array((int) $node->parent_id, $deleted, true)
                || in_array((int) $node->relay_entry_id, $deleted, true))
            ->map(fn (Server $node) => [
            'id' => $node->id, 'name' => $node->name,
            'shared' => in_array((int) $node->parent_id, $deleted, true),
            'relay' => in_array((int) $node->relay_entry_id, $deleted, true),
            'parent_id' => $node->parent_id, 'relay_entry_id' => $node->relay_entry_id,
            'enabled' => $node->enabled, 'show' => $node->show,
        ])->values()->all();
        $disabled = array_column($dependents, 'id');
        $affectedPools = [];
        foreach ($pools as $pool) {
            $members = array_map('intval', (array) $pool->members);
            $primaryDeleted = in_array($pool->node_id, $deleted, true);
            $lostDependency = in_array($pool->node_id, $disabled, true) || array_intersect($members, $disabled) !== [];
            if (!$primaryDeleted && !$lostDependency && array_intersect($members, $deleted) === []) continue;
            $remaining = array_values(array_diff($members, $deleted));
            $affectedPools[] = ['id' => $pool->id, 'name' => $pool->name, 'node_id' => $pool->node_id,
                'members' => $members, 'enabled' => $pool->enabled, 'remaining_members' => $remaining,
                'primary_deleted' => $primaryDeleted, 'disable' => $primaryDeleted || $lostDependency || count($remaining) < 2];
        }
        $blocked = [];
        $sourceIds = BalancerMember::whereIn('source_node_id', $deleted)->pluck('source_node_id')->all();
        foreach ($selected as $node) {
            if (in_array($node->id, $sourceIds)) {
                $blocked[] = ['id' => $node->id, 'name' => $node->name, 'message' => '节点被前置池引用，请先移除对应成员'];
            } elseif ($node->balancer_role === DirectPoolService::ROLE) {
                $blocked[] = ['id' => $node->id, 'name' => $node->name, 'message' => '直出订阅线路请在负载池中删除'];
            }
        }
        $result = ['nodes' => $selected->map(fn ($node) => ['id' => $node->id, 'name' => $node->name])->values()->all(),
            'dependents' => $dependents, 'landing_pools' => $affectedPools, 'blocked' => $blocked];
        $result['confirmation'] = hash('sha256', json_encode($result, JSON_THROW_ON_ERROR));
        return $result;
    }
}
