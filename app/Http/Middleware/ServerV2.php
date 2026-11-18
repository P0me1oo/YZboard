<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Models\Server as ServerModel;
use App\Models\ServerMachine;
use App\Services\ServerService;
use Closure;
use Illuminate\Http\Request;

/**
 * V2 server middleware: machine-token or server-token auth, no node_type.
 */
class ServerV2
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->filled('machine_id')) {
            $this->authenticateByMachine($request);
        } else {
            $this->authenticateByServerToken($request);
        }

        return $next($request);
    }

    private function authenticateByServerToken(Request $request): void
    {
        $isHandshake = $request->is('*/server/handshake') || $request->is('api/v2/server/handshake');

        $request->validate([
            'token' => [
                'string', 'required',
                function ($attribute, $value, $fail) {
                    $expected = admin_setting('server_token');
                    if (!is_string($expected) || $expected === '' || !hash_equals($expected, $value)) {
                        $fail("Invalid {$attribute}");
                    }
                },
            ],
            'node_id' => [$isHandshake ? 'nullable' : 'required', function ($attribute, $value, $fail) {
                if ((!is_string($value) && !is_int($value)) || !preg_match('/\A[A-Za-z0-9_-]{1,64}\z/', (string) $value)
                    || (ctype_digit((string) $value) && (int) $value < 1)) $fail('Invalid node identifier');
            }],
        ]);

        $nodeId = $request->input('node_id');
        if ($nodeId === null || $nodeId === '') {
            return;
        }

        $serverInfo = ServerService::getServer($nodeId);
        if (!$serverInfo) {
            throw new ApiException('Server does not exist');
        }
        if ($serverInfo->machine_id || $serverInfo->enabled === false) {
            throw new ApiException('This node requires machine authentication or is disabled', 403);
        }

        $request->attributes->set('node_info', $serverInfo);
    }

    private function authenticateByMachine(Request $request): void
    {
        $isHandshake = $request->is('*/server/handshake') || $request->is('api/v2/server/handshake');

        $request->validate([
            'machine_id' => 'required|integer|min:1',
            'token' => 'required|string|max:255',
            'node_id' => $isHandshake ? 'nullable|integer|min:1' : 'required|integer|min:1',
        ]);

        $machine = ServerMachine::find($request->input('machine_id'));

        if (!$machine || !hash_equals((string) $machine->token, $request->input('token'))) {
            throw new ApiException('Machine not found or invalid token', 401);
        }

        if (!$machine->is_active) {
            throw new ApiException('Machine is disabled', 403);
        }

        $nodeId = (int) $request->input('node_id');
        if ($nodeId > 0) {
            $serverInfo = ServerModel::where('id', $nodeId)
                ->where('machine_id', $machine->id)
                ->where('enabled', true)
                ->first();

            if (!$serverInfo) {
                throw new ApiException('Node not found on this machine');
            }

            $request->attributes->set('node_info', $serverInfo);
        }

        $machine->recordHeartbeat();

        $request->attributes->set('machine_info', $machine);
    }
}
