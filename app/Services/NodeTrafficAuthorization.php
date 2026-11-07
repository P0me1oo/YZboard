<?php

namespace App\Services;

use App\Models\Server;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** 结算权限独立于实时额度；仅实际下发过的名单保留七天补报宽限。 */
class NodeTrafficAuthorization
{
    public const GRACE_SECONDS = 7 * 86400;

    public function remember(Server $node, iterable $users): void
    {
        $ids = collect($users)->pluck('id')->map(fn ($id) => (int) $id)->filter(fn ($id) => $id > 0)->unique()->all();
        foreach (array_chunk($ids, 500) as $chunk) {
            $now = now()->timestamp;
            $fresh = DB::table('v2_node_traffic_grant')->where('server_id', $node->id)
                ->where('machine_id', (int) $node->machine_id)->whereIn('user_id', $chunk)
                ->where('expires_at', '>', $now + self::GRACE_SECONDS - 3600)->pluck('user_id')->all();
            $rows = array_map(fn ($id) => ['server_id' => (int) $node->id, 'machine_id' => (int) $node->machine_id,
                'user_id' => $id, 'expires_at' => $now + self::GRACE_SECONDS], array_values(array_diff($chunk, $fresh)));
            if ($rows !== []) DB::table('v2_node_traffic_grant')->upsert($rows, ['server_id', 'machine_id', 'user_id'], ['expires_at']);
        }
    }

    public function assertAllowed(Server $node, array $ids): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) return;
        $allowed = ServerService::getTrafficUsers($node, $ids)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $missing = array_values(array_diff($ids, $allowed));
        foreach (array_chunk($missing, 500) as $chunk) {
            $historical = DB::table('v2_node_traffic_grant')->where('server_id', $node->id)
                ->where('machine_id', (int) $node->machine_id)->whereIn('user_id', $chunk)
                ->where('expires_at', '>', now()->timestamp)->pluck('user_id')->all();
            if (array_diff($chunk, $historical) !== []) {
                throw ValidationException::withMessages(['traffic' => '报告包含未授权的用户']);
            }
        }
    }
}
