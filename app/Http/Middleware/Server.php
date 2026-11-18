<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Models\Server as ServerModel;
use App\Services\ServerService;
use Closure;
use Illuminate\Http\Request;

/**
 * @deprecated use {@see ServerV2}
 */
class Server
{
    public function handle(Request $request, Closure $next, ?string $nodeType = null)
    {
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
            'node_id' => ['required', function ($attribute, $value, $fail) {
                if ((!is_string($value) && !is_int($value)) || !preg_match('/\A[A-Za-z0-9_-]{1,64}\z/', (string) $value)
                    || (ctype_digit((string) $value) && (int) $value < 1)) $fail('Invalid node identifier');
            }],
            'node_type' => [
                'nullable',
                function ($attribute, $value, $fail) use ($request) {
                    if ($value === 'v2node') {
                        $value = null;
                    }
                    if (!ServerModel::isValidType($value)) {
                        $fail('Invalid node type specified');
                        return;
                    }
                    $request->merge([$attribute => ServerModel::normalizeType($value)]);
                },
            ]
        ]);

        $nodeType = $request->input('node_type', $nodeType);
        $normalizedNodeType = ServerModel::normalizeType($nodeType);
        $serverInfo = ServerService::getServer(
            $request->input('node_id'),
            $normalizedNodeType
        );
        if (!$serverInfo) {
            throw new ApiException('Server does not exist');
        }
        if ($serverInfo->machine_id || $serverInfo->enabled === false) {
            throw new ApiException('This node requires machine authentication or is disabled', 403);
        }

        $request->attributes->set('node_info', $serverInfo);

        return $next($request);
    }
}
