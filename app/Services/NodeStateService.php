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
        ])->validate();
        $state = NodeStateValidator::validate($message['state']);
        $source = 'node:' . $node->id;
        $receipt = $this->store->accept($source, $validated['epoch'], (int) $validated['sequence'], $state);
        $this->projectLatest($node);
        return $receipt + ['telemetry' => app(TelemetryDemand::class)->forSource($source)];
    }

    /** 只投影最新快照；迟到请求和投影重试不能重新应用已经过时的内容。 */
    public function projectLatest(Server $node): void
    {
        $key = 'realtime:projection:node:' . $node->id;
        Cache::lock($key . ':lock', 30)->block(2, function () use ($node, $key): void {
            ['snapshot' => $snapshot, 'version' => $applied] = $this->store->readForProjection('node:' . $node->id);
            if (!$snapshot || !$snapshot['fresh']) {
                return;
            }
            $version = $snapshot['epoch'] . ':' . $snapshot['sequence'];
            if ($applied === $version) {
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
