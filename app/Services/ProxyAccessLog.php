<?php

namespace App\Services;

use App\Models\Server;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ProxyAccessLog
{
    public const RETENTION_DAYS = 7;
    public const MAX_BATCH = 256;

    public function accept(Server $node, array $payload): array
    {
        $data = Validator::make($payload, [
            'batch_id' => ['required', 'string', 'regex:/\A[a-f0-9]{32}\z/'],
            'records' => 'required|array|min:1|max:' . self::MAX_BATCH,
            'records.*' => 'required|array:node_id,user_id,connected_at,destination,port',
            'records.*.node_id' => 'sometimes|required|integer|min:1|max:4294967295',
            'records.*.user_id' => 'required|integer|min:1|max:4294967295',
            'records.*.connected_at' => 'required|integer|min:1',
            'records.*.destination' => 'required|string|max:253',
            'records.*.port' => 'required|integer|min:1|max:65535',
        ])->validate();
        if (!array_is_list($data['records'])) {
            throw ValidationException::withMessages(['records' => '访问记录必须为列表。']);
        }
        $now = now()->timestamp;
        $cutoff = $now - self::RETENTION_DAYS * 86400;
        // 认证仍属于上报入口，只允许归属本节点或它自己的中转落地。
        $children = ServerRelayService::childrenQuery($node)->pluck('name', 'id');
        $nodes = $children->all();
        if (!$node->isRelayChild()) $nodes[$node->id] = $node->name;
        $rows = [];
        foreach ($data['records'] as $position => $record) {
            $host = self::host($record['destination']);
            if ($host === null || $record['connected_at'] > $now + 300) {
                throw ValidationException::withMessages(['records' => '目标地址或连接时间无效。']);
            }
            // 到期的重试也确认接收，避免旧批次堵塞新记录或重新写入已清理的数据。
            if ($record['connected_at'] < $cutoff) continue;
            // 旧缓存缺少选路信息，不能把中转请求猜成入口直出。
            $serverId = $record['node_id'] ?? 0;
            // 拓扑移除或调整后的迟到记录不再入库，但确认批次以免堵塞后续上报。
            if (!array_key_exists($serverId, $nodes)) continue;
            $rows[] = [
                'server_id' => (int) $serverId, 'server_name' => $nodes[$serverId],
                'batch_id' => $data['batch_id'], 'position' => $position,
                'user_id' => (int) $record['user_id'], 'connected_at' => (int) $record['connected_at'],
                'destination' => $host, 'port' => (int) $record['port'],
            ];
        }
        if ($rows !== []) {
            // 单条原子语句；重试只更新相同批次号，保留首次接收的全部记录内容。
            DB::table('v2_proxy_access_log')->upsert($rows, ['server_id', 'batch_id', 'position'], ['batch_id']);
        }
        return ['batch_id' => $data['batch_id']];
    }

    public static function host(string $value): ?string
    {
        if (filter_var($value, FILTER_VALIDATE_IP)) {
            return inet_ntop(inet_pton($value));
        }
        // 只接受主机名，不截取 URL，拒绝路径、认证信息、查询参数和控制字符。
        $value = strtolower(rtrim($value, '.'));
        if (strlen($value) > 253 || !preg_match('/\A(?=.{1,253}\z)[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*\z/', $value)) return null;
        // 看起来像 IPv4 的无效地址不作为域名保存。
        return preg_match('/\A[0-9.]+\z/', $value) ? null : $value;
    }

    public function records(Request $request): array
    {
        $input = $request->validate([
            'period' => 'sometimes|in:today,7d,custom',
            'start_at' => 'required_if:period,custom|date_format:Y-m-d\TH:i',
            'end_at' => 'required_if:period,custom|date_format:Y-m-d\TH:i|after:start_at',
            'user' => 'nullable|string|max:100', 'node' => 'nullable|string|max:100',
            'target' => 'nullable|string|max:253', 'port' => 'nullable|integer|min:1|max:65535',
            'page' => 'sometimes|integer|min:1|max:1000000',
            'page_size' => 'sometimes|integer|min:1|max:100',
            'snapshot_id' => 'sometimes|integer|min:0',
            'snapshot_at' => 'sometimes|integer|min:1|max:' . now()->timestamp,
            'view' => 'sometimes|in:users,targets,records',
            'sort' => 'sometimes|in:count,time',
            'user_id' => 'required_if:view,targets|integer|min:1|max:4294967295',
            'destination' => 'sometimes|required|string|max:253',
        ]);
        $now = CarbonImmutable::now(config('app.timezone'));
        // 翻页、切换排序和展开详情使用同一个时间边界，跨午夜也不会换成另一天。
        $anchor = isset($input['snapshot_at']) ? CarbonImmutable::createFromTimestamp((int) $input['snapshot_at'], $now->timezone) : $now;
        $oldest = $now->subSeconds(self::RETENTION_DAYS * 86400);
        $period = $input['period'] ?? 'today';
        $start = $period === 'custom' ? CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $input['start_at'], $now->timezone) : ($period === '7d' ? $anchor->subSeconds(self::RETENTION_DAYS * 86400) : $anchor->startOfDay());
        $end = $period === 'custom' ? CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $input['end_at'], $now->timezone) : $anchor->addMinutes(5);
        if ($period === 'today') $end = $end->min($anchor->startOfDay()->addDay());
        if (($period === 'custom' && $start < $anchor->subSeconds(self::RETENTION_DAYS * 86400)->startOfMinute()) || $end > $now->addMinutes(5)->endOfMinute() || $start >= $end
            || ($period === 'custom' && ($start->format('Y-m-d\TH:i') !== $input['start_at'] || $end->format('Y-m-d\TH:i') !== $input['end_at']))) {
            throw ValidationException::withMessages(['start_at' => '请选择最近 7 天内的有效时间。']);
        }
        $query = DB::table('v2_proxy_access_log as a')
            ->where('a.connected_at', '>=', max($start->timestamp, $oldest->timestamp))->where('a.connected_at', '<', $end->timestamp);
        $user = trim($input['user'] ?? '');
        if ($user !== '') {
            $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $user);
            $query->where(function ($part) use ($user, $escaped): void {
                $part->whereIn('a.user_id', DB::table('v2_user')->select('id')->whereRaw("email LIKE ? ESCAPE '!'", ['%' . $escaped . '%']));
                if (ctype_digit($user)) $part->orWhere('a.user_id', $user);
            });
        }
        foreach (['node' => ['a.server_name', 'a.server_id'], 'target' => ['a.destination', null]] as $key => [$column, $id]) {
            $value = trim($input[$key] ?? '');
            if ($value === '') continue;
            $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
            $query->where(function ($part) use ($column, $id, $value, $escaped): void {
                $part->whereRaw("$column LIKE ? ESCAPE '!'", ['%' . $escaped . '%']);
                if ($id !== null && ctype_digit($value)) $part->orWhere($id, $value);
            });
        }
        if (isset($input['port'])) $query->where('a.port', $input['port']);
        if (isset($input['user_id'])) $query->where('a.user_id', $input['user_id']);
        if (isset($input['destination'])) {
            $destination = self::host($input['destination']);
            if ($destination === null) throw ValidationException::withMessages(['destination' => '目标地址无效。']);
            $query->where('a.destination', $destination);
        }
        $snapshot = isset($input['snapshot_id']) ? (int) $input['snapshot_id'] : (int) DB::table('v2_proxy_access_log')->max('id');
        $query->where('a.id', '<=', $snapshot);
        $page = (int) ($input['page'] ?? 1);
        $size = (int) ($input['page_size'] ?? 20);
        $view = $input['view'] ?? 'records';
        $sort = $input['sort'] ?? 'count';
        if ($view === 'users' && $user === '' && !isset($input['user_id']) && DB::connection()->getDriverName() === 'mysql') {
            // 汇总先按所选时间范围读取；避免为用户去重而扫描全部历史用户索引。
            $query->forceIndex('proxy_access_time_user');
        }
        if ($view === 'records') {
            $total = (clone $query)->count();
            $query->select('a.id', 'a.user_id', 'a.server_id', 'a.server_name', 'a.connected_at', 'a.destination', 'a.port')
                ->orderByDesc('a.connected_at')->orderByDesc('a.id');
        } else {
            // 在数据库内汇总后分页，不读取全量明细，也不维护第二份统计表。
            $columns = $view === 'users' ? ['a.user_id'] : ['a.user_id', 'a.destination', 'a.port'];
            $total = $view === 'users' ? (clone $query)->distinct()->count('a.user_id')
                : DB::query()->fromSub((clone $query)->select($columns)->groupBy($columns), 'groups')->count();
            $query->select($columns)->selectRaw('COUNT(*) as access_count, MAX(a.connected_at) as last_at')->groupBy($columns);
            if ($sort === 'count') $query->orderByDesc('access_count');
            $query->orderByDesc('last_at');
            foreach ($columns as $column) $query->orderBy($column);
        }
        $rows = $query->forPage($page, $size)->get();
        // 仅查询本页涉及的用户，避免计数、排序和每条明细都关联用户表。
        $names = DB::table('v2_user')->whereIn('id', $rows->pluck('user_id')->unique())->pluck('email', 'id');
        $list = $rows->map(fn ($row) => $view === 'records' ? [
                'id' => (string) $row->id, 'user_id' => (int) $row->user_id,
                'user_name' => $names[$row->user_id] ?? '#' . $row->user_id,
                'server_id' => (int) $row->server_id, 'server_name' => $row->server_name,
                'connected_at' => CarbonImmutable::createFromTimestamp((int) $row->connected_at, $now->timezone)->toIso8601String(),
                'destination' => $row->destination, 'port' => (int) $row->port,
            ] : [
                'user_id' => (int) $row->user_id, 'user_name' => $names[$row->user_id] ?? '#' . $row->user_id,
                'access_count' => (int) $row->access_count,
                'last_at' => CarbonImmutable::createFromTimestamp((int) $row->last_at, $now->timezone)->toIso8601String(),
            ] + ($view === 'targets' ? ['destination' => $row->destination, 'port' => (int) $row->port] : []))->all();
        return compact('list', 'page', 'total') + ['page_size' => $size, 'last_page' => max(1, (int) ceil($total / $size)), 'snapshot_id' => (string) $snapshot,
            'snapshot_at' => $anchor->timestamp,
            'meta' => ['timezone' => config('app.timezone'), 'retention_days' => self::RETENTION_DAYS,
                'min_at' => $oldest->format('Y-m-d\TH:i'), 'max_at' => $now->addMinutes(5)->format('Y-m-d\TH:i'),
                'start_at' => $start->format('Y-m-d\TH:i'), 'end_at' => $now->format('Y-m-d\TH:i')]];
    }

    public function prune(): int
    {
        $cutoff = now()->timestamp - self::RETENTION_DAYS * 86400;
        $deleted = 0;
        // 分批删除，不在一次事务内锁住全部过期记录。
        do {
            $ids = DB::table('v2_proxy_access_log')->where('connected_at', '<', $cutoff)->orderBy('connected_at')->limit(2000)->pluck('id');
            if ($ids->isEmpty()) break;
            $deleted += DB::table('v2_proxy_access_log')->whereIn('id', $ids)->delete();
        } while ($ids->count() === 2000);
        return $deleted;
    }
}
