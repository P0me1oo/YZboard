<?php

namespace App\Http\Controllers\V2\Server;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\ServerMachineLoadHistory;
use App\Services\ServerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * machine controller
 */
class MachineController extends Controller
{
    public function traffic(Request $request): JsonResponse
    {
        $machine = $this->authenticateMachine($request, true);
        $data = $request->validate([
            'sample' => 'nullable|array|min:1',
            'sample.epoch' => 'required_with:sample|integer|min:1',
            'sample.period_start' => 'required_with:sample|integer|min:1',
            'sample.sequence' => 'required_with:sample|integer|min:1|max:9007199254740991',
            'sample.rx' => 'required_with:sample|integer|min:0|max:9007199254740991',
            'sample.tx' => 'required_with:sample|integer|min:0|max:9007199254740991',
            'sample.initialized' => 'required_with:sample|boolean',
            'sample.error' => 'nullable|in:interfaces_unavailable,state_lost,state_write_failed',
            'sample.interfaces' => 'sometimes|array|max:32',
            'sample.interfaces.*' => ['string', 'distinct', 'regex:/\A[a-zA-Z0-9_.:-]{1,15}\z/'],
        ]);
        return response()->json(['traffic' => \App\Services\MachineTrafficService::exchange(
            $machine->id, $data['sample'] ?? null
        )]);
    }

    public function runtimeBegin(Request $request): JsonResponse
    {
        $data = $request->validate(['run' => ['required', 'string', 'regex:/\A[a-f0-9]{32,64}\z/']]);
        $machine = $this->authenticateMachine($request);
        return response()->json(['data' => app(\App\Services\RealtimeStateStore::class)->begin('machine:' . $machine->id, $data['run'])]);
    }

    public function runtimeState(Request $request): JsonResponse
    {
        $machine = $this->authenticateMachine($request);
        return response()->json(['data' => app(\App\Services\MachineStateService::class)->accept($machine, $request->all())]);
    }

    public function control(Request $request): JsonResponse
    {
        $report = $request->validate([
            'version' => 'required|string|max:64',
            'boot_id' => 'required|string|max:64',
            'manageable' => 'required|boolean',
            'operation' => 'nullable|array',
            'operation.id' => 'required_with:operation|uuid',
            'operation.status' => 'required_with:operation|in:running,succeeded,failed',
            'operation.error' => 'nullable|in:busy,launch_failed,execution_failed,unsupported,timeout,release_query_failed,current_version_failed,current_version_invalid,latest_version_invalid',
            'operation.result' => 'nullable|in:updated,up_to_date,current_newer',
        ]);
        $machine = $this->authenticateMachine($request);
        return response()->json(['command' => \App\Services\MachineAgentService::exchange($machine, $report, $request->ip())]);
    }

    /**
     * agent 分别经 IPv4、IPv6 调用本接口，面板按来源地址族记录公网地址并回显。
     */
    public function address(Request $request): JsonResponse
    {
        $machine = $this->authenticateMachine($request);
        return response()->json(['ip' => \App\Services\MachineAgentService::recordAddress($machine, $request->ip())]);
    }

    /**
     * get nodes list for machine
     */
    public function nodes(Request $request): JsonResponse
    {
        $machine = $this->authenticateMachine($request);

        $nodes = ServerService::getMachineNodes($machine)
            ->map(fn($node) => [
                'id' => $node->id,
                'type' => $node->type,
                'name' => $node->name,
                'kernel_type' => Server::effectiveKernelType($node->kernel_type),
            ])->values();

        return response()->json([
            'nodes' => $nodes,
            'traffic' => \App\Services\MachineTrafficService::view($machine),
            'base_config' => [
                'push_interval' => (int) admin_setting('server_push_interval', 60),
                'pull_interval' => (int) admin_setting('server_pull_interval', 60),
            ],
        ]);
    }

    /**
     * report machine status
     */
    public function status(Request $request): JsonResponse
    {
        $request->validate([
            'cpu' => 'required|numeric|min:0|max:100',
            'mem.total' => 'required|integer|min:0',
            'mem.used' => 'required|integer|min:0',
            'swap.total' => 'nullable|integer|min:0',
            'swap.used' => 'nullable|integer|min:0',
            'disk.total' => 'nullable|integer|min:0',
            'disk.used' => 'nullable|integer|min:0',
            'net.in_speed' => 'nullable|numeric|min:0',
            'net.out_speed' => 'nullable|numeric|min:0',
        ]);

        $machine = $this->authenticateMachine($request);
        $recordedAt = now()->timestamp;

        $loadStatus = [
            'cpu' => (float) $request->input('cpu'),
            'mem' => [
                'total' => (int) $request->input('mem.total'),
                'used' => (int) $request->input('mem.used'),
            ],
            'swap' => [
                'total' => (int) $request->input('swap.total', 0),
                'used' => (int) $request->input('swap.used', 0),
            ],
            'disk' => [
                'total' => (int) $request->input('disk.total', 0),
                'used' => (int) $request->input('disk.used', 0),
            ],
            'updated_at' => $recordedAt,
        ];

        $netInSpeed = $request->input('net.in_speed');
        $netOutSpeed = $request->input('net.out_speed');

        if ($netInSpeed !== null && $netOutSpeed !== null) {
            $loadStatus['net'] = [
                'in_speed' => (float) $netInSpeed,
                'out_speed' => (float) $netOutSpeed,
            ];
        }

        $machine->forceFill([
            'load_status' => $loadStatus,
            'last_seen_at' => $recordedAt,
        ])->save();

        $historyData = [
            'machine_id' => $machine->id,
            'cpu' => (float) $request->input('cpu'),
            'mem_total' => (int) $request->input('mem.total'),
            'mem_used' => (int) $request->input('mem.used'),
            'disk_total' => (int) $request->input('disk.total', 0),
            'disk_used' => (int) $request->input('disk.used', 0),
            'recorded_at' => $recordedAt,
        ];

        if ($netInSpeed !== null && $netOutSpeed !== null) {
            $historyData['net_in_speed'] = (float) $netInSpeed;
            $historyData['net_out_speed'] = (float) $netOutSpeed;
        }

        ServerMachineLoadHistory::create($historyData);

        return response()->json(['data' => true]);
    }

    private function authenticateMachine(Request $request, bool $allowDisabled = false): ServerMachine
    {
        $request->validate([
            'machine_id' => 'required|integer',
            'token' => 'required|string',
        ]);

        $machine = ServerMachine::where('id', $request->input('machine_id'))
            ->where('token', $request->input('token'))
            ->first();

        if (!$machine || (!$machine->is_active && !$allowDisabled)) {
            abort(403, 'Machine not found or disabled');
        }

        $machine->forceFill(['last_seen_at' => now()->timestamp])->saveQuietly();

        return $machine;
    }
}
