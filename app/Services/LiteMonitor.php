<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\LiteConnection;
use App\Models\ServerMachine;
use Illuminate\Database\ConcurrencyErrorDetector;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Sleep;

class LiteMonitor
{
    public function __construct(private readonly LiteClient $client) {}

    public function connection(): LiteConnection
    {
        return LiteConnection::find(1) ?? new LiteConnection(['id' => 1, 'url' => '', 'enabled' => false]);
    }

    public function view(): array
    {
        $connection = $this->connection();
        return ['url' => $connection->url, 'enabled' => $connection->enabled,
            'configured' => (bool) $connection->getRawOriginal('api_key'),
            'bindings' => DB::table('v2_lite_machine')->join('v2_server_machine', 'machine_id', '=', 'v2_server_machine.id')
                ->get(['machine_id', 'name'])->all()];
    }

    public function save(array $input): array
    {
        $url = $this->client->normalizeUrl($input['url']);
        // 配置、导入和绑定共用行锁，防止换站点期间写入另一站点的编号。
        LiteConnection::firstOrCreate(['id' => 1], ['url' => '', 'enabled' => false]);
        DB::transaction(function () use ($input, $url) {
            $connection = LiteConnection::whereKey(1)->lockForUpdate()->firstOrFail();
            if ($connection->url !== $url && DB::table('v2_lite_machine')->exists()) {
                throw new ApiException('更换 Lite 站点前，请先解除已有绑定。', 409);
            }
            if (!empty($input['api_key'])) $connection->api_key = $input['api_key'];
            if (!$connection->api_key) throw new ApiException('请填写 Lite 站点 API 密钥。', 422);
            $connection->url = $url;
            $connection->enabled = $input['enabled'];
            $connection->save();
        });
        return $this->view();
    }

    private function active(): LiteConnection
    {
        $connection = $this->connection();
        if (!$connection->enabled || !$connection->api_key) throw new ApiException('请先配置并启用 Lite。', 422);
        return $connection;
    }

    private function cacheKey(LiteConnection $connection, string $kind): string
    {
        return 'lite:' . hash('sha256', $connection->url . '\0' . $connection->api_key) . ':' . $kind;
    }

    private function cached(LiteConnection $connection, string $kind, int $seconds, callable $read): array
    {
        $key = $this->cacheKey($connection, $kind);
        if (($value = Cache::get($key)) !== null) return $value;
        $value = Cache::lock($key . ':lock', 35)->get(function () use ($key, $seconds, $read) {
            if (($value = Cache::get($key)) !== null) return $value;
            $value = $read();
            Cache::put($key, $value, $seconds);
            return $value;
        });
        if ($value !== false) return $value;

        // 资料串行读取两次，其余一次；等待现有读取者，不在锁释放后重复请求上游。
        $deadline = now()->addSeconds($kind === 'nodes' ? 22 : 12);
        do {
            Sleep::usleep(100000);
            if (($value = Cache::get($key)) !== null) return $value;
        } while (now()->lt($deadline));
        throw new ApiException('Lite 正在读取，请稍后重试。', 503);
    }

    public function nodes(?LiteConnection $connection = null): array
    {
        $connection ??= $this->active();
        // 周期边界不能继续使用上一周期的额外额度和用量缓存。
        foreach (Cache::get($this->cacheKey($connection, 'nodes'), []) as $node) {
            $resetAt = self::timestamp($node['traffic_reset_at'] ?? null);
            if ($resetAt !== null && $resetAt <= now()->timestamp) {
                Cache::forget($this->cacheKey($connection, 'nodes'));
                Cache::forget($this->cacheKey($connection, 'status'));
                break;
            }
        }
        return $this->cached($connection, 'nodes', 60, function () use ($connection) {
            $records = $this->client->read($connection, 'admin:listClients');
            $display = $this->client->read($connection, 'common:getNodes');
            $nodes = [];
            foreach ($records as $record) {
                if (!is_array($record) || !Str::isUuid($record['uuid'] ?? '') || isset($nodes[$record['uuid']])) {
                    throw new ApiException('Lite 服务器列表格式不兼容。', 502);
                }
                $uuid = $record['uuid'];
                // 只缓存和返回展示字段，不缓存安装令牌等管理数据。
                $node = ['uuid' => $uuid];
                foreach (['name', 'ipv4', 'ipv6', 'region', 'region_override', 'os', 'arch', 'cpu_name',
                    'kernel_version', 'virtualization', 'group', 'tags', 'public_remark', 'expired_at',
                    'expiry_timezone', 'traffic_limit_type', 'effective_traffic_type', 'traffic_reset_time',
                    'traffic_reset_timezone', 'bandwidth'] as $field) {
                    $node[$field] = is_string($record[$field] ?? null) ? mb_substr($record[$field], 0, 1024) : '';
                }
                foreach (['cpu_cores', 'mem_total', 'swap_total', 'disk_total', 'traffic_limit',
                    'effective_traffic_limit', 'traffic_reset_day'] as $field) {
                    $node[$field] = self::number($record[$field] ?? null);
                }
                $node['traffic_reset_at'] = is_string($display[$uuid]['traffic_reset_at'] ?? null)
                    ? $display[$uuid]['traffic_reset_at'] : null;
                $region = $node['region_override'] ?: $node['region'];
                if (preg_match('/^[\x{1F1E6}-\x{1F1FF}]{2}$/u', $region)) {
                    $region = implode('', array_map(fn ($char) => chr(mb_ord($char) - 0x1F1E6 + 65), mb_str_split($region)));
                }
                $node['country'] = preg_match('/^[a-z]{2}$/i', $region) ? strtoupper($region) : null;
                $nodes[$uuid] = $node;
            }
            return $nodes;
        });
    }

