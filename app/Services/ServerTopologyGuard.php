<?php

namespace App\Services;

use App\Models\LandingPool;
use App\Models\Server;
use Illuminate\Validation\ValidationException;

final class ServerTopologyGuard
{
    public static function parent(Server $node): void
    {
        if (!$node->parent_id) return;
        $parent = Server::whereKey($node->parent_id)->lockForUpdate()->first();
        if (!$parent || $parent->id === $node->id || $parent->parent_id || $parent->type !== $node->type) {
            throw ValidationException::withMessages(['parent_id' => '请选择同协议的独立父节点']);
        }
    }

    public static function deletion(array $ids): void
    {
        $referenced = Server::whereNotIn('id', $ids)->where(fn ($query) => $query->whereIn('parent_id', $ids)->orWhereIn('relay_entry_id', $ids))->exists();
        if ($referenced) throw ValidationException::withMessages(['ids' => '节点仍被共享节点或中转线路引用，请先解除关联']);
        foreach (LandingPool::get(['node_id', 'members']) as $pool) {
            $members = array_map('intval', (array) $pool->members);
            if (in_array($pool->node_id, $ids) || array_intersect($members, $ids)) {
                throw ValidationException::withMessages(['ids' => '节点仍被落地池引用，请先解除关联']);
            }
        }
    }
}
