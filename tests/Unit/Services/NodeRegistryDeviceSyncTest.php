<?php

namespace Tests\Unit\Services;

use App\Services\NodeRegistry;
use Tests\TestCase;
use Workerman\Connection\TcpConnection;

class NodeRegistryDeviceSyncTest extends TestCase
{
    protected function tearDown(): void
    {
        NodeRegistry::remove(900001);
        NodeRegistry::remove(900002);
        parent::tearDown();
    }

    private function snapshot(int $sequence = 1, array $users = [15 => ['8.8.8.8']]): array
    {
        return ['node_id' => 900001, 'epoch' => str_repeat('a', 32), 'sequence' => $sequence, 'users' => $users];
    }

    public function test_same_version_is_sent_once_and_empty_new_version_is_sent(): void
    {
        $frames = [];
        $conn = \Mockery::mock(TcpConnection::class);
        $conn->shouldReceive('send')->twice()->andReturnUsing(function ($data) use (&$frames) {
            $frames[] = json_decode($data, true);
            return true;
        });
        NodeRegistry::add(900001, $conn);
        $this->assertTrue(NodeRegistry::sendDevices(900001, $this->snapshot()));
        $this->assertTrue(NodeRegistry::sendDevices(900001, $this->snapshot()));
        $this->assertTrue(NodeRegistry::sendDevices(900001, $this->snapshot(2, [])));
        $this->assertSame([], $frames[1]['data']['users']);
        $this->assertSame(2, $frames[1]['data']['sequence']);
    }

    public function test_failed_send_is_retried_instead_of_remembered_as_delivered(): void
    {
        $conn = \Mockery::mock(TcpConnection::class);
        $conn->shouldReceive('send')->twice()->andReturn(false, true);
        NodeRegistry::add(900001, $conn);
        $this->assertFalse(NodeRegistry::sendDevices(900001, $this->snapshot()));
        $this->assertTrue(NodeRegistry::sendDevices(900001, $this->snapshot()));
        $this->assertTrue(NodeRegistry::sendDevices(900001, $this->snapshot()));
    }

    public function test_unchanged_devices_are_renewed_before_node_expiry(): void
    {
        $this->freezeTime();
        $frames = [];
        $conn = \Mockery::mock(TcpConnection::class);
        $conn->shouldReceive('send')->andReturnUsing(function ($data) use (&$frames) {
            $frames[] = json_decode($data, true)['data'];
            return true;
        });
        NodeRegistry::add(900001, $conn);
        $snapshot = $this->snapshot();
        NodeRegistry::sendDevices(900001, $snapshot);
        // 连续八十秒名单不变，覆盖节点三十五秒过期时间。
        for ($second = 1; $second <= 80; $second++) {
            $this->travel(1)->seconds();
            NodeRegistry::sendDevices(900001, $snapshot);
            $this->assertCount(1 + intdiv($second, 10), $frames);
        }
        foreach ($frames as $frame) {
            $this->assertSame($snapshot, $frame);
        }
    }

    public function test_failed_renewal_is_retried_and_empty_devices_are_renewed(): void
    {
        $this->freezeTime();
        $conn = \Mockery::mock(TcpConnection::class);
        $conn->shouldReceive('send')->times(4)->andReturn(true, false, true, true);
        NodeRegistry::add(900001, $conn);
        $empty = $this->snapshot(1, []);
        $this->assertTrue(NodeRegistry::sendDevices(900001, $empty));
        $this->travel(10)->seconds();
        $this->assertFalse(NodeRegistry::sendDevices(900001, $empty));
        $this->assertTrue(NodeRegistry::sendDevices(900001, $empty));
        $this->travel(9)->seconds();
        $this->assertTrue(NodeRegistry::sendDevices(900001, $empty));
        $this->travel(1)->seconds();
        $this->assertTrue(NodeRegistry::sendDevices(900001, $empty));
    }

    public function test_reconnect_explicit_request_and_reattachment_resend_the_full_snapshot(): void
    {
        $old = \Mockery::mock(TcpConnection::class);
        $old->shouldReceive('send')->once()->andReturn(true);
        $old->shouldReceive('close')->once();
        NodeRegistry::add(900001, $old);
        NodeRegistry::sendDevices(900001, $this->snapshot());

        $new = \Mockery::mock(TcpConnection::class);
        $new->shouldReceive('send')->times(3)->andReturn(true);
        NodeRegistry::add(900001, $new);
        $this->assertTrue(NodeRegistry::sendDevices(900001, $this->snapshot()));
        $this->assertTrue(NodeRegistry::sendDevices(900001, $this->snapshot(), true));
        NodeRegistry::remove(900001, $new);
        NodeRegistry::add(900001, $new);
        $this->assertTrue(NodeRegistry::sendDevices(900001, $this->snapshot()));
    }

    public function test_shared_machine_connection_tracks_each_node_and_replaced_epoch(): void
    {
        $conn = \Mockery::mock(TcpConnection::class);
        $conn->machineNodeIds = [900001, 900002];
        $conn->shouldReceive('send')->times(3)->andReturn(true);
        NodeRegistry::add(900001, $conn);
        NodeRegistry::add(900002, $conn);
        NodeRegistry::sendDevices(900001, $this->snapshot());
        NodeRegistry::sendDevices(900002, array_replace($this->snapshot(), ['node_id' => 900002]));
        NodeRegistry::sendDevices(900001, array_replace($this->snapshot(), ['epoch' => str_repeat('b', 32)]));
        $this->assertTrue(NodeRegistry::sendDevices(900002, array_replace($this->snapshot(), ['node_id' => 900002])));
    }

    public function test_shared_machine_connection_renews_each_node_independently(): void
    {
        $this->freezeTime();
        $frames = [];
        $conn = \Mockery::mock(TcpConnection::class);
        $conn->machineNodeIds = [900001, 900002];
        $conn->shouldReceive('send')->times(4)->andReturnUsing(function ($data) use (&$frames) {
            $frames[] = json_decode($data, true)['data']['node_id'];
            return true;
        });
        NodeRegistry::add(900001, $conn);
        NodeRegistry::add(900002, $conn);
        $first = $this->snapshot();
        $second = array_replace($first, ['node_id' => 900002]);
        NodeRegistry::sendDevices(900001, $first);
        $this->travel(5)->seconds();
        NodeRegistry::sendDevices(900002, $second);
        $this->travel(5)->seconds();
        NodeRegistry::sendDevices(900001, $first);
        NodeRegistry::sendDevices(900002, $second);
        $this->travel(5)->seconds();
        NodeRegistry::sendDevices(900002, $second);
        $this->assertSame([900001, 900002, 900001, 900002], $frames);
    }
}