    public static function number(mixed $value): int|float|null
    {
        return is_numeric($value) && is_finite((float) $value) && $value >= 0 && $value <= 9007199254740991
            ? $value + 0 : null;
    }

    private static function ip(mixed $value): ?string
    {
        if (!is_string($value)) return null;
        if (str_starts_with(strtolower($value), '::ffff:')) $value = substr($value, 7);
        return LiteClient::isPublicAddress($value)
            ? bin2hex(inet_pton($value)) : null;
    }

    /** 只有两边都唯一的公网地址交集才能自动绑定；冲突留给管理员选择。 */
    public function candidates(array $nodes, bool $lockMachines = false): array
    {
        $query = ServerMachine::query()->orderBy('id');
        // 绑定事务先锁机器，再建立读取快照，避免心跳更新使外键检查报 1020。
        if ($lockMachines) $query->sharedLock();
        $machines = $query->get(['id', 'name', 'agent_runtime', 'lite_auto_bind']);
        $bound = DB::table('v2_lite_machine')->pluck('uuid', 'machine_id')->all();
        $matches = [];
        $uses = [];
        foreach ($machines as $machine) {
            $runtime = MachineAgentService::runtimeView($machine->agent_runtime) ?? [];
            $ips = array_filter(array_map([self::class, 'ip'], [$runtime['public_ipv4'] ?? null,
                $runtime['public_ipv6'] ?? null]));
            if (!$ips) $ips = array_filter([self::ip($runtime['public_ip'] ?? null)]);
            foreach ($nodes as $uuid => $node) {
                $remoteIps = array_filter(array_map([self::class, 'ip'], [$node['ipv4'], $node['ipv6']]));
                if ($ips && array_intersect($ips, $remoteIps)) {
                    $matches[$machine->id][] = $uuid;
                    $uses[$uuid][] = $machine->id;
                }
            }
        }
        $automatic = [];
        foreach ($matches as $id => $uuids) {
            if ($machines->firstWhere('id', $id)->lite_auto_bind && !isset($bound[$id]) && count($uuids) === 1 && count($uses[$uuids[0]]) === 1
                && !in_array($uuids[0], $bound, true)) $automatic[$id] = $uuids[0];
        }
        return ['automatic' => $automatic, 'candidates' => $matches, 'bindings' => $bound,
            'machines' => $machines->map(fn ($machine) => ['id' => $machine->id, 'name' => $machine->name,
                'binding_status' => isset($bound[$machine->id]) ? 'bound' : (!$machine->lite_auto_bind ? 'paused'
                    : (isset($automatic[$machine->id]) ? 'matched' : (isset($matches[$machine->id]) ? 'conflict' : 'pending')))])->all()];
    }

    public function inventory(): array
    {
        $nodes = $this->nodes();
        return ['nodes' => array_values($nodes)] + $this->candidates($nodes);
    }

