<?php

namespace App\Http\Controllers\V2\Admin\Server;

use App\Http\Controllers\Controller;
use App\Services\LiteMonitor;
use Illuminate\Http\Request;

class LiteController extends Controller
{
    public function __construct(private readonly LiteMonitor $monitor) {}

    public function config() { return $this->success($this->monitor->view()); }

    public function save(Request $request)
    {
        return $this->success($this->monitor->save($request->validate([
            'url' => 'required|string|max:1024', 'api_key' => ['nullable', 'string', 'min:12', 'max:4096', 'regex:/\A[^\s]+\z/'],
            'enabled' => 'required|boolean',
        ])));
    }

    public function inventory() { return $this->success($this->monitor->inventory()); }

    public function snapshot() { return $this->success($this->monitor->snapshot()); }

    public function bind(Request $request)
    {
        return $this->success($this->monitor->bind($request->validate([
            'action' => 'required|in:auto,bind,unbind,import,resume',
            'machine_id' => 'required_if:action,bind,unbind,resume|integer|exists:v2_server_machine,id',
            'uuid' => 'required_if:action,bind|uuid',
            'uuids' => 'required_if:action,import|array|min:1|max:200', 'uuids.*' => 'required|uuid|distinct',
        ])));
    }

    public function history(Request $request)
    {
        $input = $request->validate(['machine_id' => 'required|integer|exists:v2_server_machine,id',
            'range_hours' => 'required|integer|min:1|max:24', 'limit' => 'required|integer|min:10|max:500']);
        return $this->success($this->monitor->history($input['machine_id'], $input['range_hours'], $input['limit']));
    }
}
