<?php

namespace Tests\Unit\Services;

use App\Services\RealtimeSnapshotService;
use App\Services\RealtimeStateStore;
use App\Services\RealtimeTicketService;
use App\WebSocket\AdminRealtimeWorker;
use Tests\TestCase;
use Workerman\Connection\TcpConnection;

class AdminRealtimeWorkerTest extends TestCase
{
    public function test_capture_version_alone_does_not_resend_unchanged_display_content(): void
    {
        $snapshots = new RealtimeSnapshotService(new RealtimeStateStore());
        $worker = new AdminRealtimeWorker($snapshots, \Mockery::mock(RealtimeTicketService::class));
        $conn = \Mockery::mock(TcpConnection::class);
        $conn->shouldReceive('send')->once()->withArgs(function ($data): bool {
            $frame = json_decode($data, true);
            return $frame['event'] === 'state.snapshot' && isset($frame['data']['version']);
        })->andReturn(true);
        $property = new \ReflectionProperty($worker, 'connections');
        $property->setValue($worker, [1 => [
            'connection' => $conn, 'identity' => [], 'subscription' => $snapshots->validate([]),
            'subscription_id' => 'snapshot-test', 'sequence' => 0, 'received_at' => microtime(true),
            'checked_at' => microtime(true), 'ping_at' => microtime(true), 'captured_at' => 0, 'hash' => null,
        ]]);
        $worker->tick();
        $entries = $property->getValue($worker);
        $entries[1]['captured_at'] -= 1;
        $property->setValue($worker, $entries);
        $worker->changed();
        $worker->tick();
        $this->assertSame(1, $property->getValue($worker)[1]['sequence']);
    }
}
