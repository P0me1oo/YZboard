<?php

namespace Tests\Unit\Services;

use App\Services\NodeRegistry;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Workerman\Connection\TcpConnection;

class NodeRegistryTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private TcpConnection $connection;
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = Mockery::mock(TcpConnection::class);
        $this->connection->machineNodeIds = [];
        $this->connection->shouldNotReceive('close');
        $this->connection->shouldReceive('send')->andReturnUsing(function (string $message): bool {
            $this->sent[] = json_decode($message, true, flags: JSON_THROW_ON_ERROR);
            return true;
        });
        NodeRegistry::addMachine(70001, $this->connection);
        NodeRegistry::refreshMachineNodes(70001, [21001, 21002]);
    }

    protected function tearDown(): void
    {
        NodeRegistry::remove(21001, $this->connection);
        NodeRegistry::remove(21002, $this->connection);
        NodeRegistry::removeMachine(70001, $this->connection);
        parent::tearDown();
    }

    public function test_removing_one_node_keeps_the_shared_connection_and_other_node_usable(): void
    {
        NodeRegistry::refreshMachineNodes(70001, [21002]);
        NodeRegistry::refreshMachineNodes(70001, [21002]);

        $this->assertNull(NodeRegistry::get(21001));
        $this->assertSame($this->connection, NodeRegistry::get(21002));
        $this->assertSame($this->connection, NodeRegistry::getMachine(70001));
        $this->assertFalse(NodeRegistry::send(21001, 'sync.config', []));
        $this->assertTrue(NodeRegistry::send(21002, 'sync.config', []));
        $this->assertSame(21002, $this->sent[0]['data']['node_id']);
        $this->assertTrue(NodeRegistry::sendMachine(70001, 'sync.nodes', ['nodes' => [['id' => 21002]]]));
        $this->assertSame('sync.nodes', $this->sent[1]['event']);
    }

    public function test_empty_machine_keeps_its_control_connection_for_reenabling_nodes(): void
    {
        NodeRegistry::refreshMachineNodes(70001, []);
        $this->assertNull(NodeRegistry::get(21001));
        $this->assertNull(NodeRegistry::get(21002));
        $this->assertSame($this->connection, NodeRegistry::getMachine(70001));
        $this->assertTrue(NodeRegistry::sendMachine(70001, 'sync.nodes', ['nodes' => []]));

        NodeRegistry::refreshMachineNodes(70001, [21001, 21002]);
        $this->assertSame($this->connection, NodeRegistry::get(21001));
        $this->assertSame($this->connection, NodeRegistry::get(21002));
        $this->assertTrue(NodeRegistry::send(21001, 'sync.users', ['users' => []]));
        $this->assertSame(21001, $this->sent[1]['data']['node_id']);
    }
}
