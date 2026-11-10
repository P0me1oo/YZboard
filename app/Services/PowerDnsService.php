<?php

namespace App\Services;

use App\Exceptions\DdnsSyncException;
use App\Models\BalancerDnsPreset;
use App\Models\PowerDnsRecord;
use Illuminate\Support\Facades\Cache;

class PowerDnsService
{
    public function __construct(private readonly PowerDnsClient $client) {}

    /** 以 RRset 为单位保存归属意图；响应丢失后只认领同一随机标记。 */
    public function reconcile(BalancerDnsPreset $preset, string $ownerType, int $ownerId, array $desired, int $ttl): void
    {
        $key = hash('sha256', $preset->api_url . '/' . $preset->server_id . '/' . $preset->domain);
        Cache::lock('powerdns:zone:' . $key, 180)->block(2, function () use ($preset, $ownerType, $ownerId, $desired, $ttl) {
            // 读取、退避、写入和结果核对共用预算，避免重试耗尽 HTTP/队列时限或同步锁。
            $deadline = microtime(true) + 25;
            $remote = collect($this->client->request($preset, 'get', deadline: $deadline)['rrsets'])->keyBy(fn ($r) => strtolower(rtrim($r['name'], '.')) . '|' . $r['type']);
            $byName = $remote->groupBy(fn ($r) => strtolower(rtrim($r['name'], '.')), preserveKeys: true);
            $stored = PowerDnsRecord::where('owner_type', $ownerType)->where('owner_id', $ownerId)->get()
                ->keyBy(fn ($r) => $r->name . '|' . $r->type);
            $managed = $stored->all();
            $wanted = [];
            foreach ($desired as $value) {
                $name = strtolower(rtrim($value['name'], '.'));
                if ($name !== $preset->domain && !str_ends_with($name, '.' . $preset->domain)) throw new DdnsSyncException('解析超出 PowerDNS 区域');
                if (in_array($value['type'], ['A', 'AAAA'], true)) {
                    $value['contents'] = array_map(function ($ip) use ($value) {
                        if (!filter_var($ip, FILTER_VALIDATE_IP, $value['type'] === 'A' ? FILTER_FLAG_IPV4 : FILTER_FLAG_IPV6)) {
                            throw new DdnsSyncException('解析地址或地址族无效');
                        }
                        return inet_ntop(inet_pton($ip));
                    }, $value['contents']);
                }
                $wanted[$name . '|' . $value['type']] = [...$value, 'name' => $name];
            }
            $scope = array_unique(array_merge(array_column($wanted, 'name'), $stored->pluck('name')->all()));
            // 泛解析会使撤销后的个人域名继续生效；委托区域自己的 NS/SOA 必须保留。
            foreach ($remote as $r) {
                $name = strtolower(rtrim($r['name'], '.'));
                if (!str_starts_with($name, '*.') && ($r['type'] !== 'NS' || $name === $preset->domain)) continue;
                foreach ($scope as $managedName) {
                    if ((str_starts_with($name, '*.') && ($managedName === substr($name, 2) || str_ends_with($managedName, substr($name, 1))))
                        || ($r['type'] === 'NS' && $name !== $preset->domain
                            && ($managedName === $name || str_ends_with($managedName, '.' . $name)))) {
                        throw new DdnsSyncException('托管范围存在泛解析或下级 NS 委托冲突');
                    }
                }
            }
            $actions = [];
            foreach ($stored as $key => $record) {
                if ((int) $record->preset_id !== (int) $preset->id) throw new DdnsSyncException('请先清理旧 PowerDNS 配置的记录');
                $r = $remote->get($key);
                if ($r && array_column($r['comments'] ?? [], 'content') !== [$record->marker]) {
                    $record->update(['confirmed' => false]);
                    throw new DdnsSyncException('托管记录归属发生变化，请检查后重试');
                }
                if (!$r && $record->confirmed) $record->update(['confirmed' => false]);
                if (!isset($wanted[$key]) && $r) $actions[] = ['name' => $record->name . '.', 'type' => $record->type, 'changetype' => 'DELETE'];
            }
            foreach ($wanted as $key => $value) {
                $record = $stored->get($key);
                foreach ($byName->get($value['name'], []) as $rkey => $r) {
                    if (($rkey === $key || $r['type'] === 'CNAME' || $value['type'] === 'CNAME'
                        || (in_array($r['type'], ['A', 'AAAA'], true) && in_array($value['type'], ['A', 'AAAA'], true)))
                        && (!$stored->has($rkey) || array_column($r['comments'] ?? [], 'content') !== [$stored[$rkey]->marker])) {
                        throw new DdnsSyncException('域名存在非本功能管理的记录');
                    }
                }
                $record ??= PowerDnsRecord::create(['owner_type' => $ownerType, 'owner_id' => $ownerId, 'preset_id' => $preset->id,
                    'name' => $value['name'], 'type' => $value['type'], 'contents' => $value['contents'], 'confirmed' => false,
                    'marker' => 'YZDNS:' . bin2hex(random_bytes(16))]);
                $managed[$key] = $record;
                $r = $remote->get($key);
                $contents = array_values(array_unique($value['contents'])); sort($contents);
                $actual = array_column($r['records'] ?? [], 'content'); sort($actual);
                $disabled = collect($r['records'] ?? [])->contains(fn ($v) => $v['disabled'] ?? false);
                if (!$r || $contents !== $actual || (int) $r['ttl'] !== $ttl || $disabled) {
                    $actions[] = ['name' => $value['name'] . '.', 'type' => $value['type'], 'ttl' => $ttl, 'changetype' => 'REPLACE',
                        'records' => array_map(fn ($content) => ['content' => $content, 'disabled' => false], $contents),
                        'comments' => [['content' => $record->marker, 'account' => 'YZboard']]];
                }
            }
            // 一次 PATCH 同时替换 A/AAAA 和别名，避免先加后删产生地址族残留。
            if ($actions) {
                foreach ($actions as $action) {
                    $record = $managed[rtrim($action['name'], '.') . '|' . $action['type']];
                    if ($record->confirmed) $record->update(['confirmed' => false]);
                }
                $this->client->request($preset, 'patch', ['rrsets' => $actions], $remote->values()->all(), $deadline);
            }
            foreach ($stored as $key => $record) if (!isset($wanted[$key])) $record->delete();
            foreach ($wanted as $key => $value) {
                $record = $managed[$key];
                $record->fill(['contents' => $value['contents'], 'confirmed' => true]);
                if ($record->isDirty()) $record->save();
            }
        });
    }
}
