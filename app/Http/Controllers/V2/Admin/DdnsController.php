<?php

namespace App\Http\Controllers\V2\Admin;

use App\Exceptions\DdnsSyncException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\V2\Admin\Server\LoadBalancerController;
use App\Models\BalancerDnsPreset;
use App\Models\BalancerPool;
use App\Models\DdnsAlias;
use App\Models\DdnsBinding;
use App\Models\DdnsRecord;
use App\Models\Server;
use App\Services\CloudflareDnsClient;
use App\Services\DdnsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class DdnsController extends Controller
{
    public function fetch()
    {
        $bindings = DdnsBinding::orderBy('id')->get()->map(function ($binding) {
            $owner = $binding->owner_type === 'node' ? Server::find($binding->owner_id) : BalancerPool::find($binding->owner_id);
            return [...$binding->only(['id', 'owner_type', 'owner_id', 'preset_id', 'domain', 'enabled', 'retired', 'cleanup_requested', 'error', 'synced_at']),
                'name' => $owner?->name, 'records' => DdnsRecord::where('binding_id', $binding->id)->whereNotNull('record_id')->count(),
                'aliases' => DdnsAlias::where('binding_id', $binding->id)->count(),
                'planned_records' => app(DdnsService::class)->plannedRecords($binding)];
        });
        $allPresets = BalancerDnsPreset::orderBy('id')->get();
        $presets = $allPresets->map(function ($preset) use ($bindings, $allPresets) {
            $capacity = Cache::get('ddns:capacity:' . $preset->zone_id);
            $ids = $allPresets->where('zone_id', $preset->zone_id)->pluck('id')->all();
            $zoneBindings = $bindings->whereIn('preset_id', $ids);
            return [...$preset->toArray(), 'capacity' => $capacity,
                'expected_usage' => $capacity ? max(0, $capacity['usage'] - $zoneBindings->sum('records')) + $zoneBindings->sum('planned_records') : null];
        });
        return $this->success(['presets' => $presets, 'bindings' => $bindings]);
    }

    public function savePreset(Request $request)
    {
        $response = (new LoadBalancerController())->savePreset($request);
        DdnsService::notify();
        return $response;
    }

    public function deletePreset(Request $request)
    {
        return (new LoadBalancerController())->deletePreset($request);
    }

    public function check(Request $request, CloudflareDnsClient $client)
    {
        $data = $request->validate(['id' => 'required|integer|exists:v2_balancer_dns_preset,id']);
        $preset = BalancerDnsPreset::findOrFail($data['id']);
        try {
            $capacity = [...$client->usage($preset), 'checked_at' => now()->toIso8601String()];
            Cache::put('ddns:capacity:' . $preset->zone_id, $capacity, 3600);
            return $this->success($capacity);
        } catch (DdnsSyncException $error) {
            throw ValidationException::withMessages(['dns' => $error->getMessage()]);
        }
    }

    public function sync(Request $request)
    {
        $data = $request->validate(['id' => 'required|integer|exists:v2_ddns_binding,id']);
        DdnsBinding::findOrFail($data['id'])->update(['fingerprint' => null]);
        DdnsService::notify();
        return $this->success(true);
    }

    public function cleanup(Request $request)
    {
        $data = $request->validate(['id' => 'required|integer|exists:v2_ddns_binding,id']);
        $binding = DdnsBinding::findOrFail($data['id']);
        if ($binding->enabled && !$binding->retired) throw ValidationException::withMessages(['id' => '请先关闭该入口的 DDNS']);
        $binding->update(['cleanup_requested' => true, 'fingerprint' => null]);
        DdnsService::notify();
        return $this->success(true);
    }
}
