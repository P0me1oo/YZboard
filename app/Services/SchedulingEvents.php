<?php

namespace App\Services;

use App\Models\SchedulingEvent;

class SchedulingEvents
{
    public static function record(string $type, int $id, string $name, string $reason, array $previous, array $current): void
    {
        if ($previous === $current) return;
        SchedulingEvent::create(['owner_type' => $type, 'owner_id' => $id, 'pool_name' => $name,
            'reason' => $reason, 'previous' => $previous, 'current' => $current, 'created_at' => now()]);
    }
}
