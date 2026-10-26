<?php

namespace App\Http\Controllers\V2\Admin\Server;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\ServerMachineLoadHistory;
use App\Services\NodeSyncService;
use App\Services\MachineTrafficService;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

class MachineController extends Controller
{
    public function __construct(private readonly ?\App\Services\StatisticsQueryLimiter $queryLimiter = null) {}

    public function operate(Request $request)
    {
        $params = $request->validate([
            'ids' => 'required|array|min:1|max:200',
            'ids.*' => 'required|integer|min:1|distinct',
            'action' => 'required|in:upgrade,restart',
        ]);
        return $this->success(array_map(
            fn ($id) => \App\Services\MachineAgentService::queue((int) $id, $params['action']),
            $params['ids']
        ));
    }

    private const NODE_INSTALLER_URL = 'https://github.com/P0me1oo/YZ-Agent/releases/latest/download/install.sh';

    /**
     * 获取机器列表（附带关联节点数）
     */
    public function fetch(Request $request)
    {
        $machines = ServerMachine::withCount('servers')
            ->orderBy('id')
            ->get()
            ->map(function (ServerMachine $machine) {
                return [
                    'id' => $machine->id,
                    'name' => $machine->name,
                    'notes' => $machine->notes,
                    'is_active' => $machine->is_active,
                    'agent_log_disabled' => $machine->agent_log_disabled,
                    'last_seen_at' => $machine->last_seen_at,
                    'load_status' => $machine->load_status,
                    'agent_runtime' => \App\Services\MachineAgentService::runtimeView($machine->agent_runtime),
                    'agent_operation' => $this->operationState($machine),
                    'servers_count' => $machine->servers_count,
                    'proxy_u' => (int) $machine->proxy_u,
                    'proxy_d' => (int) $machine->proxy_d,
                    'traffic' => MachineTrafficService::view($machine),
                    'created_at' => $machine->created_at,
                    'updated_at' => $machine->updated_at,
                ];
            });

        return $this->success($machines);
    }

