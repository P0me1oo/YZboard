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
            $rules += ['snapshot' => 'sometimes|array:run,sequence,pending,sources,retired'];
            if (array_key_exists('snapshot', $payload)) {
                $rules += [
                    'snapshot.run' => [...self::RUN, 'same:run'],
                    'snapshot.sequence' => self::SEQUENCE . '|gte:sequence',
                ] + $this->fullSnapshotRules('snapshot.');
            }
        } elseif ($action === 'sync') {
            $rules += ['sequence' => self::SEQUENCE, 'unchanged' => 'sometimes|boolean'];
            if (in_array($payload['unchanged'] ?? false, [true, 1, '1'], true)) {
                $rules += [
                    'base_sequence' => self::SEQUENCE . '|lt:sequence',
                    'pending' => 'missing', 'sources' => 'missing', 'retired' => 'missing',
                ];
            } else {
                $rules += ['base_sequence' => 'missing'] + $this->fullSnapshotRules();
            }
        } else {
            abort_unless($action === 'begin', 404);
        }
        $data = Validator::make($payload, $rules)->validate();
        if (isset($data['snapshot'])) {
            abort_unless(in_array((int) $data['sequence'], array_map('intval', $data['snapshot']['pending']), true), 422, '完整快照必须包含当前申请');
        }
        if ($action === 'begin') return $this->service->begin((int) $node->id, $data['run']);
        if ($action === 'sync') return $this->service->sync((int) $node->id, $data);

        $user = ServerService::getAvailableUsers($node, (int) $data['user_id'])->first();
        abort_if($user === null, 403, '用户当前无权使用此节点');
        return $this->service->admit((int) $node->id, (int) $user->id, (int) $user->device_limit, $data);
    }

    private function fullSnapshotRules(string $prefix = ''): array
    {
        return [
            $prefix . 'pending' => 'present|array|max:65536',
            $prefix . 'pending.*' => 'integer|min:1|max:9007199254740991|lte:' . $prefix . 'sequence',
            $prefix . 'sources' => 'present|array|max:65536', $prefix . 'sources.*.user_id' => 'required|integer|min:1',
            $prefix . 'sources.*.ip' => 'required|ip',
            $prefix . 'sources.*.lease' => ['required', 'string', 'distinct', 'regex:/\A[a-f0-9]{32}\z/'],
            $prefix . 'sources.*.connect_sequence' => 'required|integer|min:1|max:9007199254740991|lte:' . $prefix . 'sequence',
            $prefix . 'sources.*.age_ms' => 'required|integer|min:0|max:315360000000',
            $prefix . 'retired' => 'sometimes|array|max:65536', $prefix . 'retired.*.user_id' => 'required|integer|min:1',
            $prefix . 'retired.*.ip' => 'required|ip',
            $prefix . 'retired.*.lease' => ['required', 'string', 'distinct', 'regex:/\A[a-f0-9]{32}\z/'],
        ];
    }
}
