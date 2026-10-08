<?php

namespace App\Http\Controllers\V2\Server;

use App\Http\Controllers\Controller;
use App\Services\NodeReportService;
use App\Services\ServerService;
use App\WebSocket\NodeWorker;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class ServerController extends Controller
{
    /**
     * server handshake api
     */
    public function handshake(Request $request): JsonResponse
    {
        $node = $request->attributes->get('node_info');
        if ($node !== null && (int) $request->input('device_handover', 0) !== 1) {
            app(\App\Services\DeviceHandoverService::class)->legacyNode((int) $node->id);
        }
        $websocket = ['enabled' => false];

        if ((bool) admin_setting('server_ws_enable', 1) && Cache::has(NodeWorker::HEARTBEAT_CACHE_KEY)) {
            $customUrl = trim((string) admin_setting('server_ws_url', ''));

            if ($customUrl !== '') {
                $wsUrl = rtrim($customUrl, '/');
            } else {
                $wsScheme = $request->isSecure() ? 'wss' : 'ws';
                $wsUrl = "{$wsScheme}://{$request->getHttpHost()}/ws";
            }

            $websocket = [
                'enabled' => true,
                'ws_url' => $wsUrl,
            ];
        }

        return response()->json([
            'websocket' => $websocket,
            'settings' => [
                'push_interval' => (int) admin_setting('server_push_interval', 60),
                'pull_interval' => (int) admin_setting('server_pull_interval', 60),
            ],
            'realtime' => [
                'version' => 1,
                'state_interval' => 1,
                'fallback_interval' => 10,
                'traffic_ack' => true,
                'device_handover' => 1,
            ],
        ]);
    }

    /**
     * node report api - merge traffic + alive + status + metrics
     */
    public function report(Request $request): JsonResponse
    {
        $node = $request->attributes->get('node_info');

        if ($request->boolean('realtime')) {
            $receipt = app(NodeReportService::class)->acceptRealtime($node, $request->all());
            return response()->json(['data' => true, 'receipt' => $receipt]);
        }

        ServerService::touchNode($node);
        ServerService::touchPush($node);

        app(NodeReportService::class)->accept(
            $node,
            $request->input('report_id'),
            is_array($request->input('traffic')) ? $request->input('traffic') : [],
            is_array($request->input('relay_traffic')) ? $request->input('relay_traffic') : [],
            is_array($request->input('relay_user_traffic')) ? $request->input('relay_user_traffic') : []
        );

        // 先交出按实际节点拆分的在线来源，插件处理整入口设备快照时已能识别该入口。
        $relayUserAlive = $request->input('relay_user_alive');
        if (is_array($relayUserAlive) && !empty($relayUserAlive)) {
            ServerService::processRelayUserAlive($node, $relayUserAlive);
        }

        $relayCounts = $request->input('relay_connection_counts');
        $hasRelayCounts = is_array($relayCounts)
            && ServerService::processRelayConnectionCounts($node, $relayCounts);
        $counts = $request->input('connection_counts');
        if (is_array($counts)) {
            app(\App\Services\UserConnectionService::class)->replace($node, $counts);
        }
        if (!$hasRelayCounts && is_array($counts)) {
            ServerService::processConnectionCounts($node, $counts);
        }

        $alive = $request->input('alive');
        if (is_array($alive)) {
            ServerService::processAlive($node->id, $alive);
        }

        $online = $request->input('online');
        if (is_array($online)) {
            ServerService::processOnline($node, $online);
        }

        $status = $request->input('status');
        if (is_array($status) && !empty($status)) {
            ServerService::processStatus($node, $status);
        }

        $metrics = $request->input('metrics');
        if (is_array($metrics) && !empty($metrics)) {
            ServerService::updateMetrics($node, $metrics);
        }

        $limitEvents = $request->input('limit_events');
        if (is_array($limitEvents) && !empty($limitEvents)) {
            ServerService::processLimitEvents($node, $limitEvents);
        }

        return response()->json(['data' => true]);
    }
}
