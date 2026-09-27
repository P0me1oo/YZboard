<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PlanSave;
use App\Jobs\NodeGroupSyncJob;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PlanController extends Controller
{
    public function fetch(Request $request)
    {
        $plans = Plan::orderBy('sort', 'ASC')
            ->with([
                'group:id,name'
            ])
            ->withCount([
                'users',
                'users as active_users_count' => function ($query) {
                    $query->where(function ($q) {
                        $q->where('expired_at', '>', time())
                          ->orWhereNull('expired_at');
                    });
                }
            ])
            ->get();

        $groups = \App\Models\ServerGroup::query()->get(['id', 'name'])->keyBy('id');
        $plans->each(function (Plan $plan) use ($groups): void {
            $plan->setAttribute('groups', collect($plan->effectiveGroupIds())
                ->map(fn (int $id) => $groups->get($id))
                ->filter()
                ->values());
        });

        return $this->success($plans);
    }

    public function save(PlanSave $request)
    {
        $params = $request->validated();
        $params['group_ids'] = array_values(array_map('intval', $params['group_ids']));
        $params['group_id'] = $params['group_ids'][0] ?? null;
        
        if ($request->input('id')) {
            $plan = Plan::find($request->input('id'));
            if (!$plan) {
                return $this->fail([400202, '该订阅不存在']);
            }

            $syncGroupIds = [];
            DB::beginTransaction();
            try {
                if ($request->input('force_update')) {
                    User::query()->where('plan_id', $plan->id)
                        ->get(['group_id', 'group_ids'])
                        ->each(function (User $user) use (&$syncGroupIds) {
                            $syncGroupIds = array_merge($syncGroupIds, $user->effectiveGroupIds());
                        });
                    User::where('plan_id', $plan->id)->update([
                        'group_id' => $params['group_id'],
                        'group_ids' => json_encode($params['group_ids']),
                        'transfer_enable' => $params['transfer_enable'] * 1073741824,
                        'speed_limit' => $params['speed_limit'] ?? null,
                        'device_limit' => $params['device_limit'] ?? null,
                        'conn_limit' => $params['conn_limit'] ?? null,
                        'conn_rate_limit' => $params['conn_rate_limit'] ?? null,
                    ]);
                }
                $plan->update($params);
                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();
                Log::error($e);
                return $this->fail([500, '保存失败']);
            }

            if ($request->input('force_update')) {
                $syncGroupIds = array_merge($syncGroupIds, $params['group_ids']);
                NodeGroupSyncJob::dispatch(array_values(array_unique(array_filter($syncGroupIds))));
            }
            return $this->success(true);
        }
        if (!Plan::create($params)) {
            return $this->fail([500, '创建失败']);
        }
        return $this->success(true);
    }

    public function drop(Request $request)
    {
        if (Order::where('plan_id', $request->input('id'))->first()) {
            return $this->fail([400201, '该订阅下存在订单无法删除']);
        }
        if (User::where('plan_id', $request->input('id'))->first()) {
            return $this->fail([400201, '该订阅下存在用户无法删除']);
        }
        
        $plan = Plan::find($request->input('id'));
        if (!$plan) {
            return $this->fail([400202, '该订阅不存在']);
        }
        
        return $this->success($plan->delete());
    }

    public function update(Request $request)
    {
        $updateData = $request->only([
            'show',
            'renew',
            'sell'
        ]);

        $plan = Plan::find($request->input('id'));
        if (!$plan) {
            return $this->fail([400202, '该订阅不存在']);
        }

        try {
            $plan->update($updateData);
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, '保存失败']);
        }

        return $this->success(true);
    }

    public function sort(Request $request)
    {
        $params = $request->validate([
            'ids' => 'required|array'
        ]);

        try {
            DB::beginTransaction();
            foreach ($params['ids'] as $k => $v) {
                if (!Plan::find($v)->update(['sort' => $k + 1])) {
                    throw new \Exception();
                }
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error($e);
            return $this->fail([500, '保存失败']);
        }
        return $this->success(true);
    }
}
