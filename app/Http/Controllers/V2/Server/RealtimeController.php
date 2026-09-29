<?php

namespace App\Http\Controllers\V2\Server;

use App\Http\Controllers\Controller;
use App\Services\NodeControlStateService;
use App\Services\NodeStateService;
use App\Services\RealtimeStateStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RealtimeController extends Controller
{
    public function begin(Request $request, RealtimeStateStore $store): JsonResponse
    {
        $data = $request->validate(['run' => ['required', 'string', 'regex:/\A[a-f0-9]{32,64}\z/']]);
        $node = $request->attributes->get('node_info');
        return response()->json(['data' => $store->begin('node:' . $node->id, $data['run'])]);
    }

    public function state(Request $request, NodeStateService $states): JsonResponse
    {
        return response()->json(['data' => $states->accept(
            $request->attributes->get('node_info'), $request->all()
        )]);
    }

    public function sync(Request $request, NodeControlStateService $control): JsonResponse
    {
        $node = $request->attributes->get('node_info');
        return response()->json(['data' => [
            'control' => $control->snapshot($node),
            'devices' => $control->devices($node),
        ]]);
    }
}
