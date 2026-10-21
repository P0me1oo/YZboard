<?php

namespace App\Http\Controllers\V2\Server;

use App\Http\Controllers\Controller;
use App\Services\AgentLog;
use Illuminate\Http\Request;

class AgentLogController extends Controller
{
    public function report(Request $request, AgentLog $logs)
    {
        abort_unless($request->isJson() && strlen($request->getContent()) <= 262144, 422);
        return response()->json(['data' => $logs->accept($request->attributes->get('node_info'),
            $request->attributes->get('machine_info'), $request->json()->all())]);
    }
}
