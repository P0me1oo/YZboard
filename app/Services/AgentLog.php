<?php

namespace App\Services;

use App\Models\Server;
use App\Models\ServerMachine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class AgentLog
{
    public const RETENTION_DAYS = 7;

    public function accept(?Server $node, ?ServerMachine $machine, array $payload): array
    {
        abort_unless($node || $machine, 403);
        $data = Validator::make($payload, [
            'batch_id' => 'required|string|regex:/^[a-f0-9]{32}$/',
            'records' => 'required|array|min:1|max:128',
            'records.*' => 'required|array:node_id,source,level,message,occurred_at,event',
            'records.*.node_id' => 'nullable|integer|min:1',
            'records.*.source' => 'required|in:agent,xray,singbox',
            'records.*.level' => 'required|in:debug,info,warn,error,fatal',
            'records.*.message' => 'required|string|max:4096',
            'records.*.occurred_at' => 'required|integer|min:1|max:' . (now()->timestamp + 300),
            'records.*.event' => 'sometimes|in:xray.crash',
        ])->validate();
        if (!array_is_list($data['records'])) throw \Illuminate\Validation\ValidationException::withMessages(['records' => '日志必须为列表']);
        $machine ??= $node?->machine_id ? ServerMachine::find($node->machine_id) : null;
        $allowed = $node ? collect([$node->id => $node]) : Server::where('machine_id', $machine->id)->get()->keyBy('id');
        $owner = $node ? 'node:' . $node->id : 'machine:' . $machine->id;
        $rows = [];
        foreach ($data['records'] as $index => $record) {
            if (isset($record['event']) && ($record['source'] !== 'xray' || !in_array($record['level'], ['error', 'fatal'], true))) {
                throw \Illuminate\Validation\ValidationException::withMessages(['records' => 'Xray 崩溃事件必须为 Xray 错误日志']);
            }
            $nodeId = $record['node_id'] ?? $node?->id;
            if ($nodeId && !$allowed->has($nodeId)) {
                // 已删除节点的迟到日志确认并丢弃，不阻塞机器后续批次。
                if (!Server::where('id', $nodeId)->exists()) continue;
                abort(403, '日志节点不属于上报身份');
            }
            if ($record['occurred_at'] < now()->subDays(self::RETENTION_DAYS)->timestamp) continue;
            $rows[] = [
                'event_key' => hash('sha256', $owner . '|' . $data['batch_id'] . '|' . $index),
                'machine_id' => $machine?->id, 'machine_name' => $machine?->name,
                'node_id' => $nodeId, 'node_name' => $nodeId ? $allowed->get($nodeId)?->name : null,
                'source' => $record['source'], 'level' => $record['level'],
                'event' => $record['event'] ?? null,
                'message' => LogSanitizer::text($record['message']), 'occurred_at' => $record['occurred_at'],
            ];
        }
        DB::transaction(function () use ($rows) {
            $ordinary = array_values(array_filter($rows, fn ($row) => $row['event'] === null));
            foreach (array_chunk($ordinary, 100) as $chunk) DB::table('v2_agent_log')->insertOrIgnore($chunk);
            foreach (array_filter($rows, fn ($row) => $row['event'] !== null) as $row) {
                if (DB::table('v2_agent_log')->insertOrIgnore($row) && $row['event'] === 'xray.crash') {
                    $id = DB::table('v2_agent_log')->where('event_key', $row['event_key'])->value('id');
                    app(\App\Services\TelegramBot\BusinessNotificationService::class)->recordXrayCrash((int) $id);
                }
            }
        });
        return ['batch_id' => $data['batch_id']];
    }
}
