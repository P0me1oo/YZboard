<?php

namespace App\Http\Controllers\V2\Admin\Server;

use App\Http\Controllers\Controller;
use App\Models\ServerMachine;
use App\Services\RealityScanService;
use App\Services\RealityTargetLocation;
use Illuminate\Http\Request;

class RealityScanController extends Controller
{
    public function scan(Request $request)
    {
        $data = $request->validate([
            'machine_id' => 'required|integer|min:1',
            'targets' => 'nullable|string|max:8192',
            'sni' => 'nullable|string|max:253',
        ]);
        $machine = ServerMachine::findOrFail($data['machine_id']);
        $task = $request->isMethod('get') ? RealityScanService::view($machine->id)
            : RealityScanService::start($machine, $data['targets'] ?? null, strtolower(trim($data['sni'] ?? '')));
        // 机器进程标识只用于匹配，不属于管理界面数据。
        if ($task) { unset($task['boot_id']); }
        if (($task['status'] ?? null) === 'succeeded') {
            $task['results'] = app(RealityTargetLocation::class)->enrich($task['results']);
        }
        return $this->success($task);
    }
}
