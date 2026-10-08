<?php

namespace App\Http\Controllers\V2\Server;

use App\Http\Controllers\Controller;
use App\Services\DeviceHandoverProtocol;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceHandoverController extends Controller
{
    public function begin(Request $request, DeviceHandoverProtocol $protocol): JsonResponse
    {
        return $this->handle($request, $protocol, 'begin');
    }

    public function admit(Request $request, DeviceHandoverProtocol $protocol): JsonResponse
    {
        return $this->handle($request, $protocol, 'admit');
    }

    public function sync(Request $request, DeviceHandoverProtocol $protocol): JsonResponse
    {
        return $this->handle($request, $protocol, 'sync');
    }

    private function handle(Request $request, DeviceHandoverProtocol $protocol, string $action): JsonResponse
    {
        return response()->json(['data' => $protocol->handle($request->attributes->get('node_info'), $action, $request->all())]);
    }
}
