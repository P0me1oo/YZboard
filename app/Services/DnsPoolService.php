<?php

namespace App\Services;

use App\Models\BalancerPool;
use App\Models\PoolDnsAlias;
use App\Models\PowerDnsRecord;
use App\Models\Server;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;

class DnsPoolService
{
    public static function addresses(Server $source): array
    {
        $host = strtolower(rtrim(trim((string) $source->host, " \t\n\r\0\x0B[]"), '.'));
        // 连接入口由节点配置决定；机器上报地址可能是 NAT 后的其他地址。
        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host]
            : (filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) && str_contains($host, '.')
                ? app(self::class)->resolveDomain($host) : []);
        $addresses = array_values(array_filter($addresses, fn ($v) => is_string($v) && filter_var($v, FILTER_VALIDATE_IP)
            && !in_array($v, ['0.0.0.0', '::'], true)));
        return array_values(array_unique($addresses));
    }

    public function resolveDomain(string $host): array
    {
        $visited = [];
        for ($depth = 0; $depth < 8 && !isset($visited[$host]); $depth++) {
            $visited[$host] = true;
            $records = $this->domainRecords($host);
            $addresses = []; $target = null;
            foreach ($records as $record) {
                if (strtolower(rtrim($record['host'] ?? '', '.')) !== $host) continue;
                if (isset($record['ip'])) $addresses[] = $record['ip'];
                if (isset($record['ipv6'])) $addresses[] = $record['ipv6'];
                if (isset($record['target'])) $target = strtolower(rtrim($record['target'], '.'));
            }
            if ($addresses) return $addresses;
            if (!$target || !filter_var($target, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) break;
            $host = $target;
        }
        return [];
    }

    protected function domainRecords(string $host): array
    {
        return @dns_get_record($host, DNS_A | DNS_AAAA | DNS_CNAME) ?: [];
    }

    private function users(BalancerPool $pool): Collection
    {
        $groups = Server::where('relay_pool_id', $pool->id)->where(fn ($q) => $q->where('enabled', true)->orWhereNull('enabled'))->get()->flatMap(fn ($s) => $s->group_ids ?? [])
            ->map(fn ($id) => (int) $id)->unique()->values()->all();
        if (!$groups) return collect();
        return User::query()->when($pool->follow_user_status, fn ($q) => $q->where('banned', false)->whereRaw('u + d < transfer_enable')
            ->where(fn ($q) => $q->whereNull('expired_at')->orWhere('expired_at', '>', time())))
            ->where(function ($q) use ($groups) {
                $q->whereIn('group_id', $groups);
                foreach ($groups as $group) $q->orWhereJsonContains('group_ids', $group)->orWhereJsonContains('group_ids', (string) $group);
            })->get(['id', 'group_id', 'group_ids'])->filter(fn ($u) => array_intersect($groups, $u->effectiveGroupIds()));
    }

    public function sync(BalancerPool $pool): void
    {
        $pool->load(['dnsPreset', 'members.sourceNode.machine', 'members.runtimeNode.machine']);
        if (!$pool->dnsPreset || $pool->dnsPreset->provider !== 'powerdns') throw new \RuntimeException('缺少 PowerDNS 配置');
        [$addresses, $state] = app(FrontPoolScheduler::class)->select($pool);
        $config = array_replace(FrontPoolScheduler::DEFAULTS, $pool->scheduling_config ?? []);
        $carrier = app(CarrierDns::class);
        $data = $config['carrier_enabled'] && config('dns.carrier_ready') ? $carrier->dataset() : null;
        $state['carrier'] = $config['carrier_enabled'] ? $carrier->status() : null;
        $desired = [];
        $names = [];
        $available = $config['health_enabled'] || $config['carrier_enabled'] ? $state['available_candidates'] : $state['candidates'];
        $routes = $config['health_enabled'] ? Server::with('machine')->where('relay_pool_id', $pool->id)->get() : collect();
        $scopes = [0 => $state['candidates']];
        foreach ($routes as $route) $scopes[$route->id] = FrontRouteHealth::select($pool, $route, $available);
        if ($config['health_enabled'] && $routes->isNotEmpty()) {
            // 老订阅没有落地维度，只保留所有启用线路都可用的入口；新订阅各自独立。
            $common = array_column($available, 'member_id');
            foreach ($routes->where('enabled', '!==', false) as $route) $common = array_intersect($common, array_column($scopes[$route->id], 'member_id'));
            $scopes[0] = array_values(array_filter($available, fn ($c) => in_array($c['member_id'], $common, true)));
        } elseif ($config['carrier_enabled']) $scopes[0] = $available;
        $state['routes'] = array_map(fn ($candidates) => array_column($candidates, 'member_id'), $scopes);
        $optionsByScope = [];
        $state['route_policies'] = $pool->scheduling_state['route_policies'] ?? [];
        foreach ($scopes as $routeId => $candidates) {
            foreach ($config['carrier_enabled'] ? ['default', ...CarrierDns::CARRIERS] : ['default'] as $lane) {
                $options = FrontRouteHealth::eligible($config['carrier_enabled'] ? CarrierDns::candidates($candidates, $lane) : $candidates, $config);
                $policyState = $state['route_policies'][$routeId][$lane] ?? [];
                $optionsByScope[$routeId][$lane] = $routeId ? FrontRouteHealth::policy($options, $config, $policyState) : $options;
                $state['route_policies'][$routeId][$lane] = $policyState;
            }
        }
        foreach ($available as $candidate) {
            $member = $pool->members->firstWhere('id', $candidate['member_id']);
            if (!$member->dns_label) $member->update(['dns_label' => bin2hex(random_bytes(12))]);
            $name = 'entry-' . $member->dns_label . '.' . $pool->domain;
            $names[$member->id] = $name;
            foreach (['A', 'AAAA'] as $type) {
                $ips = array_values(array_filter($candidate['addresses'], fn ($ip) => (str_contains($ip, ':') ? 'AAAA' : 'A') === $type));
                if ($ips) $desired[] = ['name' => $name, 'type' => $type, 'contents' => $ips];
            }
        }
        // 池域名保留候选地址，供尚未更新订阅的客户端过渡；新订阅使用个人域名。
        $legacy = $optionsByScope[0]['default'];
        $addresses = array_values(array_unique(array_merge([], ...array_column($legacy, 'addresses'))));
        foreach (['A', 'AAAA'] as $type) {
            $values = array_values(array_unique(array_filter($addresses, fn ($ip) => (str_contains($ip, ':') ? 'AAAA' : 'A') === $type)));
            if ($values) $desired[] = ['name' => $pool->domain, 'type' => $type, 'contents' => $values];
        }
        $assignments = [];
        if ($pool->enabled) {
            $aliases = PoolDnsAlias::where('pool_id', $pool->id)->get()->keyBy('user_id');
            $users = $this->users($pool);
            $state['rebalance_at'] = $pool->scheduling_state['rebalance_at'] ?? 0;
            $moveBudget = now()->timestamp - $state['rebalance_at'] >= 60 ? max(1, (int) ceil($users->count() * 0.1)) : 0;
            $initialBudget = $moveBudget;
            foreach ($users as $user) {
                $alias = $aliases->get($user->id) ?? PoolDnsAlias::create(['pool_id' => $pool->id, 'user_id' => $user->id, 'label' => bin2hex(random_bytes(16))]);
                $aliases->put($user->id, $alias);
                $next = [];
                foreach ($scopes as $routeId => $candidates) {
                    if ($routeId && !array_intersect($routes->firstWhere('id', $routeId)->group_ids ?? [], $user->effectiveGroupIds())) continue;
                    $label = self::label($alias, (int) $routeId);
                    $targets = [];
                    foreach ($config['carrier_enabled'] ? ['default', ...CarrierDns::CARRIERS] : ['default'] as $lane) {
                        $options = $optionsByScope[$routeId][$lane];
                        $previous = $alias->assignment_state[$routeId][$lane] ?? ($routeId === 0 && $lane === 'default' ? ['member_id' => $alias->member_id] : []);
                        $choice = FrontDynamicLoad::choose($label . ($lane === 'default' ? '' : ':' . $lane), $options, $previous, $config, $moveBudget);
                        $next[$routeId][$lane] = $choice;
                        if ($choice['member_id'] !== null) $targets[$lane] = $names[$choice['member_id']] . '.';
                    }
                    if (isset($targets['default'])) $desired[] = CarrierDns::alias($label . '.' . $pool->domain, $pool->domain, $targets, $data !== null, $pool->dnsPreset->domain);
                }
                $assignments[$alias->id] = $next;
            }
            if ($data && $users->isNotEmpty()) $desired = array_merge($desired, $carrier->records($pool->domain, $data, $pool->dnsPreset->domain));
            if ($moveBudget < $initialBudget) $state['rebalance_at'] = now()->timestamp;
        }
        app(PowerDnsService::class)->reconcile($pool->dnsPreset, 'pool', $pool->id, $desired, $pool->dns_ttl);
        // 只有外部写入已确认后才保存分配；失败时保留上次结果，标签始终不变。
        $aliases ??= PoolDnsAlias::where('pool_id', $pool->id)->get()->keyBy('user_id');
        foreach ($aliases as $alias) {
            $next = $assignments[$alias->id] ?? [];
            $alias->fill(['member_id' => $next[0]['default']['member_id'] ?? null, 'assignment_state' => $next]);
            if ($alias->isDirty()) $alias->save();
        }
        $state['snapshot']['routes'] = $state['routes'];
        $state['snapshot']['assigned'] = [];
        foreach ($assignments as $scopes) foreach ($scopes as $routeId => $lanes) foreach ($lanes as $lane => $choice) {
            $id = $choice['member_id'];
            if ($id !== null) $state['snapshot']['assigned'][$routeId][$lane][$id] = ($state['snapshot']['assigned'][$routeId][$lane][$id] ?? 0) + 1;
        }
        $previous = $pool->scheduling_state['snapshot'] ?? [];
        SchedulingEvents::record('front', $pool->id, $pool->name, 'selection_changed', $previous, $state['snapshot']);
        $pool->update(['scheduling_state' => $state]);
    }

    public static function label(PoolDnsAlias $alias, int $routeId): string
    {
        return $routeId ? substr(hash('sha256', $alias->label . ':route:' . $routeId), 0, 32) : $alias->label;
    }

    public function project(BalancerPool $pool, User|array $user, int $routeId = 0): string
    {
        $userId = (int) (is_array($user) ? ($user['id'] ?? 0) : $user->id);
        $alias = PoolDnsAlias::where('pool_id', $pool->id)->where('user_id', $userId)->first();
        $current = User::find($userId);
        if (!$current || ($pool->follow_user_status && ($current->banned || $current->u + $current->d >= $current->transfer_enable
            || ($current->expired_at !== null && $current->expired_at <= time())))) {
            throw new HttpResponseException(response('当前用户没有该节点的使用权限', 403, ['Cache-Control' => 'private, no-store']));
        }
        if (!($pool->scheduling_config['health_enabled'] ?? false)) $routeId = 0;
        $name = $alias ? self::label($alias, $routeId) . '.' . $pool->domain : null;
        $memberId = $routeId ? ($alias?->assignment_state[$routeId]['default']['member_id'] ?? null) : $alias?->member_id;
        $member = $memberId ? $pool->members()->find($memberId) : null;
        $target = $member?->dns_label ? 'entry-' . $member->dns_label . '.' . $pool->domain : null;
        $record = $name ? PowerDnsRecord::where('owner_type', 'pool')->where('owner_id', $pool->id)
            ->where('name', $name)->whereIn('type', ['CNAME', 'LUA'])->where('confirmed', true)->first() : null;
        $ready = $pool->enabled && $target && $record && ($record->type === 'LUA' || $record->contents === [$target . '.'])
            && PowerDnsRecord::where('owner_type', 'pool')->where('owner_id', $pool->id)->where('name', $target)
                ->whereIn('type', ['A', 'AAAA'])->where('confirmed', true)->exists();
        if (!$ready) throw new HttpResponseException(response('节点域名正在准备，请稍后更新订阅', 503,
            ['Cache-Control' => 'private, no-store', 'Retry-After' => '30']));
        return $name;
    }
}
