<?php

namespace Tests\Feature\Server;

use App\Jobs\DdnsSyncJob;
use App\Models\DdnsBinding;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Services\MachineAgentService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class LandingDdnsNotificationTest extends TestCase
{
    use DatabaseTruncation;

    protected function beforeTruncatingDatabase(): void
    {
        if (DB::getDriverName() === 'sqlite') RefreshDatabaseState::$migrated = false;
    }

    public static function tearDownAfterClass(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDownAfterClass();
    }

    public function test_changed_authenticated_address_queues_sync_after_commit_and_rollback_does_not(): void
    {
        Queue::fake();
        $machine = ServerMachine::create(['name' => '通知测试机', 'token' => Str::random(32), 'is_active' => true]);
        $node = Server::create(['name' => '通知测试节点', 'type' => 'shadowsocks', 'machine_id' => $machine->id,
            'host' => '192.0.2.20', 'port' => '443', 'server_port' => 443, 'rate' => 1]);
        DdnsBinding::create(['owner_type' => 'node', 'owner_id' => $node->id, 'preset_id' => 1,
            'prefix' => 'landing', 'domain' => 'landing.example.test', 'scope' => 'landing', 'address_source' => 'machine_ipv4', 'enabled' => true]);
        DB::beginTransaction();
        MachineAgentService::recordAddress($machine, '8.8.8.8');
        Queue::assertNotPushed(DdnsSyncJob::class);
        DB::rollBack();
        Queue::assertNotPushed(DdnsSyncJob::class);
        $this->assertNull(data_get($machine->fresh()->agent_runtime, 'public_ipv4'));
        MachineAgentService::recordAddress($machine, '8.8.4.4');
        Queue::assertPushed(DdnsSyncJob::class, 1);
        MachineAgentService::recordAddress($machine, '8.8.4.4');
        Queue::assertPushed(DdnsSyncJob::class, 1);
        $this->assertSame('8.8.4.4', $machine->fresh()->agent_runtime['public_ipv4']);
    }
}
