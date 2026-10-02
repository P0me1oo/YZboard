<?php

namespace Tests\Feature\Server;

use App\Models\ServerMachine;
use App\Models\ServerMachineLoadHistory;
use App\Services\MachineStateService;
use App\Services\RealtimeStateStore;
use App\Services\TelemetryDemand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MachineTelemetryTest extends TestCase
{
    use RefreshDatabase;

    public function test_idle_heartbeat_preserves_online_state_without_writing_history(): void
    {
        Cache::flush();
        $machine = ServerMachine::create(['name' => '按需采样测试', 'token' => 'telemetry-test-only', 'is_active' => true]);
        $store = app(RealtimeStateStore::class);
        $session = $store->begin('machine:' . $machine->id, str_repeat('a', 32));
        $service = app(MachineStateService::class);
        $heartbeat = ['epoch' => $session['epoch'], 'sequence' => 1, 'telemetry_mode' => 1, 'state' => []];
        $receipt = $service->accept($machine, $heartbeat);
        $this->assertSame(60, $receipt['telemetry']['detail_interval']);
        $this->assertTrue($store->read('machine:' . $machine->id)['fresh']);
        $this->assertSame(0, ServerMachineLoadHistory::count());
        $this->assertFalse($service->accept($machine, $heartbeat)['accepted']);

        app(TelemetryDemand::class)->renew(['machine:' . $machine->id]);
        $this->assertSame(1, $service->accept($machine, $heartbeat)['telemetry']['detail_interval']);
        $sample = $heartbeat;
        $sample['sequence'] = 2;
        $sample['state'] = ['status' => ['cpu' => 12, 'mem' => ['total' => 1024, 'used' => 256]]];
        $service->accept($machine, $sample);
        $this->assertSame(1, ServerMachineLoadHistory::count());
        $heartbeat['sequence'] = 3;
        $service->accept($machine, $heartbeat);
        $this->assertSame(1, ServerMachineLoadHistory::count());
        $this->assertSame(12, $machine->fresh()->load_status['cpu']);
        $this->assertSame([], $store->read('machine:' . $machine->id)['data']);
    }

    public function test_legacy_or_malformed_reports_cannot_become_empty_heartbeats(): void
    {
        $machine = ServerMachine::create(['name' => '校验测试', 'token' => 'telemetry-invalid-test-only', 'is_active' => true]);
        $store = app(RealtimeStateStore::class);
        $session = $store->begin('machine:' . $machine->id, str_repeat('b', 32));
        foreach ([['state' => []], ['telemetry_mode' => 1, 'state' => ['status' => []]],
            ['telemetry_mode' => 1, 'state' => ['status' => ['cpu' => 1]]]] as $body) {
            try {
                app(MachineStateService::class)->accept($machine, $body + ['epoch' => $session['epoch'], 'sequence' => 1]);
                $this->fail('错误报告不应接受');
            } catch (ValidationException) {
                $this->assertSame(0, $store->read('machine:' . $machine->id)['sequence']);
            }
        }
    }
}
