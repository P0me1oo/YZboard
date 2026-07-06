<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Services\NodeSyncService;
use App\Services\ServerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncNodeUsers extends Command
{
    protected $signature = 'node:sync-users {--node=* : 只同步指定节点 ID}';

    protected $description = '全量同步在线节点的可用用户列表';

    public function handle(): int
    {
        $nodeIds = collect((array) $this->option('node'))
            ->map(fn($id) => (int) $id)
            ->filter(fn($id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        $query = Server::query()
            ->where('enabled', true)
            ->orderBy('sort')
            ->orderBy('id');

        if (!empty($nodeIds)) {
            $query->whereIn('id', $nodeIds);
        }

        $nodes = $query->get();
        if ($nodes->isEmpty()) {
            $this->warn('没有找到需要同步的节点。');
            return self::SUCCESS;
        }

        $synced = 0;
        $offline = 0;
        $failed = 0;

        foreach ($nodes as $node) {
            if (!NodeSyncService::isNodeOnline($node->id)) {
                $offline++;
                $this->line("跳过离线节点 #{$node->id} {$node->name}");
                continue;
            }

            try {
                $users = ServerService::getAvailableUsers($node)->toArray();
                NodeSyncService::push($node->id, 'sync.users', ['users' => $users]);

                $synced++;
                $this->line("已同步节点 #{$node->id} {$node->name}，用户数 " . count($users));
            } catch (\Throwable $e) {
                $failed++;
                Log::warning('[NodeUserSync] sync users failed: ' . $e->getMessage(), [
                    'node_id' => $node->id,
                    'node_name' => $node->name,
                ]);
                $this->error("同步节点 #{$node->id} {$node->name} 失败：{$e->getMessage()}");
            }
        }

        $this->info("节点用户同步完成：成功 {$synced}，离线 {$offline}，失败 {$failed}。");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
