<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\NodeSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class NodeUserSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;
    public $timeout = 10;

    // 保留旧属性，兼容升级前已排队的任务。
    private ?int $oldGroupId = null;
    private array $oldGroupIds = [];

    public function __construct(
        private readonly int $userId,
        private readonly string $action,
        array|int|null $oldGroups = null
    ) {
        if (is_array($oldGroups)) {
            $this->oldGroupIds = $oldGroups;
        } else {
            $this->oldGroupId = $oldGroups;
        }
        $this->onQueue('node_sync');
    }

    public function handle(): void
    {
        $user = User::find($this->userId);

        if ($this->action === 'updated' || $this->action === 'created') {
            $oldGroups = $this->oldGroupIds ?: ($this->oldGroupId ? [$this->oldGroupId] : []);
            if ($oldGroups !== []) {
                NodeSyncService::notifyUserRemovedFromGroups($this->userId, $oldGroups);
            }
            if ($user) {
                NodeSyncService::notifyUserChanged($user);
            }
        } elseif ($this->action === 'deleted') {
            $oldGroups = $this->oldGroupIds ?: ($this->oldGroupId ? [$this->oldGroupId] : []);
            if ($oldGroups !== []) {
                NodeSyncService::notifyUserRemovedFromGroups($this->userId, $oldGroups);
            }
        }
    }
}
