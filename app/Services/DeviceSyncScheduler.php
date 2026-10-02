<?php

namespace App\Services;

/** 变化通知及时处理；定期重新查询资格、设备过期及遗漏的通知。 */
class DeviceSyncScheduler
{
    public const RECONCILE_SECONDS = 5;

    private int $nextReconcileAt = 0;

    public function targets(array $pending, array $connected): array
    {
        $now = now()->getTimestampMs();
        $pending = array_map('intval', $pending);
        if ($now >= $this->nextReconcileAt) {
            $this->nextReconcileAt = $now + self::RECONCILE_SECONDS * 1000;
            $pending = array_merge($pending, $connected);
        }
        if (in_array(0, $pending, true)) {
            $pending = array_merge($pending, $connected);
        }

        return array_values(array_intersect(array_unique($pending), $connected));
    }
}
