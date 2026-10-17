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
            'reality_scan_supported' => 'sometimes|boolean',
            'reality_scan' => 'nullable|array:id,status,error,results',
            'reality_scan.id' => 'required_with:reality_scan|uuid',
            'reality_scan.status' => 'required_with:reality_scan|in:running,succeeded,failed',
            'reality_scan.error' => 'nullable|in:invalid_target,invalid_cidr,too_many_targets,timeout',
            'reality_scan.results' => 'sometimes|array|max:512',
            'reality_scan.results.*' => 'array:target,host,ip,discovered_ip,port,feasible,tls13,tls_version,h2,alpn,x25519,curve,cert_valid,cert_chain_valid,cert_chain_bytes,cert_subject,cert_issuer,not_after,server_names,latency_ms,reason,cdn,http',
            'reality_scan.results.*.target' => 'required|string|max:512',
            'reality_scan.results.*.host' => 'present|nullable|string|max:253',
            'reality_scan.results.*.ip' => 'present|nullable|ip',
            'reality_scan.results.*.discovered_ip' => 'sometimes|ip',
            'reality_scan.results.*.port' => 'required|integer|min:1|max:65535',
            'reality_scan.results.*.feasible' => 'required|boolean',
            'reality_scan.results.*.tls13' => 'required|boolean',
            'reality_scan.results.*.tls_version' => 'present|nullable|string|max:8',
            'reality_scan.results.*.h2' => 'required|boolean',
            'reality_scan.results.*.alpn' => 'present|nullable|string|max:255',
            'reality_scan.results.*.x25519' => 'required|boolean',
            'reality_scan.results.*.curve' => 'present|nullable|string|max:64',
            'reality_scan.results.*.cert_valid' => 'required|boolean',
            'reality_scan.results.*.cert_chain_valid' => 'required|boolean',
            'reality_scan.results.*.cert_chain_bytes' => 'required|integer|min:0|max:16777216',
            'reality_scan.results.*.cert_subject' => 'present|nullable|string|max:1024',
            'reality_scan.results.*.cert_issuer' => 'present|nullable|string|max:1024',
            'reality_scan.results.*.not_after' => 'present|nullable|string|max:64',
            'reality_scan.results.*.server_names' => 'present|array|max:32',
            'reality_scan.results.*.server_names.*' => 'string|max:253',
            'reality_scan.results.*.latency_ms' => 'required|integer|min:0|max:180000',
            'reality_scan.results.*.reason' => 'present|nullable|in:dns_failed,private_target,connection_failed,tls_failed,no_certificate,no_domain,discovered,certificate_invalid,tls13_required,h2_required,x25519_required',
            'reality_scan.results.*.cdn' => 'sometimes|array:status,providers,evidence|required_array_keys:status,providers,evidence',
            'reality_scan.results.*.cdn.status' => 'required_with:reality_scan.results.*.cdn|in:detected,not_detected,unknown',
            'reality_scan.results.*.cdn.providers' => 'sometimes|array|max:16',
            'reality_scan.results.*.cdn.providers.*' => 'string|max:64',
            'reality_scan.results.*.cdn.evidence' => 'sometimes|array|max:32',
            'reality_scan.results.*.cdn.evidence.*' => 'string|max:300',
            'reality_scan.results.*.http' => 'sometimes|array:status,status_code|required_array_keys:status',
            'reality_scan.results.*.http.status' => 'required_with:reality_scan.results.*.http|in:received,timeout,failed,not_checked',
            'reality_scan.results.*.http.status_code' => 'required_if:reality_scan.results.*.http.status,received|prohibited_unless:reality_scan.results.*.http.status,received|integer|min:100|max:599',
        ]);
        $machine = $this->authenticateMachine($request);
        $command = \App\Services\MachineAgentService::exchange($machine, $report, $request->ip());
        $scan = ($report['reality_scan_supported'] ?? false) ? \App\Services\RealityScanService::exchange($machine, $report) : null;
        return response()->json(['command' => $command, 'reality_scan' => $scan]);
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
