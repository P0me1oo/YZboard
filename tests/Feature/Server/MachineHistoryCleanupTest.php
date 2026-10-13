<?php

namespace Tests\Feature\Server;

use App\Models\ServerMachine;
use App\Models\ServerMachineLoadHistory;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MachineHistoryCleanupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
    }

    private function machine(bool $active = true): ServerMachine
    {
        return ServerMachine::create(['name' => '历史清理测试', 'token' => Str::random(32), 'is_active' => $active,
            'last_seen_at' => now()->timestamp, 'load_status' => ['cpu' => 12]]);
    }

    private function sample(ServerMachine $machine, int $at): ServerMachineLoadHistory
    {
        return ServerMachineLoadHistory::create(['machine_id' => $machine->id, 'cpu' => 12,
            'mem_total' => 1024, 'mem_used' => 128, 'recorded_at' => $at]);
    }

    public function test_expired_history_is_removed_for_all_machines_without_changing_live_state(): void
    {
        $active = $this->machine();
        $inactive = $this->machine(false);
        $before = [$active->fresh()->getAttributes(), $inactive->fresh()->getAttributes()];
        $old = $this->sample($active, now()->subHours(24)->subSecond()->timestamp);
        $oldInactive = $this->sample($inactive, now()->subDays(10)->timestamp);
        $boundary = $this->sample($active, now()->subHours(24)->timestamp);
        $recent = $this->sample($inactive, now()->subMinute()->timestamp);

        $this->assertSame(0, Artisan::call('machine:prune-history'));
        $this->assertNull($old->fresh());
        $this->assertNull($oldInactive->fresh());
        $this->assertNotNull($boundary->fresh());
        $this->assertNotNull($recent->fresh());
        $this->assertSame($before, [$active->fresh()->getAttributes(), $inactive->fresh()->getAttributes()]);
        Artisan::call('machine:prune-history');
        $this->assertStringContainsString('清理 0 条', Artisan::output());
    }

    public function test_cleanup_resumes_from_oldest_samples_after_reaching_batch_limit(): void
    {
        $machine = $this->machine();
        $newer = $this->sample($machine, now()->subDays(2)->timestamp);
        $oldest = $this->sample($machine, now()->subDays(10)->timestamp);
        Artisan::call('machine:prune-history', ['--batch' => 1, '--batches' => 1]);
        $this->assertNull($oldest->fresh());
        $this->assertNotNull($newer->fresh());
        Artisan::call('machine:prune-history', ['--batch' => 1, '--batches' => 1]);
        $this->assertNull($newer->fresh());
    }

    public function test_delete_failure_keeps_history_and_next_run_recovers(): void
    {
        $old = $this->sample($this->machine(), now()->subDays(2)->timestamp);
        $this->failNextQueryMatching('/delete from ["`]v2_server_machine_load_history["`]/i');
        try {
            Artisan::call('machine:prune-history');
            $this->fail('删除失败应当向调用方报错');
        } catch (QueryException) {
            $this->assertNotNull($old->fresh());
        }
        Artisan::call('machine:prune-history');
        $this->assertNull($old->fresh());
    }

    public function test_history_api_hides_expired_data_even_before_cleanup_runs(): void
    {
        Sanctum::actingAs(User::create(['email' => Str::random(12) . '@example.test',
            'password' => Str::random(32), 'uuid' => (string) Str::uuid(), 'token' => Str::random(32), 'is_admin' => true]));
        $machine = $this->machine();
        $this->sample($machine, now()->subHours(24)->subSecond()->timestamp);
        $boundary = $this->sample($machine, now()->subHours(24)->timestamp);
        $recent = $this->sample($machine, now()->subMinutes(20)->timestamp);
        $path = '/api/v2/' . hash('crc32b', config('app.key')) . '/server/machine/history?machine_id=' . $machine->id;

        $this->getJson($path)->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.recorded_at', $boundary->recorded_at)
            ->assertJsonPath('data.1.recorded_at', $recent->recorded_at);
        $this->getJson($path . '&range_hours=1')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson($path . '&range_hours=25')->assertStatus(422);
        $this->assertSame(3, ServerMachineLoadHistory::count());
    }

    public function test_legacy_and_realtime_reports_share_scheduled_history_retention(): void
    {
        $machine = $this->machine();
        $old = $this->sample($machine, now()->subDays(2)->timestamp);
        $status = ['cpu' => 20, 'mem' => ['total' => 1024, 'used' => 256]];
        $this->postJson('/api/v2/server/machine/status', $status + ['machine_id' => $machine->id, 'token' => $machine->token])
            ->assertOk();
        $store = app(\App\Services\RealtimeStateStore::class);
        $session = $store->begin('machine:' . $machine->id, bin2hex(random_bytes(16)));
        app(\App\Services\MachineStateService::class)->accept($machine, [
            'epoch' => $session['epoch'], 'sequence' => 1, 'state' => ['status' => $status],
        ]);
        $this->assertSame(3, ServerMachineLoadHistory::count());
        Artisan::call('machine:prune-history');
        $this->assertNull($old->fresh());
        $this->assertSame(2, ServerMachineLoadHistory::count());
        $this->assertSame(20, $machine->fresh()->load_status['cpu']);
    }
}
