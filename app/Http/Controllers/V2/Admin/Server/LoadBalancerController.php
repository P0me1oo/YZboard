<?php

namespace App\Http\Controllers\V2\Admin\Server;

use App\Http\Controllers\Controller;
use App\Models\BalancerDnsPreset;
use App\Models\BalancerDnsRecord;
use App\Models\BalancerPool;
use App\Models\Server;
use App\Services\BalancerDnsService;
use App\Services\LoadBalancerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;

class LoadBalancerController extends Controller
{
    public function fetch()
    {
        $pools = BalancerPool::with(['members.sourceNode', 'members.runtimeNode'])->orderBy('id')->get()->map(function ($pool) {
            $view = $pool->attributesToArray();
            $view['ddns'] = \App\Services\DdnsService::view('pool', $pool->id);
            $view['effective_rate'] = $pool->members->first()?->runtimeNode?->getCurrentRate();
            $view['members'] = $pool->members->map(fn ($member) => [
                'node_id' => $member->source_node_id, 'name' => $member->sourceNode?->name, 'priority' => $member->priority,
                'runtime_node_id' => $member->runtime_node_id, 'online' => $member->runtimeNode && LoadBalancerService::online($member->runtimeNode),
                'last_report_at' => $member->runtimeNode ? LoadBalancerService::heartbeat($member->runtimeNode) : null,
            ])->values();
            return $view;
        });
        return $this->success(['pools' => $pools, 'presets' => BalancerDnsPreset::orderBy('id')->get(),
            'offline_threshold' => LoadBalancerService::offlineThreshold()]);
    }

    public function savePreset(Request $request)
    {
        $data = $request->validate(['id' => 'nullable|integer|exists:v2_balancer_dns_preset,id', 'name' => 'required|string|max:100',
            'domain' => ['required', 'string', 'max:253', 'regex:/\A(?=.{1,253}\z)(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,63}\z/'],
            'zone_id' => ['required', 'string', 'regex:/\A[a-fA-F0-9]{32}\z/'], 'api_token' => 'nullable|string|min:10|max:512']);
        return DB::transaction(function () use ($data) {
        $preset = isset($data['id']) ? BalancerDnsPreset::query()->lockForUpdate()->findOrFail($data['id']) : new BalancerDnsPreset();
        $data['zone_id'] = strtolower($data['zone_id']);
        if (!$preset->exists && empty($data['api_token'])) throw ValidationException::withMessages(['api_token' => '请填写 API Token']);
        if ($preset->exists && ($preset->zone_id !== $data['zone_id'] || $preset->domain !== strtolower($data['domain']))
            && (BalancerPool::where('dns_preset_id', $preset->id)->exists()
                || (\App\Services\DdnsService::installed() && \App\Models\DdnsBinding::where('preset_id', $preset->id)->exists()))) {
            throw ValidationException::withMessages(['zone_id' => '配置正在被前置池使用，请另建域名配置']);
        }
        if (empty($data['api_token'])) unset($data['api_token']);
        unset($data['id']);
        $data['domain'] = strtolower($data['domain']);
        $preset->fill($data)->save();
        return $this->success($preset);
        });
    }

    public function deletePreset(Request $request)
    {
        $data = $request->validate(['id' => 'required|integer|exists:v2_balancer_dns_preset,id']);
        DB::transaction(function () use ($data) {
            $preset = BalancerDnsPreset::query()->lockForUpdate()->findOrFail($data['id']);
            if (BalancerPool::where('dns_preset_id', $data['id'])->exists()) throw ValidationException::withMessages(['id' => '请先解除前置池对该配置的引用']);
            if (\App\Services\DdnsService::installed() && \App\Models\DdnsBinding::where('preset_id', $data['id'])->exists()) {
                throw ValidationException::withMessages(['id' => '请先清理引用该配置的节点 DDNS 及旧解析']);
            }
            $preset->delete();
        });
        return $this->success(true);
    }

