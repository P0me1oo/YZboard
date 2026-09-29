<?php

namespace App\Services;

use App\Models\Server;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

class NodeStateService
{
    public function __construct(private readonly RealtimeStateStore $store)
    {
    }

    public function accept(Server $node, array $message): array
    {
        $validated = Validator::make($message, [
            'epoch' => ['required', 'string', 'regex:/\A[a-f0-9]{32}\z/'],
            'sequence' => 'required|integer|min:1|max:9007199254740991',
            'state' => 'present|array',
            'state.alive' => 'sometimes|array',
            'state.alive.*' => 'array',
            'state.alive.*.*' => 'ip',
            'state.online' => 'sometimes|array',
            'state.online.*' => 'integer|min:0',
            'state.connection_counts' => 'sometimes|array',
            'state.connection_counts.*' => 'integer|min:0',
            'state.relay_user_alive' => 'sometimes|array',
            'state.relay_user_alive.*' => 'array',
            'state.relay_user_alive.*.*' => 'array',
            'state.relay_user_alive.*.*.*' => 'ip',
            'state.relay_connection_counts' => 'sometimes|array',
            'state.relay_connection_counts.*' => 'array',
            'state.relay_connection_counts.*.*' => 'integer|min:0',
            'state.status' => 'sometimes|array',
            'state.metrics' => 'sometimes|array',
        ])->validate();
        // 验证器可能省略没有子项的空数组；原始空快照必须保留，才能清除在线状态。
        $state = array_intersect_key($message['state'], array_flip([
            'alive', 'online', 'connection_counts', 'relay_user_alive',
            'relay_connection_counts', 'status', 'metrics',
        ]));
        $source = 'node:' . $node->id;
        $receipt = $this->store->accept($source, $validated['epoch'], (int) $validated['sequence'], $state);
        $this->projectLatest($node);
        return $receipt;
    }

    /** 只投影最新快照；迟到请求和投影重试不能重新应用已经过时的内容。 */
    public function projectLatest(Server $node): void
    {
        $key = 'realtime:projection:node:' . $node->id;
        Cache::lock($key . ':lock', 30)->block(2, function () use ($node, $key): void {
            $snapshot = $this->store->read('node:' . $node->id);
            if (!$snapshot || !$snapshot['fresh']) {
                return;
            }
            $version = $snapshot['epoch'] . ':' . $snapshot['sequence'];
            if (Cache::get($key) === $version) {
                return;
            }
            self::apply($node, $snapshot['data']);
            Cache::forever($key, $version);
        });
    }

    /** 复用原报告处理次序，尤其是中转来源必须早于整个入口的设备快照。 */
    public static function apply(Server $node, array $state): void
    {
        ServerService::touchNode($node);
        if (is_array($state['relay_user_alive'] ?? null) && $state['relay_user_alive'] !== []) {
            ServerService::processRelayUserAlive($node, $state['relay_user_alive']);
        }
        $relayCounts = $state['relay_connection_counts'] ?? null;
        $hasRelayCounts = is_array($relayCounts)
            && ServerService::processRelayConnectionCounts($node, $relayCounts);
        $counts = $state['connection_counts'] ?? null;
        if (is_array($counts)) {
            app(UserConnectionService::class)->replace($node, $counts);
            if (!$hasRelayCounts) {
                ServerService::processConnectionCounts($node, $counts);
            }
        }
        if (is_array($state['alive'] ?? null)) {
            ServerService::processAlive((int) $node->id, $state['alive']);
        }
        if (is_array($state['online'] ?? null)) {
            ServerService::processOnline($node, $state['online']);
        }
        if (is_array($state['status'] ?? null) && $state['status'] !== []) {
            ServerService::processStatus($node, $state['status']);
        }
        if (is_array($state['metrics'] ?? null) && $state['metrics'] !== []) {
            ServerService::updateMetrics($node, $state['metrics']);
        }
    }
}
