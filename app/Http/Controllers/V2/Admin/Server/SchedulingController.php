<?php

namespace App\Http\Controllers\V2\Admin\Server;

use App\Http\Controllers\Controller;
use App\Models\LandingPool;
use App\Models\SchedulingEvent;
use App\Services\LandingPoolService;
use App\Services\NodeSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SchedulingController extends Controller
{
    public function fetch()
    {
        return $this->success(['pools' => LandingPool::with('node:id,name,relay_entry_id,relay_pool_id')->orderBy('id')->get(),
            'events' => SchedulingEvent::orderByDesc('id')->limit(100)->get()]);
    }

    public function save(Request $request, LandingPoolService $service)
    {
        $data = $request->validate(['id' => 'nullable|integer|exists:v2_landing_pool,id', 'name' => 'required|string|max:100',
            'node_id' => ['required', 'integer', 'exists:v2_server,id', Rule::unique('v2_landing_pool')->ignore($request->input('id'))],
            'enabled' => 'required|boolean', 'members' => 'required|array|min:2|max:16', 'members.*' => 'required|integer|distinct|exists:v2_server,id']);
        return $this->success(['id' => $service->save($data)->id]);
    }

    public function sync(Request $request, LandingPoolService $service)
    {
        $data = $request->validate(['id' => 'required|integer|exists:v2_landing_pool,id']);
        $service->sync(LandingPool::findOrFail($data['id']));
        return $this->success(true);
    }

    public function delete(Request $request)
    {
        $data = $request->validate(['id' => 'required|integer|exists:v2_landing_pool,id']);
        Cache::lock('scheduling:landing:' . $data['id'], 30)->block(2, function () use ($data) {
            DB::transaction(function () use ($data) {
                $pool = LandingPool::with('node')->lockForUpdate()->findOrFail($data['id']);
                $entries = $pool->node ? LandingPoolService::entries($pool->node) : collect();
                $pool->delete();
                DB::afterCommit(function () use ($entries) {
                    foreach ($entries as $entry) { Cache::forget('scheduling:targets:' . $entry->id); NodeSyncService::notifyConfigUpdated($entry->id); }
                });
            });
        });
        return $this->success(true);
    }
}
