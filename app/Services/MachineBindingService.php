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
            NodeRuntimeMetadata::invalidate(Server::class);
        }, 3);
    }

    public static function matchesDeletion(int $id, string $hash): bool
    {
        $stored = DB::table('v2_machine_binding_deletion')->where('machine_id', $id)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('v2_server_machine')->where('id', $id))
            ->value('token_hash');
        return is_string($stored) && hash_equals($stored, $hash);
    }

    public static function deletionProof(int $id, string $token, string $nonce): array
    {
        $hash = hash('sha256', $token);
        abort_unless(self::matchesDeletion($id, $hash), 403);
        return [
            'version' => 1,
            'machine_id' => $id,
            'state' => 'deleted',
            'nonce' => $nonce,
            'proof' => hash_hmac('sha256', "yz-agent-machine-deleted-v1\n{$id}\n{$nonce}", hex2bin($hash)),
        ];
    }
}
