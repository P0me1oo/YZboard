<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class PreserveRealtimeState
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('POST') && $request->isJson() && $request->is(
            'api/v2/server/realtime/state',
            'api/v2/server/machine/realtime/state',
        )) {
            // 在通用字符串清理之后恢复协议快照，使 HTTP 重试与 WebSocket 内容一致。
            // 仅恢复 state；鉴权字段仍沿用原处理，快照仍由状态服务校验。
            $payload = json_decode($request->getContent(), true);
            if (is_array($payload['state'] ?? null)) {
                $request->json()->set('state', $payload['state']);
            }
        }

        return $next($request);
    }
}
