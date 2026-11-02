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
        $runtime = MachineAgentService::runtimeView($source->machine?->agent_runtime) ?? [];
        $addresses = array_key_exists('public_ipv4', $runtime) || array_key_exists('public_ipv6', $runtime)
            ? [$runtime['public_ipv4'] ?? null, $runtime['public_ipv6'] ?? null] : [$runtime['public_ip'] ?? null];
        $addresses = array_values(array_filter($addresses, fn ($v) => is_string($v) && filter_var($v, FILTER_VALIDATE_IP)
            && !in_array($v, ['0.0.0.0', '::'], true)));
        if (!$addresses && filter_var($source->host, FILTER_VALIDATE_IP)) $addresses = [$source->host];
        return array_values(array_unique($addresses));
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
        $desired = [];
        foreach (['A', 'AAAA'] as $type) {
            $values = array_values(array_unique(array_filter($addresses, fn ($ip) => (str_contains($ip, ':') ? 'AAAA' : 'A') === $type)));
            if ($values) $desired[] = ['name' => $pool->domain, 'type' => $type, 'contents' => $values];
        }
        if ($pool->enabled && $pool->personal_domain_enabled) {
            $aliases = PoolDnsAlias::where('pool_id', $pool->id)->get()->keyBy('user_id');
            foreach ($this->users($pool) as $user) {
                $alias = $aliases->get($user->id) ?? PoolDnsAlias::create(['pool_id' => $pool->id, 'user_id' => $user->id, 'label' => bin2hex(random_bytes(16))]);
                // 全部前置不可用时连同个人解析撤销；身份标签保留供恢复时复用。
                if ($addresses) $desired[] = ['name' => $alias->label . '.' . $pool->domain, 'type' => 'CNAME', 'contents' => [$pool->domain . '.']];
            }
        }
        app(PowerDnsService::class)->reconcile($pool->dnsPreset, 'pool', $pool->id, $desired, $pool->dns_ttl);
        $previous = $pool->scheduling_state['snapshot'] ?? [];
        SchedulingEvents::record('front', $pool->id, $pool->name, 'selection_changed', $previous, $state['snapshot']);
        $pool->update(['scheduling_state' => $state]);
    }

    public function project(BalancerPool $pool, User|array $user): string
    {
        if (!$pool->personal_domain_enabled) return $pool->domain;
        $userId = (int) (is_array($user) ? ($user['id'] ?? 0) : $user->id);
        $alias = PoolDnsAlias::where('pool_id', $pool->id)->where('user_id', $userId)->first();
        $current = User::find($userId);
        if (!$current || ($pool->follow_user_status && ($current->banned || $current->u + $current->d >= $current->transfer_enable
            || ($current->expired_at !== null && $current->expired_at <= time())))) {
            throw new HttpResponseException(response('当前用户没有该节点的使用权限', 403, ['Cache-Control' => 'private, no-store']));
        }
        $name = $alias ? $alias->label . '.' . $pool->domain : null;
        $ready = $name && PowerDnsRecord::where('owner_type', 'pool')->where('owner_id', $pool->id)
            ->where('name', $name)->where('type', 'CNAME')->where('confirmed', true)->exists()
            && PowerDnsRecord::where('owner_type', 'pool')->where('owner_id', $pool->id)->where('name', $pool->domain)
                ->whereIn('type', ['A', 'AAAA'])->where('confirmed', true)->exists();
        if (!$ready) throw new HttpResponseException(response('节点域名正在准备，请稍后更新订阅', 503,
            ['Cache-Control' => 'private, no-store', 'Retry-After' => '30']));
        return $name;
    }
}
