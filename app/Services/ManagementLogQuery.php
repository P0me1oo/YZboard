<?php

namespace App\Services;

use App\Models\Server;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ManagementLogQuery
{
    public function agentNodes(): array
    {
        $nodes = Server::query()->get(['id', 'name'])->keyBy('id');
        // 已删除节点仍可查询保留期内的日志，名称取最近入库的一条记录。
        $latest = DB::table('v2_agent_log')->whereNotNull('node_id')
            ->whereBetween('occurred_at', [now()->timestamp - LogSettings::days('agent') * 86400, now()->timestamp])
            ->select('node_id', DB::raw('MAX(id) as latest_id'))->groupBy('node_id');
        $history = DB::table('v2_agent_log as logs')->joinSub($latest, 'latest', function ($join) {
            $join->on('logs.id', '=', 'latest.latest_id');
        })->get(['logs.node_id as id', 'logs.node_name as name']);
        foreach ($history as $node) {
            if (!$nodes->has($node->id)) $nodes->put($node->id, $node);
        }
        return $nodes->sortKeys()->map(fn ($node) => [
            'id' => (int) $node->id, 'name' => $node->name ?: '#' . $node->id,
        ])->values()->all();
    }

    public function fetch(Request $request, string $category): array
    {
        abort_unless(in_array($category, ['system', 'agent', 'user']), 404);
        $data = $request->validate([
            'page' => 'sometimes|integer|min:1|max:10000', 'page_size' => 'sometimes|integer|min:1|max:100',
            'start_at' => 'nullable|date_format:Y-m-d\TH:i', 'end_at' => 'nullable|date_format:Y-m-d\TH:i|after_or_equal:start_at',
            'node_id' => 'nullable|integer|min:1', 'machine_id' => 'nullable|integer|min:1',
            'user' => 'nullable|string|max:100', 'keyword' => 'nullable|string|max:100',
            'source' => 'nullable|in:panel,audit,agent,xray,singbox',
            'level' => 'nullable|in:debug,info,warn,error,fatal',
            'event' => 'nullable|in:login,password_change,password_reset,connection_limit,connection_rate,device_limit,speed_limit',
            'date' => 'nullable|date_format:Y-m-d|before_or_equal:today',
        ]);
        $page = (int) ($data['page'] ?? 1); $size = (int) ($data['page_size'] ?? 20);
        foreach (['start_at', 'end_at'] as $key) {
            if (!empty($data[$key])) $data[$key] = \Carbon\Carbon::createFromFormat('Y-m-d\TH:i', $data[$key], config('app.timezone'))->startOfMinute()->timestamp;
        }
        if (!empty($data['end_at'])) $data['end_at'] += 59;
        if ($category === 'system' && ($data['source'] ?? 'panel') !== 'audit') {
            abort_if(!empty($data['source']) && $data['source'] !== 'panel', 422);
            return app(SystemLogReader::class)->fetch($data, $page, $size);
        }
        $audit = $category === 'system';
        abort_if($category === 'agent' && !empty($data['source']) && !in_array($data['source'], ['agent', 'xray', 'singbox']), 422);
        $table = $audit ? 'v2_admin_audit_log' : ($category === 'agent' ? 'v2_agent_log' : 'v2_user_event_log');
        $at = $audit ? 'created_at' : 'occurred_at';
        $days = LogSettings::days($audit ? 'audit' : $category);
        $query = DB::table($table)->where($at, '>=', max(now()->timestamp - $days * 86400, (int) ($data['start_at'] ?? 0)))
            ->where($at, '<=', (int) ($data['end_at'] ?? now()->timestamp));
        foreach (['node_id', 'machine_id', 'level', 'event'] as $key) {
            if (!$audit && !empty($data[$key]) && ($category === 'agent' ? $key !== 'event' : $key !== 'level')) $query->where($key, $data[$key]);
        }
        if ($category === 'agent' && !empty($data['source'])) $query->where('source', $data['source']);
        if (!empty($data['user']) && $category !== 'agent') {
            $users = User::query()->select('id');
            ctype_digit($data['user']) ? $users->where('id', $data['user']) : $users->where('email', $data['user']);
            $query->whereIn($audit ? 'admin_id' : 'user_id', $users);
        }
        if (!empty($data['keyword'])) $query->where($audit ? 'action' : ($category === 'agent' ? 'message' : 'event'), 'like', '%' . addcslashes($data['keyword'], '%_\\') . '%');
        $total = (clone $query)->count();
        $rows = $query->orderByDesc($at)->orderByDesc('id')->offset(($page - 1) * $size)->limit($size)->get();
        $users = $category === 'agent' ? collect() : User::whereIn('id', $rows->pluck($audit ? 'admin_id' : 'user_id'))->pluck('email', 'id');
        $list = $rows->map(function ($row) use ($audit, $category, $users) {
            if ($audit) return ['id' => $row->id, 'occurred_at' => $row->created_at, 'source' => 'audit', 'level' => 'info',
                'user_id' => $row->admin_id, 'user_name' => $users[$row->admin_id] ?? '#' . $row->admin_id,
                'message' => LogSanitizer::text($row->action), 'source_ip' => filter_var($row->ip, FILTER_VALIDATE_IP) ? $row->ip : null];
            $item = (array) $row; unset($item['event_key']);
            if ($category === 'user') $item['user_name'] = $users[$row->user_id] ?? '#' . $row->user_id;
            if ($category === 'agent') $item['message'] = LogSanitizer::text($item['message']);
            return $item;
        })->all();
        return ['list' => $list, 'page' => $page, 'page_size' => $size, 'total' => $total, 'last_page' => max(1, (int) ceil($total / $size)),
            'retention_days' => $days, 'timezone' => config('app.timezone')];
    }
}
