<?php

namespace App\Http\Controllers\V2\Admin\Server;

use App\Http\Controllers\Controller;
use App\Models\BalancerDnsPreset;
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
        $counts = \App\Models\PoolDnsAlias::whereNotNull('member_id')->selectRaw('member_id, count(*) as total')->groupBy('member_id')->pluck('total', 'member_id');
        $pools = BalancerPool::with(['members.sourceNode', 'members.runtimeNode.machine'])->orderBy('id')->get()->map(function ($pool) use ($counts) {
            $view = $pool->attributesToArray();
            $view['effective_rate'] = $pool->members->first()?->runtimeNode?->getCurrentRate();
            $view['members'] = $pool->members->map(fn ($member) => [
                'node_id' => $member->source_node_id, 'name' => $member->sourceNode?->name, 'priority' => $member->priority, 'weight' => $member->weight, 'assigned_users' => (int) ($counts[$member->id] ?? 0),
                'runtime_node_id' => $member->runtime_node_id, 'online' => LoadBalancerService::memberOnline($pool, $member),
                'compatibility_reasons' => $member->runtimeNode ? LoadBalancerService::memberCompatibility($pool, $member->runtimeNode) : [],
                'addresses' => $member->dns_addresses, 'healthy_addresses' => $member->healthy_addresses, 'checked_at' => $member->checked_at,
                'scheduling_state' => $member->scheduling_state,
                'last_report_at' => $member->runtimeNode ? LoadBalancerService::heartbeat($member->runtimeNode) : null,
            ])->values();
            return $view;
        });
        return $this->success(['pools' => $pools, 'presets' => BalancerDnsPreset::orderBy('id')->get()]);
    }

    public function savePreset(Request $request)
    {
        $data = $request->validate(['id' => 'nullable|integer|exists:v2_balancer_dns_preset,id', 'name' => 'required|string|max:100',
            'domain' => ['required', 'string', 'max:253', 'regex:/\A(?=.{1,253}\z)(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,63}\z/'],
            'provider' => 'sometimes|in:cloudflare,powerdns', 'api_url' => 'nullable|url:http,https|max:253', 'server_id' => 'nullable|string|max:100|regex:/\A[a-zA-Z0-9_-]+\z/',
            'zone_id' => ['nullable', 'string', 'regex:/\A[a-fA-F0-9]{32}\z/'], 'api_token' => 'nullable|string|min:10|max:512']);
        return DB::transaction(function () use ($data) {
        $preset = isset($data['id']) ? BalancerDnsPreset::query()->lockForUpdate()->findOrFail($data['id']) : new BalancerDnsPreset();
        $data['provider'] ??= $preset->provider ?? 'cloudflare';
        if ($data['provider'] === 'powerdns') {
            $data['api_url'] = rtrim($data['api_url'] ?? $preset->api_url ?? '', '/');
            $url = parse_url($data['api_url']);
            if (!$url || !in_array($url['scheme'] ?? '', ['http', 'https'], true) || empty($url['host'])
                || isset($url['user'], $url['pass']) || isset($url['user']) || isset($url['query']) || isset($url['fragment']) || !empty($url['path'])) {
                throw ValidationException::withMessages(['api_url' => '填写 PowerDNS API 基础地址，不含路径、认证信息或查询参数']);
            }
            $data['server_id'] = $data['server_id'] ?? $preset->server_id ?? 'localhost';
            $other = BalancerDnsPreset::where('provider', 'powerdns')->where('id', '!=', $preset->id ?? 0)->first();
            if ($other && ($other->api_url !== $data['api_url'] || $other->server_id !== $data['server_id'])) {
                throw ValidationException::withMessages(['api_url' => '当前使用单台 PowerDNS，请与已有配置的 API 地址和服务器 ID 保持一致']);
            }
            $data['zone_id'] = md5(strtolower($data['domain']));
        } else {
            if (empty($data['zone_id'])) throw ValidationException::withMessages(['zone_id' => '请填写 Cloudflare Zone ID']);
            $data['zone_id'] = strtolower($data['zone_id']);
            $data['api_url'] = null;
        }
        if (!$preset->exists && empty($data['api_token'])) throw ValidationException::withMessages(['api_token' => '请填写 API Token']);
        if ($preset->exists && empty($data['api_token']) && ($preset->provider !== $data['provider']
            || ($data['provider'] === 'powerdns' && $preset->api_url !== $data['api_url']))) {
            throw ValidationException::withMessages(['api_token' => '切换 DNS 服务或 API 地址时请重新填写密钥']);
        }
        if ($preset->exists && ($preset->zone_id !== $data['zone_id'] || $preset->domain !== strtolower($data['domain']) || $preset->provider !== $data['provider']
                || ($data['provider'] === 'powerdns' && ($preset->api_url !== $data['api_url'] || $preset->server_id !== $data['server_id'])))
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
        $mode = $request->input('mode', 'dns');
        $request->merge(['mode' => $mode]);
        $data = $request->validate(['id' => 'nullable|integer|exists:v2_balancer_pool,id', 'name' => 'required|string|max:100',
            'domain' => ['required', 'string', 'max:253', 'regex:/\A(?=.{1,253}\z)(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,63}\z/', Rule::unique('v2_balancer_pool')->ignore($id)],
            'dns_preset_id' => 'required|integer|exists:v2_balancer_dns_preset,id',
            'mode' => 'required|in:dns', 'dns_ttl' => 'sometimes|integer|min:30|max:86400',
            'ddns_enabled' => 'sometimes|boolean', 'personal_domain_enabled' => 'sometimes|boolean', 'follow_user_status' => 'sometimes|boolean',
            'scheduling_config' => 'sometimes|nullable|array:health_enabled,strategy,latency_node_id,quota_enabled,quota_percent',
            'scheduling_config.health_enabled' => 'sometimes|boolean', 'scheduling_config.strategy' => 'sometimes|in:priority,latency,latency_balanced,cycle',
            'scheduling_config.latency_node_id' => 'nullable|integer|exists:v2_server,id',
            'scheduling_config.quota_enabled' => 'sometimes|boolean', 'scheduling_config.quota_percent' => 'sometimes|integer|min:1|max:50',
            'gateway_machine_id' => 'prohibited', 'gateway_address' => 'prohibited', 'gateway_port' => 'prohibited',
            'gateway_source_ips' => 'prohibited', 'ddns' => 'prohibited', 'enabled' => 'required|boolean',
            'members' => 'required|array|min:1|max:64', 'members.*.node_id' => 'required|integer|distinct|exists:v2_server,id',
            'members.*.priority' => 'required|integer|min:1|max:65535', 'members.*.weight' => 'sometimes|integer|min:1|max:1000']);
        if (!empty($data['dns_preset_id'])) {
            $preset = BalancerDnsPreset::findOrFail($data['dns_preset_id']);
            if (!(($mode === 'dns' && $data['domain'] === $preset->domain) || str_ends_with(strtolower($data['domain']), '.' . $preset->domain))) {
                throw ValidationException::withMessages(['domain' => '入口域名必须是所选配置域名下的子域名']);
            }
        }
        $pool = $service->save($data);
        return $this->success(['id' => $pool->id]);
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
            if (\App\Models\PowerDnsRecord::where('owner_type', 'pool')->where('owner_id', $pool->id)->exists()) throw ValidationException::withMessages(['id' => '请先停用前置池并同步清理 DNS 记录']);
            $originalIds = $pool->members()->whereColumn('runtime_node_id', 'source_node_id')->pluck('source_node_id')->all();
            $pool->members()->delete();
            \App\Models\PoolDnsAlias::where('pool_id', $pool->id)->delete();
            $pool->delete();
            DB::afterCommit(function () use ($originalIds) {
                foreach ($originalIds as $id) \App\Services\NodeSyncService::notifyFullSync((int) $id);
            });
        });
        });
        return $this->success(true);
    }
}
