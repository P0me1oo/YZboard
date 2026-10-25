<?php

namespace App\Observers;

use App\Jobs\NodeUserSyncJob;
use App\Models\User;
use App\Services\TrafficResetService;

class UserObserver
{
  public function __construct(
    private readonly TrafficResetService $trafficResetService
  ) {
  }

  public function updated(User $user): void
  {
    // 在提交前保存旧权限组，提交后模型的原始属性已变成新值。
    $syncFields = ['group_id', 'group_ids', 'uuid', 'speed_limit', 'device_limit', 'conn_limit', 'conn_rate_limit', 'banned', 'expired_at', 'transfer_enable', 'u', 'd', 'plan_id'];
    $needsSync = $user->wasChanged($syncFields);
    $oldGroups = $user->wasChanged(['group_id', 'group_ids'])
      ? (new User(['group_id' => $user->getOriginal('group_id'), 'group_ids' => $user->getOriginal('group_ids')]))->effectiveGroupIds()
      : [];

    $recalculate = $user->wasChanged(['plan_id', 'expired_at']);
    $snapshot = clone $user;
    $user->getConnection()->afterCommit(function () use ($snapshot, $recalculate, $needsSync, $oldGroups) {
      if ($recalculate && $snapshot->fresh()) $this->recalculateNextResetAt($snapshot);
      if ($needsSync) {
        NodeUserSyncJob::dispatch($snapshot->id, 'updated', $oldGroups);
        \App\Services\DdnsService::notify();
      }
    });
  }

  public function created(User $user): void
  {
    $snapshot = clone $user;
    $user->getConnection()->afterCommit(function () use ($snapshot) {
      if (!$snapshot->fresh()) return;
      $this->recalculateNextResetAt($snapshot);
      NodeUserSyncJob::dispatch($snapshot->id, 'created');
      \App\Services\DdnsService::notify();
    });
  }

  public function deleted(User $user): void
  {
    \App\Services\DdnsService::notify();
    if ($user->effectiveGroupIds() !== []) {
      $id = $user->id;
      $groups = $user->effectiveGroupIds();
      $user->getConnection()->afterCommit(fn () => NodeUserSyncJob::dispatch($id, 'deleted', $groups));
    }
  }

  /**
   * 根据当前用户状态重新计算 next_reset_at
   */
  private function recalculateNextResetAt(User $user): void
  {
    $user->refresh();
    User::withoutEvents(function () use ($user) {
      $nextResetTime = $this->trafficResetService->calculateNextResetTime($user);
      $user->next_reset_at = $nextResetTime?->timestamp;
      $user->save();
    });
  }
}
