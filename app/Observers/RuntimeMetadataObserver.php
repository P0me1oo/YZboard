<?php

namespace App\Observers;

use App\Services\NodeRuntimeMetadata;
use Illuminate\Database\Eloquent\Model;

class RuntimeMetadataObserver
{
    public function saved(Model $model): void
    {
        // 活跃时间、负载和累计流量不属于这里消费的资料，避免每次心跳刷新全部节点。
        $runtimeFields = ['updated_at', 'last_seen_at', 'load_status', 'agent_runtime', 'agent_operation', 'u', 'd'];
        if ($model->wasRecentlyCreated || array_diff(array_keys($model->getChanges()), $runtimeFields) !== []) {
            NodeRuntimeMetadata::invalidate($model::class);
        }
    }

    public function deleted(Model $model): void
    {
        NodeRuntimeMetadata::invalidate($model::class);
    }
}
