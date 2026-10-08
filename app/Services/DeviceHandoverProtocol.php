<?php

namespace App\Services;

use App\Models\Server;
use Illuminate\Support\Facades\Validator;

/** HTTP 和长连接共用校验、权限筛选与设备协调，确认只在处理完成后返回。 */
class DeviceHandoverProtocol
{
    private const RUN = ['required', 'string', 'regex:/\A[a-f0-9]{32}\z/'];
    private const SEQUENCE = 'required|integer|min:1|max:9007199254740991';

    public function __construct(private readonly DeviceHandoverService $service) {}

    public function handle(Server $node, string $action, array $payload): array
    {
        $rules = ['run' => self::RUN];
        if ($action === 'admit') {
            $rules += ['sequence' => self::SEQUENCE, 'user_id' => 'required|integer|min:1', 'ip' => 'required|ip'];
        } elseif ($action === 'sync') {
            $rules += ['sequence' => self::SEQUENCE, 'unchanged' => 'sometimes|boolean'];
            if (in_array($payload['unchanged'] ?? false, [true, 1, '1'], true)) {
                $rules += [
                    'base_sequence' => self::SEQUENCE . '|lt:sequence',
                    'pending' => 'missing', 'sources' => 'missing', 'retired' => 'missing',
                ];
            } else {
                $rules += [
                    'base_sequence' => 'missing',
                    'pending' => 'present|array|max:65536', 'pending.*' => 'integer|min:1|max:9007199254740991|lte:sequence',
                    'sources' => 'present|array|max:65536', 'sources.*.user_id' => 'required|integer|min:1',
                    'sources.*.ip' => 'required|ip', 'sources.*.lease' => ['required', 'string', 'distinct', 'regex:/\A[a-f0-9]{32}\z/'],
                    'sources.*.connect_sequence' => 'required|integer|min:1|max:9007199254740991|lte:sequence',
                    'sources.*.age_ms' => 'required|integer|min:0|max:315360000000',
                    'retired' => 'sometimes|array|max:65536', 'retired.*.user_id' => 'required|integer|min:1',
                    'retired.*.ip' => 'required|ip', 'retired.*.lease' => ['required', 'string', 'distinct', 'regex:/\A[a-f0-9]{32}\z/'],
                ];
            }
        } else {
            abort_unless($action === 'begin', 404);
        }
        $data = Validator::make($payload, $rules)->validate();
        if ($action === 'begin') return $this->service->begin((int) $node->id, $data['run']);
        if ($action === 'sync') return $this->service->sync((int) $node->id, $data);

        $user = ServerService::getAvailableUsers($node, (int) $data['user_id'])->first();
        abort_if($user === null, 403, '用户当前无权使用此节点');
        return $this->service->admit((int) $node->id, (int) $user->id, (int) $user->device_limit, $data);
    }
}
