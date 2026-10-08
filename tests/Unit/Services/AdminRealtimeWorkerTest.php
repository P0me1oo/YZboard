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
        $now = microtime(true);
        $worker = new AdminRealtimeWorker($snapshots, \Mockery::mock(RealtimeTicketService::class), function () use (&$now) { return $now; });
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
        $now += 1.1;
        $worker->changed();
        $worker->tick();
        $this->assertSame(1, $property->getValue($worker)[1]['sequence']);
    }

    public function test_changed_notifications_are_coalesced_and_new_subscribers_share_cached_frames(): void
    {
        $now = microtime(true);
        $subscription = ['nodes' => [1]];
        $snapshots = \Mockery::mock(RealtimeSnapshotService::class);
        $snapshots->shouldReceive('snapshot')->with($subscription)->twice()->andReturn(
            ['version' => ['sequence' => 1], 'online' => 1],
            ['version' => ['sequence' => 2], 'online' => 2],
        );
        $worker = new AdminRealtimeWorker($snapshots, \Mockery::mock(RealtimeTicketService::class), function () use (&$now) { return $now; });
        $messages = [];
        $connection = function (int $id) use (&$messages) {
            $conn = \Mockery::mock(TcpConnection::class);
            $conn->shouldReceive('send')->twice()->andReturnUsing(function ($data) use ($id, &$messages) {
                $messages[$id][] = json_decode($data, true)['data'];
                return true;
            });
            return $conn;
        };
        $entry = fn ($conn, $id) => [
            'connection' => $conn, 'identity' => [], 'subscription' => $subscription,
            'subscription_id' => (string) $id, 'sequence' => 0, 'received_at' => $now,
            'checked_at' => $now, 'ping_at' => $now, 'captured_at' => 0, 'hash' => null,
        ];
        $property = new \ReflectionProperty($worker, 'connections');
        $property->setValue($worker, [1 => $entry($connection(1), 1)]);
        $worker->tick();
        for ($step = 0; $step < 8; $step++) {
            $now += 0.1;
            $worker->changed();
            $worker->tick();
        }
        $entries = $property->getValue($worker);
        $entries[2] = $entry($connection(2), 2);
        $property->setValue($worker, $entries);
        $worker->tick();
        $this->assertSame(1, $messages[2][0]['online'], '新订阅应立即收到同组现有快照');
        $this->assertCount(1, $messages[1], '一秒内多次变化不能反复计算和发送');
        $now += 0.3;
        $worker->tick();
        foreach ([1, 2] as $id) {
            $this->assertSame(2, $messages[$id][1]['online']);
            $this->assertSame((string) $id, $messages[$id][1]['subscription_id']);
        }
        $property->setValue($worker, []);
        $worker->tick();
        $this->assertSame([], (new \ReflectionProperty($worker, 'frames'))->getValue($worker), '无人订阅的缓存应释放');
    }
}
