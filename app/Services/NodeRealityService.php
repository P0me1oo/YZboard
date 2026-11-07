<?php

namespace App\Services;

use App\Models\BalancerMember;
use App\Models\BalancerPool;
use App\Models\Server;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Validation\ValidationException;

class NodeRealityService
{
    /** 只返回确认操作范围所需的信息，不返回池模板或密钥。 */
    public function scope(array $ids): array
    {
        $this->validateNodes(Server::whereIn('id', $ids)->get()->keyBy('id'), $ids);
        $pools = BalancerPool::whereHas('members', fn ($query) => $query->whereIn('source_node_id', $ids))
            ->orderBy('id')->get(['id', 'name']);
        return ['count' => count($ids), 'pools' => $pools->map(fn ($pool) => $pool->only(['id', 'name']))->values()->all()];
    }

    public function apply(array $input): array
    {
        $locks = [];
        try {
            foreach (collect($input['pool_ids'] ?? [])->map(fn ($id) => (int) $id)->sort()->values() as $poolId) {
                $lock = Cache::lock('balancer:dns:' . $poolId, 120);
                $lock->block(2);
                $locks[] = $lock;
            }
            return $this->persist($input);
        } catch (LockTimeoutException $e) {
            throw ValidationException::withMessages(['pool_ids' => '关联前置池正在更新，请稍后重试']);
        } finally {
            foreach (array_reverse($locks) as $lock) $lock->release();
        }
    }

    private function persist(array $input): array
    {
        return DB::transaction(function () use ($input) {
            $ids = array_map('intval', $input['ids']);
            $poolIds = BalancerMember::whereIn('source_node_id', $ids)->pluck('pool_id')->unique()->sort()->values()->all();
            $confirmed = collect($input['pool_ids'] ?? [])->map(fn ($id) => (int) $id)->sort()->values()->all();
            if ($confirmed !== array_map('intval', $poolIds)) $this->scopeChanged();
            $pools = BalancerPool::whereIn('id', $poolIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($pools->count() !== count($poolIds)) $this->scopeChanged();
            $members = BalancerMember::whereIn('pool_id', $poolIds)->orderBy('pool_id')->orderBy('id')->lockForUpdate()->get();
            $nodeIds = $members->pluck('source_node_id')->merge($ids)->unique()->values();
            $allNodes = Server::whereIn('id', $nodeIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $nodes = $allNodes->only($ids)->keyBy('id');
            $this->validateNodes($nodes, $ids);
            // 当前读取再次检查新关联，避免确认后增加的池被遗漏；节点锁保护后续写入。
            $currentPoolIds = BalancerMember::whereIn('source_node_id', $ids)->orderBy('pool_id')->lockForUpdate()
                ->get()->pluck('pool_id')->unique()->values()->map(fn ($id) => (int) $id)->all();
            if ($currentPoolIds !== $confirmed) $this->scopeChanged();

            if ($input['mode'] === 'node') {
                $source = $nodes->get((int) $input['source_id']);
                if (!$source) throw ValidationException::withMessages(['source_id' => '请选择本次勾选的节点作为基准']);
                $settings = PoolRealityService::prepare(new BalancerPool(), $source, []);
            } else {
                $settings = PoolRealityService::prepare(new BalancerPool(), $nodes->first(), [
                    ...$input['reality_settings'], 'short_id' => $input['reality_settings']['short_id'] ?? '',
                ]);
            }
            foreach ($nodes as $node) PoolRealityService::apply($node, $settings);

            $missing = [];
            foreach ($pools as $pool) {
                if ($pool->mode !== 'dns') {
                    throw ValidationException::withMessages(['pool_ids' => $pool->name . '：旧转发模式暂不支持此联动']);
                }
                foreach ($members->where('pool_id', $pool->id) as $member) {
                    $node = $allNodes->get($member->source_node_id);
                    if (!$node || (int) $member->runtime_node_id !== (int) $member->source_node_id) $this->scopeChanged();
                    if (!$nodes->has($node->id) && !$this->matches((array) data_get($node->protocol_settings, 'reality_settings', []), $settings)) {
                        $missing[$pool->id][] = $node->name . '（ID:' . $node->id . '）';
                    }
                }
            }
            if ($missing) {
                throw ValidationException::withMessages(['ids' => collect($missing)->map(
                    fn ($names, $poolId) => $pools[$poolId]->name . '：请补选 ' . implode('、', $names) . '，这些成员仍使用不同的 Reality 参数'
                )->values()->all()]);
            }

            $updatedPools = [];
            foreach ($pools as $pool) {
                $template = (array) $pool->template_config;
                $reality = (array) data_get($template, 'protocol_settings.reality_settings', []);
                if ($this->matches($reality, $settings)) continue;
                $template['protocol_settings']['reality_settings'] = array_replace($reality, $settings);
                $pool->template_config = $template;
                $updatedPools[] = $pool;
            }
            // 所有节点和关联池通过检查后，才在同一事务内保存；未勾选节点不写入。
            foreach ($updatedPools as $pool) $pool->save();
            $updated = 0;
            foreach ($nodes as $node) {
                if ($node->isDirty()) { $node->save(); $updated++; }
            }
            foreach ($updatedPools as $pool) DB::afterCommit(fn () => app(LoadBalancerService::class)->notify($pool));
            return ['count' => $nodes->count(), 'updated' => $updated, 'pools_updated' => count($updatedPools)];
        }, 3);
    }

    private function validateNodes(Collection $nodes, array $ids): void
    {
        if ($nodes->count() !== count($ids)) {
            throw ValidationException::withMessages(['ids' => '所选节点已发生变化，请刷新后重新选择']);
        }
        foreach ($nodes as $node) {
            if (!in_array($node->type, ['vless', 'trojan'], true) || (int) data_get($node->protocol_settings, 'tls') !== 2) {
                throw ValidationException::withMessages(['ids' => $node->name . '：请选择 VLESS 或 Trojan Reality 节点']);
            }
            if ($node->parent_id || $node->balancer_role !== null) {
                throw ValidationException::withMessages(['ids' => $node->name . '：请选择实际运行的独立节点']);
            }
        }
    }

    private function matches(array $current, array $settings): bool
    {
        foreach (PoolRealityService::FIELDS as $field) {
            $value = trim((string) ($current[$field] ?? ($field === 'server_port' ? 443 : '')));
            if (in_array($field, ['server_name', 'short_id'], true)) $value = strtolower($value);
            if ($field === 'server_name') $value = rtrim($value, '.');
            if ($field === 'server_port') {
                if ($value !== '' && !ctype_digit($value)) return false;
                $value = $value === '' ? 443 : (int) $value;
            }
            if ($value !== $settings[$field]) return false;
        }
        return true;
    }

    private function scopeChanged(): never
    {
        throw ValidationException::withMessages(['pool_ids' => '关联前置池或成员已变化，请重新读取后确认']);
    }
}
