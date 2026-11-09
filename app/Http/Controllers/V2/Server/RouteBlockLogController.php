<?php

namespace App\Http\Controllers\V2\Server;

use App\Http\Controllers\Controller;
use App\Services\RouteBlockLog;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class RouteBlockLogController extends Controller
{
    public function report(Request $request, RouteBlockLog $logs)
    {
        $raw = $request->getContent();
        if (!$request->isJson() || strlen($raw) > 131072 || !is_array($payload = json_decode($raw, true))) {
            throw ValidationException::withMessages(['records' => '拦截记录请求格式或大小无效。']);
        }
        return response()->json(['data' => $logs->accept($request->attributes->get('node_info'), $payload)]);
    }
}
