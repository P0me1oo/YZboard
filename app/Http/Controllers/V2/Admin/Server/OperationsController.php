<?php

namespace App\Http\Controllers\V2\Admin\Server;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\BalancerPool;
use App\Models\LandingPool;
use App\Services\NodeOperations;
use App\Services\NodeSyncService;
use App\Services\RealtimeStateStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class OperationsController extends Controller
{
    private function node(Request $r): Server
    {
        $p = $r->validate(['node_id' => 'required|integer|min:1']);
        return Server::findOrFail($p['node_id']);
    }
    public function fetch(Request $r, NodeOperations $service)
    {
        $p = $r->validate(['ids' => 'sometimes|array|max:500','ids.*' => 'integer|min:1']);
        $nodes = Server::query()->when(isset($p['ids']), fn ($q) => $q->whereIn('id', $p['ids']))
            ->limit(500)->get(['id', 'parent_id', 'health_check']);
        $views = $service->views($nodes);
        return $this->success($nodes->map(fn ($n) => ['id' => (int)$n->id] + $views[$n->id]));
    }
    public function detail(Request $r, NodeOperations $service)
    {
        return $this->success($service->detail($this->node($r)));
    }
    public function health(Request $r)
    {
        $node = $this->node($r);
        $p = $r->validate(['enabled' => 'required|boolean','url' => 'required_if:enabled,true|nullable|url:https|max:500','interval' => 'required|integer|min:30|max:3600']);
        if ($p['enabled']) {
            $u = parse_url($p['url']);
            if (isset($u['user']) || isset($u['pass']) || isset($u['fragment']) || isset($u['query'])) {
                throw ValidationException::withMessages(['url' => '请使用不带认证、查询参数或片段的 HTTPS 检查地址。']);
            }
        }
        if ($node->parent_id) {
            throw ValidationException::withMessages(['node_id' => '共享节点请在其主节点设置检查。']);
        }
        $node->health_check = $p;
        $node->save();
        NodeSyncService::notifyFullSync($node->id);
        return $this->success(true);
    }
    public function check(Request $r, NodeOperations $service)
    {
        $node = $this->node($r);
        $this->requireAgent($node, $service);
        if (!($node->health_check['enabled'] ?? false)) {
            throw ValidationException::withMessages(['node_id' => '请先启用链路检查。']);
        }
        if (!Cache::add('node:health:request:'.$node->id, true, 15)) {
            return $this->success(true);
        }
        $node->health_check = array_merge($node->health_check, ['request' => bin2hex(random_bytes(16))]);
        $node->save();
        NodeSyncService::notifyFullSync($node->id);
        return $this->success(true);
    }
    public function certificates(Request $r, NodeOperations $service)
    {
        $rows = [];
        $nodes = Server::whereNull('parent_id')->orWhere('parent_id', 0)
            ->get(['id', 'parent_id', 'health_check', 'name', 'cert_config', 'certificate_renew']);
        $views = $service->views($nodes);
        foreach ($nodes as $node) {
            $view = $views[$node->id];
            $cfg = $node->cert_config;
            $certificate = $view['state']['certificate'] ?? null;
            if (!$cfg && !($certificate['present'] ?? false)) {
                continue;
            }
            $rows[] = ['node_id' => (int)$node->id,'name' => $node->name,'mode' => $cfg['cert_mode'] ?? $cfg['mode'] ?? $certificate['mode'] ?? 'none','domain' => $cfg['domain'] ?? $certificate['domain'] ?? '','fresh' => $view['fresh'],'supported' => $view['supported'],'certificate' => $certificate,'renewal' => $view['state']['renewal'] ?? null,'requested' => $node->certificate_renew];
        }
        return $this->success($rows);
    }
    public function renew(Request $r, NodeOperations $service)
    {
        $node = $this->node($r);
        $this->requireAgent($node, $service);
        $cfg = $node->cert_config;
        if (!in_array($cfg['cert_mode'] ?? $cfg['mode'] ?? '', ['http','dns'], true)) {
            throw ValidationException::withMessages(['node_id' => '只有自动申请的 ACME 证书支持续期。']);
        }
        if (!Cache::add('node:cert:renew:'.$node->id, true, 300)) {
            throw ValidationException::withMessages(['node_id' => '续期请求已提交，请稍后查看结果。']);
        }
        $node->certificate_renew = bin2hex(random_bytes(16));
        $node->save();
        NodeSyncService::notifyFullSync($node->id);
        return $this->success(['request' => $node->certificate_renew]);
    }
    private function requireAgent(Server $node, NodeOperations $service): void
    {
        $view = $service->view($node);
        if (!$view['supported'] || !$view['fresh']) {
            throw ValidationException::withMessages(['node_id' => '节点未连接或 Agent 尚不支持此功能。']);
        }
    }
    public function topology(Request $r, NodeOperations $service)
    {
        $nodes = Server::get(['id', 'parent_id', 'health_check', 'name', 'type', 'machine_id', 'enabled', 'relay_entry_id', 'relay_pool_id']);
        $views = $service->views($nodes);
        $pools = BalancerPool::with('members')->get();
        $landings = LandingPool::get();
        $edges = [];
        $items = [];
        foreach ($nodes as $node) {
            $items[] = ['id' => 'node:'.$node->id,'node_id' => (int)$node->id,'name' => $node->name,'type' => $node->isRelayChild() ? 'landing' : 'node','protocol' => $node->type,'machine_id' => $node->machine_id,'enabled' => $node->enabled,'operations' => $views[$node->id]];
            if ($node->relay_entry_id) {
                $edges[] = ['from' => 'node:'.$node->relay_entry_id,'to' => 'node:'.$node->id,'kind' => 'relay'];
            }if ($node->relay_pool_id) {
                $edges[] = ['from' => 'pool:'.$node->relay_pool_id,'to' => 'node:'.$node->id,'kind' => 'relay'];
            }
        }
        foreach ($pools as $pool) {
            $items[] = ['id' => 'pool:'.$pool->id,'name' => $pool->name,'type' => 'pool','enabled' => $pool->enabled,'mode' => $pool->mode];
            foreach ($pool->members as $member) {
                if ($member->runtime_node_id) {
                    $edges[] = ['from' => 'pool:'.$pool->id,'to' => 'node:'.$member->runtime_node_id,'kind' => 'member','weight' => $member->weight,'priority' => $member->priority];
                }
            }
        }
        foreach ($landings as $pool) {
            foreach ($pool->members ?? [] as $id) {
                $edges[] = ['from' => 'node:'.$pool->node_id,'to' => 'node:'.$id,'kind' => 'standby','active' => in_array((int)$id, array_map('intval', array_values($pool->state['active'] ?? [])), true)];
            }
        }
        return $this->success(['nodes' => $items,'edges' => $edges]);
    }
}
