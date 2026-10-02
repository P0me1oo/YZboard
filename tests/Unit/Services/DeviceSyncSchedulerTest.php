<?php

namespace Tests\Unit\Services;

use App\Services\DeviceSyncScheduler;
use Tests\TestCase;

class DeviceSyncSchedulerTest extends TestCase
{
    public function test_unchanged_reports_do_not_rebuild_between_reconciliations(): void
    {
        $this->freezeTime();
        $scheduler = new DeviceSyncScheduler();
        $this->assertSame([1, 2], $scheduler->targets([], [1, 2]));
        for ($i = 0; $i < 4; $i++) {
            $this->travel(1)->seconds();
            $this->assertSame([], $scheduler->targets([], [1, 2]));
        }
        $this->travel(1)->seconds();
        $this->assertSame([1, 2], $scheduler->targets([], [1, 2]));
    }

    public function test_changes_and_retries_are_not_delayed_by_periodic_reconciliation(): void
    {
        $this->freezeTime();
        $scheduler = new DeviceSyncScheduler();
        $scheduler->targets([], [1, 2]);
        $this->assertSame([2], $scheduler->targets(['2', '2', '99'], [1, 2]));
        $this->assertEqualsCanonicalizing([1, 2], $scheduler->targets(['0', '2'], [1, 2]));
        $this->assertSame([1], $scheduler->targets([1], [1, 2]));
    }

    public function test_restart_reconciles_every_connection_and_ignores_disconnected_nodes(): void
    {
        $scheduler = new DeviceSyncScheduler();
        $this->assertSame([], $scheduler->targets([1, 0], []));
        $this->assertSame([2, 3], (new DeviceSyncScheduler())->targets([1], [2, 3]));
    }
}
