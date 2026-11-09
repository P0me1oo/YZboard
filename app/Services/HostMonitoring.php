<?php

namespace App\Services;

use App\Models\Server;
use App\Models\ServerMachine;
use Illuminate\Support\Facades\DB;

/** 只根据已保存的接入开关和绑定判断，不因 Lite 暂时不可达重新采样。 */
final class HostMonitoring
{
    public static function enabled(?ServerMachine $machine = null, ?Server $node = null): bool
    {
        $machineId = $machine?->id ?? $node?->machine_id;
        if (!$machineId) return true;

        return !DB::table('v2_lite_machine')
            ->where('machine_id', $machineId)
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('v2_lite_connection')
                ->where('id', 1)->where('enabled', true))
            ->exists();
    }

    public static function withoutHostState(array $state): array
    {
        if (is_array($state['status'] ?? null)) {
            $state['status'] = array_intersect_key($state['status'], ['kernel_status' => true]);
            if ($state['status'] === []) unset($state['status']);
        }
        if (is_array($state['metrics'] ?? null)) {
            unset($state['metrics']['cpu_per_core'], $state['metrics']['load']);
        }
        return $state;
    }
}
