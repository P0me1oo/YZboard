<?php

namespace App\Http\Controllers\V2\Admin\Server;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Server;
use App\Models\ServerGroup;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GroupController extends Controller
{
    public function fetch(Request $request): JsonResponse
    {
        $serverGroups = ServerGroup::query()
            ->orderedForDisplay()
            ->withCount('users')
            ->get();

        // 只在需要时手动加载server_count
        $serverGroups->each(function ($group) {
            $group->setAttribute('server_count', $group->server_count);
        });

        return $this->success($serverGroups);
    }

    public function save(Request $request)
    {
        if (empty($request->input('name'))) {
            return $this->fail([422, '组名不能为空']);
        }

        if ($request->input('id')) {
            $serverGroup = ServerGroup::find($request->input('id'));
        } else {
            $serverGroup = new ServerGroup();
        }

        $serverGroup->name = $request->input('name');
        // 新建的组排在末尾；已有的组保持当前排序值。
        if (!$serverGroup->exists) {
            $serverGroup->sort = (int) ServerGroup::query()->max('sort') + 1;
        }
        return $this->success($serverGroup->save());
    }

    public function sort(Request $request)
    {
        ini_set('post_max_size', '1m');
        $params = $request->validate([
            '*.id' => 'numeric',
            '*.order' => 'numeric'
        ]);

        try {
            DB::beginTransaction();
            collect($params)->each(function ($item) {
                if (isset($item['id']) && isset($item['order'])) {
                    ServerGroup::where('id', $item['id'])->update(['sort' => $item['order']]);
                }
            });
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error($e);
            return $this->fail([500, '保存失败']);
        }
        return $this->success(true);
    }

    public function drop(Request $request)
    {
        $groupId = $request->input('id');

        $serverGroup = ServerGroup::find($groupId);
        if (!$serverGroup) {
            return $this->fail([400202, '组不存在']);
        }
        if (Server::whereJsonContains('group_ids', $groupId)->exists()) {
            return $this->fail([400, '该组已被节点所使用，无法删除']);
        }

        if (Plan::where('group_id', $groupId)->exists()) {
            return $this->fail([400, '该组已被订阅所使用，无法删除']);
        }
        if (User::where('group_id', $groupId)->exists()) {
            return $this->fail([400, '该组已被用户所使用，无法删除']);
        }
        return $this->success($serverGroup->delete());
    }
}
