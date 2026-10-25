<?php

namespace App\Services;

use App\Exceptions\DdnsSyncException;
use App\Exceptions\DdnsSyncDeferred;
use App\Jobs\DdnsSyncJob;
use App\Models\BalancerDnsPreset;
use App\Models\BalancerPool;
use App\Models\DdnsAlias;
use App\Models\DdnsBinding;
use App\Models\DdnsRecord;
use App\Models\Server;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class DdnsService
{
    public function __construct(private readonly CloudflareDnsClient $cloudflare) {}

    public static function installed(): bool
    {
        return Schema::hasTable('v2_ddns_binding');
    }

    public static function notify(): void
    {
        if (self::installed() && DdnsBinding::exists()) DB::afterCommit(fn () => DdnsSyncJob::dispatch());
    }

    public static function current(string $type, int $id): ?DdnsBinding
    {
        if (!self::installed()) return null;
        return DdnsBinding::where('owner_type', $type)->where('owner_id', $id)->where('retired', false)->first();
    }

    public static function view(string $type, int $id): array
    {
        $binding = self::current($type, $id);
        return $binding ? ['enabled' => $binding->enabled, 'preset_id' => $binding->preset_id, 'prefix' => $binding->prefix,
            'domain' => $binding->domain, 'status' => $binding->error ? 'error' : ($binding->synced_at && $binding->fingerprint ? 'synced' : 'pending'),
            'error' => $binding->error, 'synced_at' => $binding->synced_at?->toIso8601String()]
            : ['enabled' => false, 'preset_id' => null, 'prefix' => ''];
    }

    /** 绑定单独保存，节点复制不会继承域名或远端记录编号。 */
    public function configure(string $type, Server|BalancerPool $owner, ?array $data): void
    {
        if ($data === null) return;
        $current = self::current($type, $owner->id);
        if (!($data['enabled'] ?? false)) {
            $current?->update(['enabled' => false, 'fingerprint' => null]);
            self::notify();
            return;
        }
        if ($type === 'node' && ($owner->isRelayChild() || $owner->balancer_pool_id)) {
            $this->invalid('enabled', '请在前置入口或统一调度入口设置 DDNS');
        }
        $address = $type === 'node' ? $owner->host : $owner->gateway_address;
        if (!filter_var($address, FILTER_VALIDATE_IP)) $this->invalid('enabled', '开启 DDNS 时，连接地址必须手工填写 IPv4 或 IPv6');
        $this->validateTls($type === 'node' ? $owner->toArray() : ($owner->template_config ?? []));
        $preset = BalancerDnsPreset::query()->whereKey($data['preset_id'] ?? 0)->lockForUpdate()->first();
        if (!$preset) $this->invalid('preset_id', '请选择系统中预设的域名配置');
        $prefix = strtolower(trim((string) ($data['prefix'] ?? '')));
        if (!preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/', $prefix)) {
            $this->invalid('prefix', '前缀只允许字母、数字和中划线，长度为 1 至 63');
        }
        $domain = $prefix . '.' . $preset->domain;
        if (strlen($domain) > 220) $this->invalid('prefix', '域名过长，无法为用户生成独立子域名');
        $existing = DdnsBinding::where('domain', $domain)->lockForUpdate()->first();
        if ($existing && ($existing->owner_type !== $type || (int) $existing->owner_id !== (int) $owner->id
            || (int) $existing->preset_id !== (int) $preset->id || $existing->cleanup_requested)) {
            $this->invalid('prefix', '该域名前缀已被使用或仍在清理，请选择其他前缀');
        }
        foreach (DdnsBinding::where('id', '!=', $existing?->id ?? 0)->pluck('domain') as $other) {
            if (str_ends_with($domain, '.' . $other) || str_ends_with($other, '.' . $domain)) {
                $this->invalid('prefix', '该域名与已有 DDNS 的管理范围重叠');
            }
        }
        if (BalancerPool::where('domain', $domain)->exists()) $this->invalid('prefix', '该域名已用于前置池，请为个人 DDNS 使用独立前缀');
        if ($current && $current->id !== $existing?->id) $current->update(['enabled' => false, 'retired' => true, 'fingerprint' => null]);
        $binding = $existing ?? new DdnsBinding(['owner_type' => $type, 'owner_id' => $owner->id,
            'preset_id' => $preset->id, 'prefix' => $prefix, 'domain' => $domain]);
        $binding->fill(['enabled' => true, 'retired' => false, 'fingerprint' => null, 'error' => null])->save();
        self::notify();
    }

    private function validateTls(array $server): void
    {
        $settings = $server['protocol_settings'] ?? [];
        if (in_array($server['type'] ?? '', ['hysteria', 'tuic', 'anytls'], true)) {
            if (empty($settings['tls']['server_name'])) $this->invalid('enabled', '开启 DDNS 前请明确填写 TLS 证书域名');
            return;
        }
        $tls = (int) ($settings['tls'] ?? 0);
        $required = in_array($server['type'] ?? '', ['trojan', 'naive'], true) || $tls === 1;
        if ($tls === 2) {
            if (empty($settings['reality_settings']['server_name'])) $this->invalid('enabled', '开启 DDNS 前请填写 Reality 握手域名');
        } elseif ($required && empty($settings['tls_settings']['server_name'])) {
            $this->invalid('enabled', '开启 DDNS 前请明确填写 TLS 证书域名');
        }
    }

    /** 这里计算客户端入口，落地和池成员的内部地址不参与用户解析。 */
    public static function endpoint(array $server): array
    {
        if (!empty($server['relay_pool_id'])) return ['pool', (int) $server['relay_pool_id']];
        return ['node', (int) (($server['relay_entry_id'] ?? null) ?: ($server['id'] ?? 0))];
    }

    public function project(array $servers, User|array $user): array
    {
        if (!self::installed() || !DdnsBinding::where('enabled', true)->where('retired', false)->exists()) return $servers;
        foreach ($servers as &$server) {
            [$type, $id] = self::endpoint($server);
            $binding = self::current($type, $id);
            if (!$binding?->enabled) continue;
            // 兼容订阅渲染器的数组输入；涉及个人域名时，只信任数据库中的最新资格。
            if (is_array($user)) $user = User::find((int) ($user['id'] ?? 0));
            if (!$user || $user->banned || $user->u + $user->d >= $user->transfer_enable
                || ($user->expired_at !== null && $user->expired_at <= time())
                || !array_intersect($user->effectiveGroupIds(), $this->groupIds($binding))) {
                throw new HttpResponseException(response('当前用户没有该节点的使用权限', 403, ['Cache-Control' => 'private, no-store']));
            }
            $alias = DdnsAlias::where('binding_id', $binding->id)->where('user_id', $user->id)->first();
            $record = $alias ? DdnsRecord::where('binding_id', $binding->id)->where('alias_id', $alias->id)->whereNotNull('record_id')->first() : null;
            $baseReady = DdnsRecord::where('binding_id', $binding->id)->where('name', $binding->domain)
                ->whereNull('alias_id')->whereNotNull('record_id')->exists();
            if (!$record || !$baseReady || !$binding->synced_at) {
                self::notify();
                throw new HttpResponseException(response('节点域名正在准备，请稍后更新订阅', 503,
                    ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'private, no-store', 'Retry-After' => '30']));
            }
            $server['host'] = $alias->label . '.' . $binding->domain;
        }
        unset($server);
        return $servers;
    }

    private function owner(DdnsBinding $binding): Server|BalancerPool|null
    {
        return $binding->owner_type === 'node' ? Server::find($binding->owner_id) : BalancerPool::find($binding->owner_id);
    }

    private function groupIds(DdnsBinding $binding): array
    {
        $owner = $this->owner($binding);
        if (!$owner || $owner->enabled === false || $binding->cleanup_requested) return [];
        if ($binding->owner_type === 'node') {
            if ($owner->isRelayChild() || $owner->balancer_pool_id) return [];
            $routes = Server::where('relay_entry_id', $owner->id)->whereNull('relay_pool_id')->get()->prepend($owner);
        } else {
            $routes = Server::where('relay_pool_id', $owner->id)->get();
        }
        return $routes->filter(fn ($node) => $node->enabled !== false)->flatMap(fn ($node) => $node->group_ids ?? [])
            ->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    private function eligibleUsers(DdnsBinding $binding): Collection
    {
        $groups = $this->groupIds($binding);
        if (!$groups) return collect();
        return User::where('banned', 0)->whereRaw('u + d < transfer_enable')
            ->where(fn ($q) => $q->whereNull('expired_at')->orWhere('expired_at', '>', time()))
            ->where(function ($q) use ($groups) {
                $q->whereIn('group_id', $groups);
                foreach ($groups as $group) $q->orWhereJsonContains('group_ids', $group)->orWhereJsonContains('group_ids', (string) $group);
            })->get(['id', 'group_id', 'group_ids', 'expired_at'])
            ->filter(fn ($user) => array_intersect($user->effectiveGroupIds(), $groups) !== []);
    }

    public function plannedRecords(DdnsBinding $binding): int
    {
        $owner = $this->owner($binding);
        if (!$owner || $owner->enabled === false || $binding->cleanup_requested) return 0;
        if ($binding->owner_type === 'node' && ($owner->isRelayChild() || $owner->balancer_pool_id)) return 0;
        $ids = $this->eligibleUsers($binding)->pluck('id')->all();
        return 1 + ($binding->enabled && !$binding->retired ? count($ids)
            : DdnsAlias::where('binding_id', $binding->id)->whereIn('user_id', $ids)->count());
    }

    public function synchronize(int $budgetSeconds = 180): bool
    {
        if (!self::installed()) return true;
        $lock = Cache::lock('ddns:sync', 300);
        if (!$lock->get()) return true;
        $this->cloudflare->reset();
        // 本轮处理和远端请求共用截止时间，为退出和释放同步锁留出余量。
        $this->cloudflare->setDeadline(microtime(true) + max(0, min(180, $budgetSeconds)));
        $success = true;
        $cursor = (int) Cache::get('ddns:sync:cursor', 0);
        try {
            $bindings = DdnsBinding::orderByRaw('CASE WHEN id > ? THEN 0 ELSE 1 END', [$cursor])->orderBy('id')->get();
            foreach ($bindings as $binding) {
                try {
                    $this->cloudflare->checkpoint();
                } catch (DdnsSyncDeferred) {
                    $success = false;
                    break;
                }
                try {
                    $this->syncBinding($binding->fresh());
                } catch (DdnsSyncDeferred $error) {
                    $binding->update(['fingerprint' => null, 'error' => $error->getMessage()]);
                    $success = false;
                    break;
                } catch (\Throwable $error) {
                    $success = false;
                    $binding->update(['error' => $error instanceof DdnsSyncException ? $error->getMessage() : 'DDNS 同步失败，稍后自动重试']);
                } finally {
                    // 下轮先服务后面的入口，已写入的批次通过远端归属和本地意图继续核对。
                    Cache::put('ddns:sync:cursor', $binding->id, 3600);
                }
            }
        } finally {
            $this->cloudflare->setDeadline(null);
            $lock->release();
        }
        return $success;
    }

    private function syncBinding(DdnsBinding $binding): void
    {
        $preset = $binding->preset;
        if (!$preset) throw new DdnsSyncException('域名预设不存在，无法同步或清理记录');
        $owner = $this->owner($binding);
        $cleanup = $binding->cleanup_requested || !$owner;
        $users = $this->eligibleUsers($binding);
        $desired = [];
        $addressError = false;
        $publicEntry = $owner && ($binding->owner_type === 'pool' || (!$owner->isRelayChild() && !$owner->balancer_pool_id));
        if (!$cleanup && $owner->enabled !== false && $publicEntry) {
            $address = $binding->owner_type === 'node' ? $owner->host : $owner->gateway_address;
            $addressError = !filter_var($address, FILTER_VALIDATE_IP);
            $desired[$binding->domain] = ['alias_id' => null, 'type' => str_contains($address, ':') ? 'AAAA' : 'A', 'content' => $address];
            $aliases = DdnsAlias::where('binding_id', $binding->id)->get()->keyBy('user_id');
            if (!$addressError && $binding->enabled && !$binding->retired) {
                $missing = [];
                foreach ($users as $user) {
                    $this->cloudflare->checkpoint();
                    if (!$aliases->has($user->id)) $missing[] = ['binding_id' => $binding->id, 'user_id' => $user->id, 'label' => bin2hex(random_bytes(16))];
                }
                foreach (array_chunk($missing, 100) as $chunk) {
                    $this->cloudflare->checkpoint();
                    DdnsAlias::insert($chunk);
                }
                if ($missing) $aliases = DdnsAlias::where('binding_id', $binding->id)->get()->keyBy('user_id');
            }
            $ids = array_fill_keys($users->pluck('id')->all(), true);
            foreach ($aliases as $userId => $alias) {
                $this->cloudflare->checkpoint();
                if (!isset($ids[$userId])) continue;
                $desired[$alias->label . '.' . $binding->domain] = ['alias_id' => $alias->id, 'type' => 'CNAME', 'content' => $binding->domain];
            }
        }
        ksort($desired);
        $fingerprint = hash('sha256', json_encode($desired, JSON_THROW_ON_ERROR));
        if (!$cleanup && !$addressError && !$binding->error && $binding->fingerprint === $fingerprint
            && $binding->synced_at?->gt(now()->subMinutes(10))) return;

        // 同一轮按区域共用分页快照，避免为每名用户重复读取整个区域。
        $remote = $this->cloudflare->records($preset);
        $stored = DdnsRecord::where('binding_id', $binding->id)->get()->keyBy('name');
        // 先清除已经确认失效的编号；即使后续额度或其他记录冲突，也不能继续下发它。
        foreach ($stored as $name => $record) {
            $this->cloudflare->checkpoint();
            if (!$record->record_id) continue;
            if (!isset($remote[$record->record_id])) {
                $record->record_id = null;
                $record->save();
            } elseif (strtolower(rtrim($remote[$record->record_id]['name'], '.')) !== $name
                || ($remote[$record->record_id]['comment'] ?? '') !== $record->marker) {
                // 编号仍存在但用途已改变，保留审计线索并停止下发，不能丢掉归属后误清理。
                $binding->update(['synced_at' => null]);
                throw new DdnsSyncException('托管记录归属发生变化，请检查后重试');
            }
        }
        $deletes = [];
        foreach ($stored as $name => $record) {
            $this->cloudflare->checkpoint();
            if (isset($desired[$name])) continue;
            $matches = $this->cloudflare->recordsNamed($preset, $name);
            $owned = array_filter($matches, fn ($r) => ($r['comment'] ?? '') === $record->marker);
            if ($matches && !$owned) {
                throw new DdnsSyncException('托管记录归属发生变化，请检查后重试');
            }
            foreach ($owned as $r) $deletes[] = ['record' => $record, 'remote' => $r];
            if (!$owned) $record->delete();
        }
        foreach (array_chunk($deletes, 100) as $chunk) {
            $this->cloudflare->checkpoint();
            $this->cloudflare->request($preset, 'post', '/dns_records/batch', ['deletes' => array_map(fn ($item) => ['id' => $item['remote']['id']], $chunk)]);
            foreach ($chunk as $item) {
                $this->cloudflare->forget($preset, $item['remote']['id']);
                $item['record']->delete();
            }
        }
        if ($cleanup) {
            DdnsAlias::where('binding_id', $binding->id)->delete();
            $binding->delete();
            return;
        }
        // 保留旧解析期间允许原节点改用域名，但失效用户的撤销必须先执行。
        if ($addressError) throw new DdnsSyncException('节点连接地址需要手工填写有效 IP；失效用户解析已撤销');

        $remote = $this->cloudflare->records($preset);
        foreach ($remote as $r) {
            $this->cloudflare->checkpoint();
            $name = strtolower($r['name']);
            // 用户名称被删除后也不能由泛解析继续得到地址。
            if ($name === '*.' . $binding->domain || ($r['type'] === 'NS'
                && ($name === $binding->domain || str_ends_with($binding->domain, '.' . $name)))) {
                throw new DdnsSyncException('DDNS 域名存在泛解析或子域委托冲突，请先处理');
            }
        }
        $missing = [];
        foreach ($desired as $name => $value) {
            $this->cloudflare->checkpoint();
            if (!$stored->has($name)) $missing[] = ['name' => $name, 'binding_id' => $binding->id,
                ...$value, 'marker' => 'YZDDNS:' . bin2hex(random_bytes(16))];
        }
        foreach (array_chunk($missing, 100) as $chunk) {
            $this->cloudflare->checkpoint();
            DdnsRecord::insert($chunk);
        }
        if ($missing) $stored = DdnsRecord::where('binding_id', $binding->id)->get()->keyBy('name');
        $actions = [];
        foreach ($desired as $name => $value) {
            $this->cloudflare->checkpoint();
            $record = $stored->get($name);
            if ((int) $record->binding_id !== (int) $binding->id) throw new DdnsSyncException('DNS 记录已被其他绑定使用');
            $matches = $this->cloudflare->recordsNamed($preset, $name);
            foreach ($matches as $r) {
                if (($r['comment'] ?? '') !== $record->marker) throw new DdnsSyncException('入口或用户域名已有其他用途的记录，请更换前缀');
            }
            $existing = $matches ? reset($matches) : null;
            if (count($matches) > 1) throw new DdnsSyncException('托管域名存在重复记录，请检查后重试');
            $record->fill($value);
            $record->record_id = $existing['id'] ?? null;
            $record->save();
            $content = $existing['content'] ?? null;
            $equal = $value['type'] === 'CNAME' ? strtolower(rtrim((string) $content, '.')) === $value['content']
                : ($content && @inet_pton($content) === @inet_pton($value['content']));
            if ($existing && $existing['type'] === $value['type'] && $equal
                && !($existing['proxied'] ?? false) && ($existing['ttl'] ?? 0) === 60) continue;
            $payload = ['name' => $name, 'type' => $value['type'], 'content' => $value['content'],
                'proxied' => false, 'ttl' => 60, 'comment' => $record->marker];
            if ($existing) $payload['id'] = $existing['id'];
            $actions[] = ['operation' => $existing ? 'puts' : 'posts', 'payload' => $payload, 'record' => $record];
        }
        // 更新不消耗新增额度；先完成已有解析的 IP 修正，再检查新用户记录容量。
        $updates = array_values(array_filter($actions, fn ($action) => $action['operation'] === 'puts'));
        $creates = array_values(array_filter($actions, fn ($action) => $action['operation'] === 'posts'));
        $this->applyActions($preset, $updates);
        $newCount = count($creates);
        if ($newCount) {
            $usage = $this->cloudflare->usage($preset);
            if ($usage['quota'] !== null && $usage['usage'] + $newCount > (int) $usage['quota']) {
                throw new DdnsSyncException('Cloudflare DNS 额度不足：需要新增 ' . $newCount . ' 条，请检查系统设置中的记录容量');
            }
        }
        $this->applyActions($preset, $creates);
        $binding->update(['fingerprint' => $fingerprint, 'synced_at' => now(), 'error' => null]);
    }

    private function applyActions(BalancerDnsPreset $preset, array $actions): void
    {
        foreach (array_chunk($actions, 100) as $chunk) {
            $this->cloudflare->checkpoint();
            $body = [];
            foreach ($chunk as $action) $body[$action['operation']][] = $action['payload'];
            $result = $this->cloudflare->request($preset, 'post', '/dns_records/batch', $body)['result'];
            $completed = collect(array_merge($result['puts'] ?? [], $result['posts'] ?? []))->keyBy('name');
            foreach ($chunk as $action) {
                $r = $completed->get($action['record']->name);
                if (!$r || empty($r['id']) || ($r['comment'] ?? '') !== $action['record']->marker) {
                    throw new DdnsSyncException('Cloudflare 未返回完整记录，稍后核对后自动重试');
                }
                $action['record']->update(['record_id' => $r['id']]);
                $this->cloudflare->remember($preset, $r);
            }
        }
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages(['ddns.' . $field => $message]);
    }
}
