<?php

namespace App\Services;

use App\Models\Server;
use App\Models\ServerRoute;
use App\Support\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RouteBlockLog
{
    public const RETENTION_SETTING = 'route_block_retention_days';
    public const DEFAULT_DAYS = 7;
    public const MAX_DAYS = 365;

    public static function days(): int
    {
        return max(1, min(self::MAX_DAYS, (int) (new Setting())->getOrFail(self::RETENTION_SETTING, self::DEFAULT_DAYS)));
    }

    public static function saveDays(int $days): void
    {
        (new Setting())->set(self::RETENTION_SETTING, $days);
    }

    public function accept(Server $node, array $payload): array
    {
        $data = Validator::make($payload, [
            'batch_id' => ['required', 'string', 'regex:/\A[a-f0-9]{32}\z/'],
            'records' => 'required|array|min:1|max:256',
            'records.*' => 'required|array:node_id,user_id,connected_at,destination,port,rule_id,network',
            'records.*.node_id' => 'required|integer|min:1|max:4294967295',
            'records.*.user_id' => 'required|integer|min:0|max:4294967295',
            'records.*.rule_id' => 'required|integer|min:1|max:4294967295',
            'records.*.connected_at' => 'required|integer|min:1',
            'records.*.destination' => 'required|string|max:253',
            'records.*.port' => 'required|integer|min:1|max:65535',
            'records.*.network' => 'required|in:tcp,udp',
        ])->validate();
        if (!array_is_list($data['records'])) {
            throw ValidationException::withMessages(['records' => '拦截记录必须为列表。']);
        }
        $now = now()->timestamp;
        $cutoff = $now - self::days() * 86400;
        $rows = [];
        $names = ServerRoute::whereIn('id', array_column($data['records'], 'rule_id'))->pluck('remarks', 'id');
        foreach ($data['records'] as $position => $record) {
            $host = ProxyAccessLog::host($record['destination']);
            if ($host === null || $record['connected_at'] > $now + 300
                || (int) $record['node_id'] !== (int) $node->id
                || ((int) $record['user_id'] === 0 && !$node->isRelayChild())) {
                throw ValidationException::withMessages(['records' => '拦截记录的节点、用户、目标或时间无效。']);
            }
            if ($record['connected_at'] < $cutoff) continue;
            $rows[] = [
                'node_id' => $node->id, 'node_name' => $node->name,
                'user_id' => (int) $record['user_id'] ?: null,
                'rule_id' => (int) $record['rule_id'], 'rule_name' => $names[$record['rule_id']] ?? null,
                'destination' => $host, 'port' => (int) $record['port'], 'network' => $record['network'],
                'occurred_at' => (int) $record['connected_at'], 'batch_id' => $data['batch_id'], 'position' => $position,
            ];
        }
        // 默认记录，不读取通用日志开关；隐私保护期间仍确认合法批次，避免阻塞节点队列。
        if (!AgentLogPolicy::disabled(node: $node) && $rows !== []) {
            DB::table('v2_route_block_log')->upsert($rows, ['node_id', 'batch_id', 'position'], ['batch_id']);
        }
        return ['batch_id' => $data['batch_id']];
    }

    public function records(Request $request): array
    {
        $data = $request->validate([
            'user' => 'nullable|string|max:100', 'node' => 'nullable|string|max:100',
            'rule' => 'nullable|string|max:100', 'target' => 'nullable|string|max:253',
            'port' => 'nullable|integer|min:1|max:65535', 'network' => 'nullable|in:tcp,udp',
            'start_at' => 'nullable|date_format:Y-m-d\TH:i', 'end_at' => 'nullable|date_format:Y-m-d\TH:i',
            'page' => 'sometimes|integer|min:1|max:1000000', 'page_size' => 'sometimes|integer|min:1|max:100',
            'view' => 'sometimes|in:users,records',
            'user_id' => 'sometimes|required|integer|min:0|max:4294967295',
            'snapshot_id' => 'sometimes|required|integer|min:0',
            'snapshot_at' => 'sometimes|required|integer|min:1|max:' . now()->timestamp,
        ]);
        $zone = config('app.timezone');
        $now = CarbonImmutable::now($zone);
        $days = self::days();
        $oldest = $now->subSeconds($days * 86400);
        $anchor = isset($data['snapshot_at']) ? CarbonImmutable::createFromTimestamp((int) $data['snapshot_at'], $zone) : $now;
        $start = !empty($data['start_at']) ? CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $data['start_at'], $zone) : $anchor->subSeconds($days * 86400);
        $end = !empty($data['end_at']) ? CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $data['end_at'], $zone) : $anchor->addMinutes(5);
        if ($start >= $end || (!empty($data['start_at']) && $start < $anchor->subSeconds($days * 86400)->startOfMinute()) || $end > $now->addMinutes(5)->endOfMinute()) {
            throw ValidationException::withMessages(['start_at' => "请选择最近 {$days} 天内的有效时间。"]);
        }
        $query = DB::table('v2_route_block_log')->where('occurred_at', '>=', max($oldest->timestamp, $start->timestamp))
            ->where('occurred_at', '<', $end->timestamp);
        $like = fn (string $value) => '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value) . '%';
        foreach (['node' => ['node_name', 'node_id'], 'rule' => ['rule_name', 'rule_id'], 'target' => ['destination', null]] as $key => [$column, $id]) {
            $value = trim($data[$key] ?? '');
            if ($value === '') continue;
            $query->where(function ($part) use ($value, $column, $id, $like) {
                $part->whereRaw("$column LIKE ? ESCAPE '!'", [$like($value)]);
                if ($id && ctype_digit($value)) $part->orWhere($id, $value);
            });
        }
        $user = trim($data['user'] ?? '');
        if ($user !== '') $query->where(function ($part) use ($user, $like) {
            $part->whereIn('user_id', DB::table('v2_user')->select('id')->whereRaw("email LIKE ? ESCAPE '!'", [$like($user)]));
            if (ctype_digit($user)) {
                if ($user === '0') $part->orWhereNull('user_id');
                else $part->orWhere('user_id', $user);
            }
        });
        foreach (['port', 'network'] as $key) if (!empty($data[$key])) $query->where($key, $data[$key]);
        if (array_key_exists('user_id', $data)) {
            if ((int) $data['user_id'] === 0) $query->whereNull('user_id');
            else $query->where('user_id', (int) $data['user_id']);
        }
        $snapshot = isset($data['snapshot_id']) ? (int) $data['snapshot_id'] : (int) DB::table('v2_route_block_log')->max('id');
        $query->where('id', '<=', $snapshot);
        $view = $data['view'] ?? 'records';
        if ($view === 'users') {
            // 在数据库按用户汇总；子查询计数保留 NULL 用户组，不把无法识别的用户漏掉。
            $total = DB::query()->fromSub((clone $query)->select('user_id')->groupBy('user_id'), 'users')->count();
            $query->select('user_id')->selectRaw('COUNT(*) AS block_count, MAX(occurred_at) AS last_at')->groupBy('user_id')
                ->orderByDesc('block_count')->orderByDesc('last_at')->orderBy('user_id');
        } else {
            $total = (clone $query)->count();
            $query->select('id', 'node_id', 'node_name', 'user_id', 'rule_id', 'rule_name', 'destination', 'port', 'network', 'occurred_at')
                ->orderByDesc('occurred_at')->orderByDesc('id');
        }
        $page = (int) ($data['page'] ?? 1);
        $size = (int) ($data['page_size'] ?? 20);
        $rows = $query->forPage($page, $size)->get();
        $users = DB::table('v2_user')->whereIn('id', $rows->pluck('user_id')->filter()->unique())->pluck('email', 'id');
        $list = $rows->map(fn ($row) => [
            'user_id' => $row->user_id === null ? null : (int) $row->user_id,
            'user_name' => $row->user_id === null ? null : ($users[$row->user_id] ?? '#' . $row->user_id),
        ] + ($view === 'users' ? [
            'block_count' => (int) $row->block_count, 'last_at' => (int) $row->last_at,
        ] : [
            'id' => (string) $row->id, 'occurred_at' => (int) $row->occurred_at,
            'node_id' => (int) $row->node_id, 'node_name' => $row->node_name,
            'rule_id' => (int) $row->rule_id, 'rule_name' => $row->rule_name,
            'destination' => $row->destination, 'port' => (int) $row->port, 'network' => $row->network,
        ]))->all();
        return compact('list', 'total', 'page') + ['page_size' => $size, 'last_page' => max(1, (int) ceil($total / $size)),
            'timezone' => $zone, 'retention_days' => $days, 'snapshot_id' => (string) $snapshot, 'snapshot_at' => $anchor->timestamp];
    }
}
