<?php

namespace App\Services;

use App\Models\ServerMachine;
use App\Models\ServerMachineLoadHistory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class MachineStateService
{
    public function __construct(private readonly RealtimeStateStore $store)
    {
    }

    public function accept(ServerMachine $machine, array $message): array
    {
        abort_unless($machine->is_active, 403, 'Machine is disabled');
        $validated = Validator::make($message, [
            'epoch' => ['required', 'string', 'regex:/\A[a-f0-9]{32}\z/'],
            'sequence' => 'required|integer|min:1|max:9007199254740991',
            'telemetry_mode' => 'sometimes|integer|in:1',
            'state' => 'present|array',
            'state.status' => ($message['telemetry_mode'] ?? null) === 1 ? 'sometimes|required|array' : 'required|array',
            'state.status.cpu' => 'required_with:state.status|numeric|min:0|max:100',
            'state.status.mem.total' => 'required_with:state.status|integer|min:0',
            'state.status.mem.used' => 'required_with:state.status|integer|min:0',
            'state.status.swap.total' => 'sometimes|integer|min:0',
            'state.status.swap.used' => 'sometimes|integer|min:0',
            'state.status.disk.total' => 'sometimes|integer|min:0',
            'state.status.disk.used' => 'sometimes|integer|min:0',
            'state.status.net.in_speed' => 'sometimes|numeric|min:0',
            'state.status.net.out_speed' => 'sometimes|numeric|min:0',
        ])->validate();
        $state = [];
        if (isset($validated['state']['status'])) {
            $state['status'] = array_intersect_key($validated['state']['status'], array_flip(['cpu', 'mem', 'swap', 'disk', 'net']));
        }
        $source = 'machine:' . $machine->id;
        $receipt = $this->store->accept($source, $validated['epoch'], (int) $validated['sequence'], $state);
        if ($state !== []) $this->persistLatest($machine);
        return $receipt + ['telemetry' => app(TelemetryDemand::class)->forSource($source)];
    }

    /** 当前显示直接读取缓存；历史曲线和机器表最多每分钟保存一次，避免每秒写数据库。 */
    private function persistLatest(ServerMachine $machine): void
    {
        $key = 'realtime:machine:history:' . $machine->id;
        Cache::lock($key . ':lock', 20)->block(2, function () use ($machine, $key): void {
            if (Cache::has($key)) return;
            $snapshot = $this->store->read('machine:' . $machine->id);
            if (!$snapshot || !$snapshot['fresh'] || !isset($snapshot['data']['status'])) return;
            $status = $snapshot['data']['status'];
            $recordedAt = intdiv($snapshot['received_at'], 1000);
            $status['updated_at'] = $recordedAt;
            DB::transaction(function () use ($machine, $status, $recordedAt): void {
                $machine->forceFill(['load_status' => $status, 'last_seen_at' => $recordedAt])->saveQuietly();
                ServerMachineLoadHistory::create([
                    'machine_id' => $machine->id,
                    'cpu' => $status['cpu'],
                    'mem_total' => $status['mem']['total'],
                    'mem_used' => $status['mem']['used'],
                    'disk_total' => $status['disk']['total'] ?? 0,
                    'disk_used' => $status['disk']['used'] ?? 0,
                    'net_in_speed' => $status['net']['in_speed'] ?? null,
                    'net_out_speed' => $status['net']['out_speed'] ?? null,
                    'recorded_at' => $recordedAt,
                ]);
            });
            Cache::put($key, true, 60);
        });
    }
}
