<?php

namespace App\Services;

use App\Models\Server;
use Illuminate\Validation\ValidationException;

final class ServerTopologyGuard
{
    public static function parent(Server $node): void
    {
        // 表单校验后引用仍可能被并发删除，保存时与删除共用节点行锁。
        if ($node->relayEntryId() && !Server::whereKey($node->relayEntryId())->lockForUpdate()->first()) {
            throw ValidationException::withMessages(['relay_entry_id' => '前置入口节点不存在，请重新选择']);
        }
        if (!$node->parent_id) return;
        $parent = Server::whereKey($node->parent_id)->lockForUpdate()->first();
        if (!$parent || $parent->id === $node->id || $parent->parent_id || $parent->type !== $node->type) {
            throw ValidationException::withMessages(['parent_id' => '请选择同协议的独立父节点']);
        }
    }

}
