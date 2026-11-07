<?php

namespace App\Services;

use App\Models\Server;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class NodeOperations
{
    public function accept(Server $node, array $data): void
    {
        Cache::lock('node:operations:lock:'.$node->id, 10)->block(2, fn () => $this->acceptLocked($node, $data));
    }
    private function acceptLocked(Server $node, array $data): void
    {
        // 公共状态入口只接受有界元数据，既不存原始核心配置也不存密钥。
        $v = Validator::make($data, [
         'run'=>['required','string','regex:/\A[a-f0-9]{32}\z/'],'sequence'=>'required|integer|min:1|max:9007199254740991','error_id'=>'nullable|string|max:64',
            'sampled_at' => 'required|integer|min:1|max:9007199254740991','version' => 'required|integer|in:1','runtime' => 'required|in:running,stopped,failed','error' => 'nullable|string|max:300',
         'config_hash' => 'required|string|max:128','control' => 'sometimes|array:epoch,sequence','control.epoch' => 'nullable|string|max:64','control.sequence' => 'sometimes|integer|min:0',
         'pending_bytes' => 'required|integer|min:0|max:9007199254740991',
         'auto_speed' => 'sometimes|array|max:10000','auto_speed.*' => 'array:limit_mbps,expires_at,rule','auto_speed.*.limit_mbps' => 'required|integer|min:1|max:100000','auto_speed.*.expires_at' => 'required|integer|min:0','auto_speed.*.rule' => 'required|string|size:64','auto_speed_error' => 'nullable|string|max:100',
         'health' => 'nullable|array:id,checked_at,status,config_hash,target,source,steps,routes','health.id' => 'required_with:health|string|max:64','health.checked_at' => 'required_with:health|integer|min:1','health.status' => 'required_with:health|in:ok,failed,unsupported,partial','health.config_hash' => 'sometimes|string|max:128','health.target' => 'required_with:health|string|max:500','health.source' => 'required_with:health|string|max:50','health.steps' => 'sometimes|array|max:8','health.steps.*' => 'array:name,status,latency_ms,error','health.steps.*.name' => 'required|string|max:40','health.steps.*.status' => 'required|in:ok,failed,not_supported','health.steps.*.latency_ms' => 'required|integer|min:0|max:120000','health.steps.*.error' => 'nullable|string|max:200',
         'health.routes' => 'sometimes|array|max:17','health.routes.*' => 'array:tag,node_id,status,latency_ms,error','health.routes.*.tag' => 'present|string|max:100','health.routes.*.node_id' => 'sometimes|integer|min:0','health.routes.*.status' => 'required|in:ok,failed,incomplete','health.routes.*.latency_ms' => 'required|integer|min:0|max:120000','health.routes.*.error' => 'nullable|string|max:200',
         'certificate' => 'nullable|array:domain,issuer,fingerprint,not_after,not_before,present,applied,mode,renewal_error,updated_at','certificate.domain' => 'sometimes|string|max:255','certificate.issuer' => 'sometimes|string|max:255','certificate.fingerprint' => 'sometimes|string|max:64','certificate.not_after' => 'sometimes|integer|min:0','certificate.not_before' => 'sometimes|integer|min:0','certificate.present' => 'sometimes|boolean','certificate.applied' => 'sometimes|boolean','certificate.mode' => 'sometimes|string|max:20','certificate.renewal_error' => 'nullable|string|max:200','certificate.updated_at' => 'sometimes|integer|min:0',
         'renewal' => 'nullable|array:id,status,error','renewal.id' => 'required_with:renewal|string|max:64','renewal.status' => 'required_with:renewal|in:running,succeeded,failed','renewal.error' => 'nullable|string|max:200',
        ]);
        if ($v->fails()) {
            return;
        }
        $data = $v->validated();
        $key='node:operations:'.$node->id;
        $current=Cache::get($key);
        if(($current['run']??null)===$data['run']) {
            if(($current['sequence']??0)>=$data['sequence'])return;
        } else {
            if(Cache::has($key.':retired:'.$data['run']))return;
            if(isset($current['run']))Cache::forever($key.':retired:'.$current['run'],true);
        }
        $data['received_at'] = now()->timestamp;
        if (isset($data['error'])) {
            $data['error'] = LogSanitizer::text($data['error']);
        }
        $health = $data['health'] ?? null;
        if ($health && ($health['checked_at'] > now()->timestamp + 30 || $health['checked_at'] < now()->timestamp - 86400 || !($node->health_check['enabled'] ?? false) || ($health['target'] ?? '') !== ($node->health_check['url'] ?? ''))) {
            unset($data['health']);
            $health = null;
        }
        Cache::put('node:operations:'.$node->id, $data, 86400);
        if ($health && Cache::add('node:health:sample:'.$node->id.':'.hash('sha256', $health['id']), true, 86400)) {
            try {
                DB::table('v2_node_health_sample')->insertOrIgnore(['server_id' => $node->id,'sample_id' => $health['id'],'checked_at' => $health['checked_at'],'status' => $health['status'],'latency_ms' => isset($health['routes'][0]['latency_ms']) ? $health['routes'][0]['latency_ms'] : null,'detail' => json_encode($health, JSON_THROW_ON_ERROR)]);
            } catch (\Throwable $e) {
                Cache::forget('node:health:sample:'.$node->id.':'.hash('sha256', $health['id']));
                throw $e;
            }
        }
    }
    public function view(Server $node): array
    {
        $data = Cache::get('node:operations:'.($node->parent_id ?: $node->id));
        return $this->present($node, $data);
    }

    /** 列表一次读取一批缓存，共享节点只读取一次父节点状态。 */
    public function views(iterable $nodes): array
    {
        $nodes = collect($nodes);
        $keys = $nodes->map(fn ($node) => 'node:operations:'.($node->parent_id ?: $node->id))->unique();
        $states = [];
        foreach ($keys->chunk(500) as $chunk) {
            $states += Cache::many($chunk->values()->all());
        }
        return $nodes->mapWithKeys(fn ($node) => [(int) $node->id => $this->present(
            $node, $states['node:operations:'.($node->parent_id ?: $node->id)] ?? null
        )])->all();
    }

    private function present(Server $node, mixed $data): array
    {
        $fresh = is_array($data) && ($data['received_at'] ?? 0) >= now()->timestamp - 90;
        return ['supported' => is_array($data),'fresh' => $fresh,'enabled' => (bool)($node->health_check['enabled'] ?? false),'health_config' => $node->health_check,'state' => $data];
    }
    public function detail(Server $node): array
    {
        $rows = DB::table('v2_node_health_sample')->where('server_id', $node->id)->where('checked_at', '>=', now()->timestamp - 86400)->orderBy('checked_at')->limit(2880)->get(['checked_at','status','latency_ms']);
        $count = $rows->whereIn('status', ['ok','failed'])->count();
        return $this->view($node) + ['node_id' => (int)$node->id,'name' => $node->name,'history' => $rows,'availability' => $count ? round(100 * $rows->where('status', 'ok')->count() / $count, 2) : null,'samples' => $count];
    }
}