    public function save(Request $request, LoadBalancerService $service)
    {
        $request->merge(['domain' => strtolower(trim((string) $request->input('domain')))]);
        $id = (int) $request->input('id', 0);
        $data = $request->validate(['id' => 'nullable|integer|exists:v2_balancer_pool,id', 'name' => 'required|string|max:100',
            'domain' => ['required', 'string', 'max:253', 'regex:/\A(?=.{1,253}\z)(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,63}\z/', Rule::unique('v2_balancer_pool')->ignore($id)],
            'dns_preset_id' => 'nullable|integer|exists:v2_balancer_dns_preset,id',
            'gateway_machine_id' => 'required|integer|exists:v2_server_machine,id', 'gateway_address' => 'sometimes|ip',
            'gateway_port' => 'sometimes|integer|min:1|max:65535', 'enabled' => 'required|boolean',
            'ddns' => 'sometimes|nullable|array:enabled,preset_id,prefix',
            'ddns.enabled' => 'required_with:ddns|boolean', 'ddns.preset_id' => 'nullable|integer', 'ddns.prefix' => 'nullable|string|max:63',
            'gateway_source_ips' => 'nullable|array|max:16', 'gateway_source_ips.*' => 'required|ip|distinct',
            'members' => 'required|array|min:1|max:64', 'members.*.node_id' => 'required|integer|distinct|exists:v2_server,id',
            'members.*.priority' => 'required|integer|min:1|max:65535']);
        if (!empty($data['dns_preset_id'])) {
            $preset = BalancerDnsPreset::findOrFail($data['dns_preset_id']);
            if (!str_ends_with(strtolower($data['domain']), '.' . $preset->domain)) {
                throw ValidationException::withMessages(['domain' => '入口域名必须是所选配置域名下的子域名']);
            }
        }
        if (($data['ddns']['enabled'] ?? false) && empty($data['gateway_address'])) {
            throw ValidationException::withMessages(['gateway_address' => '开启 DDNS 时请手工填写统一入口的连接 IP']);
        }
        $pool = $service->save($data);
        return $this->success(['id' => $pool->id]);
    }

    public function settings(Request $request)
    {
        $data = $request->validate(['offline_threshold' => 'required|integer|min:30|max:86400']);
        admin_setting(['load_balance_offline_threshold' => $data['offline_threshold']]);
        foreach (BalancerPool::with('gateway')->get() as $pool) if ($pool->gateway) \App\Services\NodeSyncService::notifyConfigUpdated($pool->gateway->id);
        return $this->success(true);
    }

    public function syncDns(Request $request, BalancerDnsService $dns)
    {
        $data = $request->validate(['id' => 'required|integer|exists:v2_balancer_pool,id']);
        $pool = BalancerPool::findOrFail($data['id']);
        $ok = $dns->sync($pool);
        if (!$ok) throw ValidationException::withMessages(['dns' => $pool->fresh()->dns_error]);
        return $this->success(true);
    }

    public function delete(Request $request)
    {
        $data = $request->validate(['id' => 'required|integer|exists:v2_balancer_pool,id']);
        \Illuminate\Support\Facades\Cache::lock('balancer:dns:' . $data['id'], 120)->block(2, function () use ($data) {
        DB::transaction(function () use ($data) {
            $pool = BalancerPool::query()->lockForUpdate()->findOrFail($data['id']);
            if (Server::where('relay_pool_id', $pool->id)->exists()) throw ValidationException::withMessages(['id' => '请先解除落地节点对该池的引用']);
            if (BalancerDnsRecord::where('pool_id', $pool->id)->exists()) throw ValidationException::withMessages(['id' => '请先停用前置池并同步清理 DNS 记录']);
            foreach (Server::where('balancer_pool_id', $pool->id)->get() as $node) $node->delete();
            $pool->members()->delete();
            $pool->delete();
        });
        });
        return $this->success(true);
    }
}