    /**
     * 创建 / 更新机器
     */
    public function save(Request $request)
    {
        $params = $request->validate([
            'id' => 'nullable|integer|exists:v2_server_machine,id',
            'name' => 'required|string|max:255',
            'notes' => 'nullable|string',
            'is_active' => 'nullable|boolean',
            'agent_log_disabled' => 'sometimes|required|boolean',
            'traffic_limit_gb' => 'sometimes|required|numeric|min:0|max:8388607',
            'traffic_mode' => 'sometimes|required|in:outbound,both,proxy',
            'traffic_reset_day' => 'sometimes|required|integer|min:1|max:31',
            'traffic_interfaces' => 'sometimes|array|max:32',
            'traffic_interfaces.*' => ['string', 'distinct', 'regex:/\A[a-zA-Z0-9_.:-]{1,15}\z/'],
        ]);

        if (!empty($params['id'])) {
            DB::transaction(function () use ($params) {
                $machine = ServerMachine::whereKey($params['id'])->lockForUpdate()->firstOrFail();
                if (($params['traffic_mode'] ?? $machine->traffic_mode) === 'proxy'
                    && ($params['traffic_limit_gb'] ?? ($machine->traffic_limit / 1073741824)) > 0
                    && \App\Models\BalancerPool::where('gateway_machine_id', $machine->id)->exists()) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['traffic_mode' => '调度入口设置流量限额时请使用网卡计量模式']);
                }
                MachineTrafficService::configure($machine, $params);
                $machine->fill(array_intersect_key($params, array_flip(['name', 'notes', 'is_active', 'agent_log_disabled'])))->save();
                DB::afterCommit(fn () => MachineTrafficService::notify($machine->id));
            }, 3);
            return $this->success(true);
        }

        $machine = new ServerMachine([
            'name' => $params['name'],
            'notes' => $params['notes'] ?? null,
            'is_active' => $params['is_active'] ?? true,
            'agent_log_disabled' => $params['agent_log_disabled'] ?? false,
            'traffic_mode' => $params['traffic_mode'] ?? 'both',
            'token' => ServerMachine::generateToken(),
        ]);
        MachineTrafficService::configure($machine, $params);
        $machine->save();

        return $this->success([
            'id' => $machine->id,
            'token' => $machine->token,
            'install_command' => $this->buildInstallCommand($request, $machine),
        ]);
    }

    /**
     * 重置机器 Token
     */
    public function resetToken(Request $request)
    {
        $params = $request->validate([
            'id' => 'required|integer|exists:v2_server_machine,id',
        ]);

        $machine = ServerMachine::find($params['id']);
        $token = ServerMachine::generateToken();
        $machine->update(['token' => $token]);

        return $this->success(['token' => $token]);
    }

    /**
     * 获取机器 Token（仅展示一次，用于首次配置）
     */
    public function getToken(Request $request)
    {
        $params = $request->validate([
            'id' => 'required|integer|exists:v2_server_machine,id',
        ]);

        $machine = ServerMachine::find($params['id']);

        return $this->success(['token' => $machine->token]);
    }

    /**
     * 获取机器模式一键安装命令
     */
    public function installCommand(Request $request)
    {
        $params = $request->validate([
            'id' => 'required|integer|exists:v2_server_machine,id',
        ]);

        $machine = ServerMachine::find($params['id']);

        return $this->success([
            'command' => $this->buildInstallCommand($request, $machine),
        ]);
    }

    /**
     * 删除机器（自动解除关联节点）
     */
    public function drop(Request $request)
    {
        $params = $request->validate([
            'id' => 'required|integer|exists:v2_server_machine,id',
        ]);

        $machine = ServerMachine::find($params['id']);
        $machineId = $machine->id;

        // Detach nodes first (sets machine_id = null), then delete and notify
        Server::where('machine_id', $machineId)->update(['machine_id' => null]);
        \App\Services\NodeRuntimeMetadata::invalidate(Server::class);
        $machine->delete();

        // Notify with empty node list so WS process cleans up registry
        NodeSyncService::notifyMachineNodesChanged($machineId);

        return $this->success(true);
    }

    /**
     * 获取机器下的节点列表
     */
    public function nodes(Request $request)
    {
        $params = $request->validate([
            'machine_id' => 'required|integer|exists:v2_server_machine,id',
        ]);

        $nodes = Server::where('machine_id', $params['machine_id'])
            ->orderBy('sort')
            ->get(['id', 'name', 'type', 'host', 'port', 'show', 'enabled', 'sort']);

        return $this->success($nodes);
    }

    /**
     * 获取机器负载历史
     */
    public function history(Request $request)
    {
        $params = $request->validate([
            'machine_id' => 'required|integer|exists:v2_server_machine,id',
            'limit' => 'nullable|integer|min:10|max:1440',
            'range_hours' => 'nullable|integer|min:1|max:24',
        ]);
        return ($this->queryLimiter ?? app(\App\Services\StatisticsQueryLimiter::class))->run(function () use ($params) {
            $query = ServerMachineLoadHistory::query()
                ->where('machine_id', $params['machine_id'])
                // 即使定时清理暂未执行，也不展示 24 小时以前的历史。
                ->where('recorded_at', '>=', now()->subHours(24)->timestamp);

            if (!empty($params['range_hours'])) {
                $query->where('recorded_at', '>=', now()->subHours((int) $params['range_hours'])->timestamp);
            }

            $limit = min(500, (int) ($params['limit'] ?? 60));
            $columns = ['cpu', 'mem_total', 'mem_used', 'disk_total', 'disk_used', 'net_in_speed', 'net_out_speed', 'recorded_at'];
            if ((clone $query)->count() <= $limit) {
                return $this->success($query->orderBy('recorded_at')->get($columns)->all());
            }
            $bounds = (clone $query)->selectRaw('MIN(recorded_at) AS first_at, MAX(recorded_at) AS last_at')->first();
            $width = max(1, (int) ceil(((int) $bounds->last_at - (int) $bounds->first_at + 1) / $limit));
            $groups = [];
            foreach ($query->orderBy('recorded_at')->cursor($columns) as $row) {
                $bucket = intdiv(max(0, (int) $row->recorded_at - (int) $bounds->first_at), $width);
                $groups[$bucket] ??= $this->newHistoryBucket();
                $this->addHistorySample($groups[$bucket], $row);
            }
            return $this->success(array_values(array_map([$this, 'finishHistoryBucket'], $groups)));
        });
    }

    private function newHistoryBucket(): array
    {
        return ['count' => 0, 'recorded_at' => 0, 'mem_total' => 0, 'disk_total' => 0,
            'cpu_sum' => 0.0, 'mem_used_sum' => 0.0, 'disk_used_sum' => 0.0,
            'net_in_speed_sum' => 0.0, 'net_out_speed_sum' => 0.0,
            'cpu_peak' => 0.0, 'mem_used_peak' => 0.0, 'disk_used_peak' => 0.0,
            'net_in_speed_peak' => 0.0, 'net_out_speed_peak' => 0.0];
    }

    private function addHistorySample(array &$bucket, object $row): void
    {
        $bucket['count']++;
        $bucket['recorded_at'] = (int) $row->recorded_at;
        $bucket['mem_total'] = max($bucket['mem_total'], (int) $row->mem_total);
        $bucket['disk_total'] = max($bucket['disk_total'], (int) $row->disk_total);
        foreach (['cpu', 'mem_used', 'disk_used', 'net_in_speed', 'net_out_speed'] as $field) {
            $value = (float) $row->$field;
            $bucket[$field . '_sum'] += $value;
            $bucket[$field . '_peak'] = max($bucket[$field . '_peak'], $value);
        }
    }

    private function finishHistoryBucket(array $bucket): array
    {
        $count = max(1, $bucket['count']);
        $result = ['cpu' => $bucket['cpu_sum'] / $count, 'mem_total' => $bucket['mem_total'],
            'mem_used' => $bucket['mem_used_sum'] / $count, 'disk_total' => $bucket['disk_total'],
            'disk_used' => $bucket['disk_used_sum'] / $count,
            'net_in_speed' => $bucket['net_in_speed_sum'] / $count,
            'net_out_speed' => $bucket['net_out_speed_sum'] / $count,
            'recorded_at' => $bucket['recorded_at']];
        foreach (['cpu', 'mem_used', 'disk_used', 'net_in_speed', 'net_out_speed'] as $field) {
            $result[$field . '_avg'] = $result[$field];
            $result[$field . '_peak'] = $bucket[$field . '_peak'];
        }
        return $result;
    }

    private function operationState(ServerMachine $machine): ?array
    {
        // 列表只投影超时状态，避免与正在上报的进程争用写入。
        $operation = $machine->agent_operation;
        if ($operation && in_array($operation['status'], ['pending', 'running'], true) && $operation['expires_at'] <= time()) {
            $operation['status'] = 'timeout';
            $operation['error'] = 'timeout';
        }
        return $operation;
    }

    private function buildInstallCommand(Request $request, ServerMachine $machine): string
    {
        $panelUrl = rtrim((string) (admin_setting('app_url') ?: $request->getSchemeAndHttpHost()), '/');

        return sprintf(
            'curl -fsSL %s | sudo bash -s -- --mode machine --panel %s --token %s --machine-id %d --version latest',
            self::NODE_INSTALLER_URL,
            escapeshellarg($panelUrl),
            escapeshellarg($machine->token),
            $machine->id
        );
    }
}
