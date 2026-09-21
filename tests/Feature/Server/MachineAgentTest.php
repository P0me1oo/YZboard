<?php

namespace Tests\Feature\Server;

use App\Models\ServerMachine;
use App\Services\MachineAgentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MachineAgentTest extends TestCase
{
    use RefreshDatabase;

    private function machine(): ServerMachine
    {
        return ServerMachine::create(['name' => '测试服务器', 'token' => Str::random(32), 'is_active' => true]);
    }

    public function test_admin_locale_urls_include_current_content_hash(): void
    {
        $html = view('admin', ['title' => '测试面板', 'version' => 'test', 'logo' => '', 'secure_path' => 'test-admin'])->render();
        foreach (['zh-CN', 'en-US', 'ru-RU'] as $locale) {
            $file = public_path("assets/admin/locales/{$locale}.js");
            $url = "/assets/admin/locales/{$locale}.js?v=" . hash_file('sha256', $file);
            $this->assertStringContainsString($url, $html);
            $this->assertLessThan(strpos($html, '<script type="module"'), strpos($html, $url));
        }
    }

    private function report(string $boot = 'first-process', ?array $operation = null): array
    {
        return ['version' => 'v1.16.0', 'boot_id' => $boot, 'manageable' => true, 'operation' => $operation];
    }

    public function test_reports_runtime_and_public_ip_without_accepting_private_ip(): void
    {
        $machine = $this->machine();
        MachineAgentService::exchange($machine, $this->report(), '8.8.8.8');
        $this->assertSame('8.8.8.8', $machine->fresh()->agent_runtime['public_ip']);
        $this->assertSame('v1.16.0', $machine->fresh()->agent_runtime['version']);
        MachineAgentService::exchange($machine, $this->report(), '10.0.0.1');
        $this->assertNull($machine->fresh()->agent_runtime['public_ip']);
        MachineAgentService::exchange($machine, $this->report(), '2606:4700:4700::1111');
        $this->assertSame('2606:4700:4700::1111', $machine->fresh()->agent_runtime['public_ip']);
    }

    public function test_operation_is_idempotent_and_requires_new_process_for_success(): void
    {
        $machine = $this->machine();
        MachineAgentService::exchange($machine, $this->report(), null);
        $operation = MachineAgentService::queue($machine->id, 'restart')['operation'];
        $this->assertSame('busy', MachineAgentService::queue($machine->id, 'upgrade')['error']);
        $this->assertSame($operation['id'], MachineAgentService::exchange($machine, $this->report(), null)['id']);
        $result = ['id' => $operation['id'], 'status' => 'succeeded'];
        $this->assertSame('running', MachineAgentService::exchange($machine, $this->report('first-process', $result), null)['status']);
        $this->assertNull(MachineAgentService::exchange($machine, $this->report('new-process', $result), null));
        $this->assertSame('succeeded', $machine->fresh()->agent_operation['status']);
        // 迟到的失败回报不能覆盖已确认的成功。
        $result['status'] = 'failed';
        MachineAgentService::exchange($machine, $this->report('new-process', $result), null);
        $this->assertSame('succeeded', $machine->fresh()->agent_operation['status']);
    }

    public function test_old_offline_disabled_and_missing_servers_are_rejected_individually(): void
    {
        $machine = $this->machine();
        $this->assertSame('offline', MachineAgentService::queue($machine->id, 'upgrade')['error']);
        MachineAgentService::exchange($machine, [...$this->report(), 'manageable' => false], null);
        $this->assertSame('unsupported', MachineAgentService::queue($machine->id, 'upgrade')['error']);
        $machine->update(['is_active' => false]);
        $this->assertSame('disabled', MachineAgentService::queue($machine->id, 'restart')['error']);
        $this->assertSame('not_found', MachineAgentService::queue(999999, 'restart')['error']);
    }

    public function test_failure_timeout_and_unrelated_results_do_not_trigger_retries(): void
    {
        $machine = $this->machine();
        MachineAgentService::exchange($machine, $this->report(), null);
        $operation = MachineAgentService::queue($machine->id, 'upgrade')['operation'];
        MachineAgentService::exchange($machine, $this->report('first-process', ['id' => (string) Str::uuid(), 'status' => 'succeeded']), null);
        $this->assertSame('pending', $machine->fresh()->agent_operation['status']);
        MachineAgentService::exchange($machine, $this->report('first-process', ['id' => $operation['id'], 'status' => 'failed', 'error' => 'execution_failed']), null);
        $this->assertSame('failed', $machine->fresh()->agent_operation['status']);
        $operation = MachineAgentService::queue($machine->id, 'restart')['operation'];
        $operation['expires_at'] = time() - 1;
        $machine->update(['agent_operation' => $operation]);
        $this->assertNull(MachineAgentService::exchange($machine, $this->report(), null));
        $this->assertSame('timeout', $machine->fresh()->agent_operation['status']);
    }

    public function test_control_endpoint_authenticates_before_changing_machine(): void
    {
        $machine = $this->machine();
        $this->postJson('/api/v2/server/machine/control', [...$this->report(), 'machine_id' => $machine->id, 'token' => Str::random(32)])->assertForbidden();
        $this->assertNull($machine->fresh()->agent_runtime);
        $this->postJson('/api/v2/server/machine/control', [...$this->report(), 'machine_id' => $machine->id, 'token' => $machine->token])->assertOk();
        $this->assertSame('v1.16.0', $machine->fresh()->agent_runtime['version']);
    }

    public function test_batch_endpoint_preserves_success_when_another_server_is_offline(): void
    {
        $online = $this->machine();
        $offline = $this->machine();
        MachineAgentService::exchange($online, $this->report(), null);
        $response = app(\App\Http\Controllers\V2\Admin\Server\MachineController::class)->operate(
            \Illuminate\Http\Request::create('/', 'POST', ['ids' => [$online->id, $offline->id], 'action' => 'upgrade'])
        );
        $results = $response->getData(true)['data'];
        $this->assertSame('pending', $results[0]['operation']['status']);
        $this->assertSame('offline', $results[1]['error']);
        $this->assertNull($offline->fresh()->agent_operation);
    }

    public function test_batch_endpoint_rejects_arbitrary_actions(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(\App\Http\Controllers\V2\Admin\Server\MachineController::class)->operate(
            \Illuminate\Http\Request::create('/', 'POST', ['ids' => [1], 'action' => 'reboot'])
        );
    }
}
