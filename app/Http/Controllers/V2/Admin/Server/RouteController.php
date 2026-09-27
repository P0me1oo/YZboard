<?php

namespace App\Http\Controllers\V2\Admin\Server;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\ServerRoute;
use App\Services\ServerRouteService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class RouteController extends Controller
{
    public function fetch(Request $request)
    {
        $nodeIds = ServerRouteService::nodeIdsByRoute();
        $routes = ServerRoute::get()->map(fn (ServerRoute $route) => [
            ...$route->toArray(),
            'node_ids' => $nodeIds[$route->id] ?? [],
        ]);

        return [
            'data' => $routes
        ];
    }

    public function save(Request $request)
    {
        $params = $request->validate([
            'remarks' => 'required|string|max:255',
            'match' => 'nullable|array',
            'match.*' => 'nullable|string',
            'protocol' => 'nullable|array',
            'protocol.*' => 'string|in:' . implode(',', ServerRoute::PROTOCOLS),
            'port' => 'nullable|string|max:255',
            'network' => 'nullable|string|in:' . implode(',', ServerRoute::NETWORKS),
            'action' => 'required|in:block,direct,dns,proxy',
            'action_value' => 'nullable',
            'apply_to_new_nodes' => 'nullable|boolean',
            'node_ids' => 'nullable|array',
            'node_ids.*' => 'integer|distinct',
        ], [
            'remarks.required' => '备注不能为空',
            'protocol.*.in' => '协议只支持 BitTorrent',
            'network.in' => '网络类型只能是 TCP 或 UDP',
            'action.required' => '动作类型不能为空',
            'action.in' => '动作类型参数有误',
            'node_ids.*.integer' => '节点参数有误',
        ]);

        $params['match'] = ServerRoute::normalizeMatch($params['match'] ?? []);
        $params['protocol'] = ServerRoute::normalizeProtocol($params['protocol'] ?? []) ?: null;
        [$params['port'], $portError] = ServerRouteService::normalizePort($params['port'] ?? null);
        if ($portError !== null) {
            throw ValidationException::withMessages(['port' => $portError]);
        }
        $params['network'] = ($params['network'] ?? null) ?: null;

        $hasConditions = $params['protocol'] !== null || $params['port'] !== null || $params['network'] !== null;
        if ($params['match'] === [] && !$hasConditions) {
            throw ValidationException::withMessages(['match' => '请至少填写一个匹配条件']);
        }
        if ($params['action'] === ServerRoute::ACTION_DNS && $hasConditions) {
            throw ValidationException::withMessages(['action' => 'DNS 动作只能按域名匹配，不能使用协议、端口或网络条件']);
        }

        // 未提交节点列表时不改动绑定关系，兼容只修改路由内容的旧调用。
        $nodeIds = array_key_exists('node_ids', $params) && $params['node_ids'] !== null
            ? array_values(array_unique(array_map('intval', $params['node_ids'])))
            : null;
        unset($params['node_ids']);
        if (!array_key_exists('apply_to_new_nodes', $params) || $params['apply_to_new_nodes'] === null) {
            if ($request->input('id')) {
                unset($params['apply_to_new_nodes']);
            } else {
                $params['apply_to_new_nodes'] = true;
            }
        }

        try {
            return DB::transaction(function () use ($request, $params, $nodeIds) {
                if ($request->input('id')) {
                    $route = ServerRoute::whereKey($request->input('id'))->lockForUpdate()->first();
                    if (!$route) {
                        throw new ApiException('路由不存在');
                    }
                    $route->update($params);
                } else {
                    $route = ServerRoute::create($params);
                }

                if ($nodeIds !== null) {
                    if (Server::whereIn('id', $nodeIds)->count() !== count($nodeIds)) {
                        throw ValidationException::withMessages(['node_ids' => '所选节点不存在或已被删除，请刷新后重试']);
                    }
                    ServerRouteService::syncNodes((int) $route->id, $nodeIds);
                }

                return $this->success(true);
            }, 3);
        } catch (ValidationException|ApiException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, $request->input('id') ? '保存失败' : '创建失败']);
        }
    }

    public function drop(Request $request)
    {
        return DB::transaction(function () use ($request) {
            $route = ServerRoute::whereKey($request->input('id'))->lockForUpdate()->first();
            if (!$route) throw new ApiException('路由不存在');
            // 先从节点上移除编号，避免残留编号在日后被复用时误绑定到新路由。
            ServerRouteService::syncNodes((int) $route->id, []);
            if (!$route->delete()) throw new ApiException('删除失败');
            return [
                'data' => true
            ];
        });
    }
}
