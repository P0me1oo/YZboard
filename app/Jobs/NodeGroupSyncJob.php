<?php

namespace App\Jobs;

use App\Services\NodeSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class NodeGroupSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;

    /**
     * @param int[] $groupIds
     */
    public function __construct(private readonly array $groupIds)
    {
        $this->onQueue('node_sync');
    }

    public function backoff(): array
    {
        return [5, 15, 30];
    }

    public function handle(): void
    {
        NodeSyncService::notifyUsersUpdatedByGroups($this->groupIds);
    }
}
