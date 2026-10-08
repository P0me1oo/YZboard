<?php

namespace App\Http\Controllers\V2\Server;

use App\Http\Controllers\Controller;
use App\Services\DeviceHandoverService;
use App\Services\ServerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceHandoverController extends Controller
{
    private const RUN = ['required', 'string', 'regex:/\A[a-f0-9]{32}\z/'];

    public function begin(Request $request, DeviceHandoverService $service): JsonResponse
    {
        $data = $request->validate(['run' => self::RUN]);
        return response()->json(['data' => $service->begin((int) $request->attributes->get('node_info')->id, $data['run'])]);
    }

    public function admit(Request $request, DeviceHandoverService $service): JsonResponse
    {
        $data = $request->validate([
            'run' => self::RUN, 'sequence' => 'required|integer|min:1|max:9007199254740991',
            'user_id' => 'required|integer|min:1', 'ip' => 'required|ip',
        ]);
        $node = $request->attributes->get('node_info');
        $user = ServerService::getAvailableUsers($node, (int) $data['user_id'])->first();
        abort_if($user === null, 403, '用户当前无权使用此节点');
        return response()->json(['data' => $service->admit((int) $node->id, (int) $user->id, (int) $user->device_limit, $data)]);
    }

    public function sync(Request $request, DeviceHandoverService $service): JsonResponse
    {
        $data = $request->validate([
            'run' => self::RUN, 'sequence' => 'required|integer|min:1|max:9007199254740991',
            'pending' => 'present|array|max:65536', 'pending.*' => 'integer|min:1|max:9007199254740991|lte:sequence',
            'sources' => 'present|array|max:65536', 'sources.*.user_id' => 'required|integer|min:1',
            'sources.*.ip' => 'required|ip', 'sources.*.lease' => ['required', 'string', 'distinct', 'regex:/\A[a-f0-9]{32}\z/'],
            'sources.*.connect_sequence' => 'required|integer|min:1|max:9007199254740991|lte:sequence',
            'sources.*.age_ms' => 'required|integer|min:0|max:315360000000',
            'retired' => 'sometimes|array|max:65536', 'retired.*.user_id' => 'required|integer|min:1',
            'retired.*.ip' => 'required|ip', 'retired.*.lease' => ['required', 'string', 'distinct', 'regex:/\A[a-f0-9]{32}\z/'],
        ]);
        return response()->json(['data' => $service->sync((int) $request->attributes->get('node_info')->id, $data)]);
    }
}
