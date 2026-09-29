<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Services\RealtimeSnapshotService;
use App\Services\RealtimeTicketService;
use App\WebSocket\NodeWorker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class RealtimeController extends Controller
{
    public function ticket(Request $request, RealtimeTicketService $tickets): JsonResponse
    {
        $enabled = (bool) admin_setting('server_ws_enable', 1) && Cache::has(NodeWorker::HEARTBEAT_CACHE_KEY);
        // 浏览器沿用站点入口，不向浏览器暴露仅供节点使用的独立通信地址。
        $url = ($request->isSecure() ? 'wss://' : 'ws://') . $request->getHttpHost() . '/ws?client=admin';
        return response()->json(['data' => [
            'enabled' => $enabled, 'url' => $url,
            'ticket' => $enabled ? $tickets->issue(Auth::guard('sanctum')->user()) : null,
            'fallback_interval' => 10,
        ]]);
    }

    public function snapshot(Request $request, RealtimeSnapshotService $snapshots): JsonResponse
    {
        $subscription = $snapshots->validate($request->all());
        return response()->json(['data' => $snapshots->snapshot($subscription)]);
    }
}
