<?php

namespace App\Http\Controllers\V2\Server;

use App\Http\Controllers\Controller;
use App\Services\ProxyAccessLog;
use Illuminate\Http\Request;

class AccessLogController extends Controller
{
    public function report(Request $request, ProxyAccessLog $logs)
    {
        // 原始 JSON 保留控制字符，避免通用 TrimStrings 中间件先把非法主机名修成合法值。
        $raw = $request->getContent();
        if (!$request->isJson() || strlen($raw) > 131072) {
            throw \Illuminate\Validation\ValidationException::withMessages(['records' => '访问记录请求格式或大小无效。']);
        }
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['records' => '访问记录必须使用 JSON 对象。']);
        }
        return response()->json(['data' => $logs->accept($request->attributes->get('node_info'), $payload)]);
    }
}