    public function bind(array $input): array
    {
        $local = in_array($input['action'], ['unbind', 'resume'], true);
        $connection = $local ? $this->connection() : $this->active();
        $nodes = $local ? [] : $this->nodes($connection);
        try {
            return DB::transaction(function () use ($connection, $nodes, $input, $local) {
                $current = LiteConnection::whereKey(1)->lockForUpdate()->firstOrFail();
                if ((!$local && !$current->enabled) || $current->url !== $connection->url || $current->api_key !== $connection->api_key) {
                    throw new ApiException('Lite 配置已变化，请重新读取。', 409);
                }
                $count = 0;
                $ids = [];
                if ($input['action'] === 'auto') {
                    foreach ($this->candidates($nodes, lockMachines: true)['automatic'] as $id => $uuid) {
                        DB::table('v2_lite_machine')->insert(['machine_id' => $id, 'uuid' => $uuid]);
                        $count++;
                    }
                } elseif ($local) {
                    if (!ServerMachine::whereKey($input['machine_id'])->lockForUpdate()->first(['id'])) {
                        throw new ApiException('目标服务器已不存在，请重新读取。', 409);
                    }
                    ServerMachine::whereKey($input['machine_id'])->update(['lite_auto_bind' => $input['action'] === 'resume']);
                    $count = $input['action'] === 'unbind'
                        ? DB::table('v2_lite_machine')->where('machine_id', $input['machine_id'])->delete() : 1;
                } elseif ($input['action'] === 'bind') {
                    $uuid = $input['uuid'];
                    if (!isset($nodes[$uuid])) throw new ApiException('Lite 服务器已不存在，请重新读取。', 409);
                    if (!ServerMachine::whereKey($input['machine_id'])->lockForUpdate()->first(['id'])) {
                        throw new ApiException('目标服务器已不存在，请重新读取。', 409);
                    }
                    if (DB::table('v2_lite_machine')->where('uuid', $uuid)->where('machine_id', '!=', $input['machine_id'])->exists()) {
                        throw new ApiException('这台 Lite 服务器已经绑定，请先解除原绑定。', 409);
                    }
                    DB::table('v2_lite_machine')->updateOrInsert(['machine_id' => $input['machine_id']], ['uuid' => $uuid]);
                    ServerMachine::whereKey($input['machine_id'])->update(['lite_auto_bind' => true]);
                    $count = 1;
                } else {
                    foreach ($input['uuids'] as $uuid) {
                        if (!isset($nodes[$uuid])) throw new ApiException('Lite 服务器已不存在，请重新读取。', 409);
                        if (DB::table('v2_lite_machine')->where('uuid', $uuid)->exists()) continue;
                        $machine = ServerMachine::create(['name' => mb_substr($nodes[$uuid]['name'] ?: 'Lite server', 0, 255),
                            'token' => ServerMachine::generateToken(), 'is_active' => true, 'traffic_limit' => 0, 'traffic_mode' => 'both']);
                        DB::table('v2_lite_machine')->insert(['machine_id' => $machine->id, 'uuid' => $uuid]);
                        $ids[] = $machine->id;
                        $count++;
                    }
                }
                return ['count' => $count, 'created_ids' => $ids];
            }, 3);
        } catch (QueryException $error) {
            if (!(new ConcurrencyErrorDetector())->causedByConcurrencyError($error)) throw $error;
            throw new ApiException('Lite 绑定遇到并发更新，请稍后重试。', 503);
        }
    }

    public function snapshot(): array
    {
        $connection = $this->connection();
        if (!$connection->enabled) return ['enabled' => false, 'machines' => (object) []];
        $bindings = DB::table('v2_lite_machine')->pluck('uuid', 'machine_id');
        return $this->boundSnapshot($connection, $bindings->all());
    }

    /** 调度只读取池中已绑定的机器；失败保留绑定标记，不回退成本地额度。 */
    public function schedulingSnapshot(array $machineIds): array
    {
        $connection = $this->connection();
        if (!$connection->enabled || !$machineIds) return [];
        $bindings = DB::table('v2_lite_machine')->whereIn('machine_id', $machineIds)->pluck('uuid', 'machine_id')->all();
        if (!$bindings) return [];
        $failed = $this->cacheKey($connection, 'scheduling_error');
        if (Cache::has($failed)) return array_fill_keys(array_keys($bindings), ['error' => true]);
        try {
            return (array) $this->boundSnapshot($connection, $bindings)['machines'];
        } catch (ApiException) {
            Cache::put($failed, true, 10);
            return array_fill_keys(array_keys($bindings), ['error' => true]);
        }
    }

    /** 后台重试与手动操作共用同一套锁、唯一性检查和事务。 */
    public function autoBind(): int
    {
        $connection = $this->connection();
        if (!$connection->enabled || !$connection->api_key
            || !ServerMachine::where('lite_auto_bind', true)->whereNotIn('id', DB::table('v2_lite_machine')->select('machine_id'))->exists()) return 0;
        return $this->bind(['action' => 'auto'])['count'];
    }

