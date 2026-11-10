<?php

namespace App\Services;

use App\Models\Server;
use App\Models\ServerMachine;
use Illuminate\Support\Facades\DB;

class MachineBindingService
{
    public static function delete(int $id): void
    {
        DB::transaction(function () use ($id) {
            $machine = ServerMachine::whereKey($id)->lockForUpdate()->firstOrFail();
            // 与删除一起提交；离线 Agent 以后仍可核实，不能只保存在有过期时间的缓存里。
            DB::table('v2_machine_binding_deletion')->updateOrInsert(['machine_id' => $id], [
                'token_hash' => hash('sha256', $machine->token),
                'deleted_at' => now(),
            ]);
            Server::where('machine_id', $id)->update(['machine_id' => null]);
            $machine->delete();
            DB::afterCommit(function () use ($id) {
                NodeRuntimeMetadata::invalidate(Server::class);
                NodeSyncService::notifyMachineNodesChanged($id);
            });
        }, 3);
    }

    public static function status(int $id, string $token, string $nonce): array
    {
        $hash = hash('sha256', $token);
        $machine = ServerMachine::find($id);
        if ($machine) {
            abort_unless(hash_equals(hash('sha256', $machine->token), $hash), 403);
            return ['version' => 1, 'machine_id' => $id, 'state' => $machine->is_active ? 'active' : 'disabled'];
        }

        $deleted = DB::table('v2_machine_binding_deletion')->where('machine_id', $id)->first();
        // 不存在的编号、重置前的旧令牌、升级前已删除的记录均不能作为自动解绑依据。
        abort_unless($deleted && hash_equals($deleted->token_hash, $hash), 403);
        return [
            'version' => 1,
            'machine_id' => $id,
            'state' => 'deleted',
            'nonce' => $nonce,
            'proof' => hash_hmac('sha256', "yz-agent-machine-deleted-v1\n{$id}\n{$nonce}", hex2bin($hash)),
        ];
    }
}