    private function boundSnapshot(LiteConnection $connection, array $bindings): array
    {
        if (!$bindings) return ['enabled' => true, 'machines' => (object) []];
        $nodes = $this->nodes($connection);
        $statuses = $this->cached($connection, 'status', 10,
            fn () => $this->client->read($connection, 'common:getNodesLatestStatus', ['compact' => true]));
        $machines = [];
        foreach ($bindings as $id => $uuid) {
            $node = $nodes[$uuid] ?? null;
            $state = is_array($statuses[$uuid] ?? null) ? $statuses[$uuid] : [];
            $time = self::timestamp($state['time'] ?? null);
            $fresh = $time && $time >= now()->timestamp - 180 && $time <= now()->timestamp + 60;
            $online = is_bool($state['online'] ?? null) ? $state['online'] : null;
            $load = $fresh && $online === true ? self::load($state) : null;
            $traffic = null;
            if ($node) {
                $up = self::number($state['net_total_up'] ?? null);
                $down = self::number($state['net_total_down'] ?? null);
                $mode = $node['effective_traffic_type'] ?: $node['traffic_limit_type'];
                $used = $up !== null && $down !== null ? match ($mode) {
                    'up' => $up, 'down' => $down, 'sum' => min(9007199254740991, $up + $down),
                    'max' => max($up, $down), 'min' => min($up, $down), default => null,
                } : null;
                $traffic = ['used' => $used, 'up' => $up, 'down' => $down,
                    'limit' => $node['effective_traffic_limit'] ?? $node['traffic_limit'], 'mode' => $mode,
                    'reset_day' => $node['traffic_reset_day'], 'reset_time' => $node['traffic_reset_time'],
                    'timezone' => $node['traffic_reset_timezone'], 'reset_at' => $node['traffic_reset_at']];
            }
            $machines[$id] = ['uuid' => $uuid, 'info' => $node, 'online' => $online,
                'updated_at' => $time, 'fresh' => (bool) $fresh, 'load_status' => $load, 'traffic' => $traffic,
                'uptime' => self::number($state['uptime'] ?? null), 'missing' => !$node];
        }
        return ['enabled' => true, 'machines' => (object) $machines];
    }

    private static function timestamp(mixed $value): ?int
    {
        return is_string($value) && ($time = strtotime($value)) && $time > 0 ? $time : null;
    }

    private static function load(array $state): ?array
    {
        foreach (['cpu', 'ram', 'ram_total', 'disk', 'disk_total'] as $field) {
            if (self::number($state[$field] ?? null) === null) return null;
        }
        return ['cpu' => min(100, $state['cpu'] + 0),
            'mem' => ['used' => $state['ram'] + 0, 'total' => $state['ram_total'] + 0],
            'disk' => ['used' => $state['disk'] + 0, 'total' => $state['disk_total'] + 0],
            'swap' => ['used' => self::number($state['swap'] ?? null), 'total' => self::number($state['swap_total'] ?? null)],
            'net' => self::number($state['net_in'] ?? null) !== null && self::number($state['net_out'] ?? null) !== null
                ? ['in_speed' => $state['net_in'] + 0, 'out_speed' => $state['net_out'] + 0] : null,
            'updated_at' => self::timestamp($state['time'] ?? null)];
    }

    public function history(int $machineId, int $hours, int $limit): array
    {
        $connection = $this->active();
        $uuid = DB::table('v2_lite_machine')->where('machine_id', $machineId)->value('uuid');
        if (!$uuid) throw new ApiException('该服务器未绑定 Lite。', 422);
        return $this->cached($connection, "history:{$uuid}:{$hours}:{$limit}", 30, function () use ($connection, $uuid, $hours, $limit) {
            $result = $this->client->read($connection, 'common:getRecords',
                ['uuid' => $uuid, 'type' => 'load', 'load_type' => 'all', 'hours' => $hours, 'maxCount' => $limit]);
            $records = $result['records'][$uuid] ?? [];
            if (!is_array($records)) throw new ApiException('Lite 历史数据格式不兼容。', 502);
            $history = [];
            foreach (array_slice($records, 0, $limit) as $record) {
                $load = is_array($record) ? self::load($record) : null;
                if (!$load || !$load['updated_at'] || $load['updated_at'] < time() - $hours * 3600) continue;
                $history[] = ['cpu' => $load['cpu'], 'mem_total' => $load['mem']['total'], 'mem_used' => $load['mem']['used'],
                    'disk_total' => $load['disk']['total'], 'disk_used' => $load['disk']['used'],
                    'net_in_speed' => $load['net']['in_speed'] ?? null, 'net_out_speed' => $load['net']['out_speed'] ?? null,
                    'recorded_at' => $load['updated_at']];
            }
            usort($history, fn ($a, $b) => $a['recorded_at'] <=> $b['recorded_at']);
            return $history;
        });
    }
}
