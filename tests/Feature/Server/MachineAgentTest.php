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

    public function test_address_endpoint_records_each_family_and_control_keeps_the_other(): void
    {
        $machine = $this->machine();
        $auth = ['machine_id' => $machine->id, 'token' => $machine->token];
        $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])
            ->postJson('/api/v2/server/machine/address', $auth)->assertOk()->assertJsonPath('ip', '8.8.8.8');
        $this->withServerVariables(['REMOTE_ADDR' => '2606:4700:4700::1111'])
            ->postJson('/api/v2/server/machine/address', $auth)->assertOk()->assertJsonPath('ip', '2606:4700:4700::1111');
        // 控制请求默认走 IPv6，不能把单独回报的 IPv4 覆盖掉。
        MachineAgentService::exchange($machine, $this->report(), '2001:4860:4860::8888');
        $runtime = $machine->fresh()->agent_runtime;
        $this->assertSame('8.8.8.8', $runtime['public_ipv4']);
        $this->assertSame('2001:4860:4860::8888', $runtime['public_ipv6']);
        $this->assertSame('2001:4860:4860::8888', $runtime['public_ip']);
        $this->assertSame('v1.16.0', $runtime['version']);
        // 双栈监听给出的 IPv4 映射地址按 IPv4 记录。
        MachineAgentService::exchange($machine, $this->report(), '::ffff:8.8.4.4');
        $runtime = $machine->fresh()->agent_runtime;
        $this->assertSame('8.8.4.4', $runtime['public_ipv4']);
        $this->assertSame('8.8.4.4', $runtime['public_ip']);
        $this->assertSame('2001:4860:4860::8888', $runtime['public_ipv6']);
    }

    public function test_address_endpoint_requires_machine_auth_and_ignores_non_public_sources(): void
    {
        $machine = $this->machine();
        $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])
            ->postJson('/api/v2/server/machine/address', ['machine_id' => $machine->id, 'token' => Str::random(32)])
            ->assertForbidden();
        $this->assertNull($machine->fresh()->agent_runtime);
        foreach (['10.0.0.1', '127.0.0.1', '::1', 'fe80::1', 'fd00::1'] as $ip) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip])
                ->postJson('/api/v2/server/machine/address', ['machine_id' => $machine->id, 'token' => $machine->token])
                ->assertOk()->assertJsonPath('ip', null);
        }
        $this->assertNull($machine->fresh()->agent_runtime);
    }

    public function test_admin_list_hides_only_the_address_family_that_stopped_reporting(): void
    {
        $machine = $this->machine();
        $fetch = fn () => app(\App\Http\Controllers\V2\Admin\Server\MachineController::class)
            ->fetch(\Illuminate\Http\Request::create('/', 'GET'))->getData(true)['data'][0]['agent_runtime'];
        $runtime = fn (int $ipv4At, int $ipv6At) => [
            'version' => 'v1.19.0', 'boot_id' => 'process', 'manageable' => true, 'reported_at' => max($ipv4At, $ipv6At),
            'public_ip' => '8.8.8.8',
            'public_ipv4' => '8.8.8.8', 'public_ipv4_at' => $ipv4At,
            'public_ipv6' => '2606:4700:4700::1111', 'public_ipv6_at' => $ipv6At,
        ];
        $now = time();
        $machine->update(['agent_runtime' => $runtime($now, $now - MachineAgentService::ADDRESS_STALE_SECONDS - 1)]);
        $this->assertSame('8.8.8.8', $fetch()['public_ipv4']);
        $this->assertNull($fetch()['public_ipv6']);
        // 整台服务器离线时两种地址一起停止回报，保留最后一次的结果。
        $machine->update(['agent_runtime' => $runtime($now - 7200, $now - 7260)]);
        $this->assertSame('8.8.8.8', $fetch()['public_ipv4']);
        $this->assertSame('2606:4700:4700::1111', $fetch()['public_ipv6']);
        // 只有控制请求回报过一种地址族的旧 agent，另一种直接为空。
        $machine->update(['agent_runtime' => null]);
        MachineAgentService::exchange($machine, $this->report(), '2606:4700:4700::1111');
        $this->assertNull($fetch()['public_ipv4']);
        $this->assertSame('2606:4700:4700::1111', $fetch()['public_ipv6']);
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

    public function test_upgrade_noop_results_finish_without_a_new_process_and_allow_another_operation(): void
    {
        foreach (['up_to_date', 'current_newer'] as $code) {
            $machine = $this->machine();
            MachineAgentService::exchange($machine, $this->report(), null);
            $operation = MachineAgentService::queue($machine->id, 'upgrade')['operation'];
            $result = ['id' => $operation['id'], 'status' => 'succeeded', 'result' => $code];
            $this->postJson('/api/v2/server/machine/control', [
                ...$this->report('first-process', $result), 'machine_id' => $machine->id, 'token' => $machine->token,
            ])->assertOk()->assertJsonPath('command', null);
            $this->assertSame('succeeded', $machine->fresh()->agent_operation['status']);
            $this->assertSame($code, $machine->fresh()->agent_operation['result']);
            // 重复与迟到的失败结果不得改变已确认结果。
            MachineAgentService::exchange($machine, $this->report('first-process', $result), null);
            MachineAgentService::exchange($machine, $this->report('first-process', [...$result, 'status' => 'failed']), null);
            $this->assertSame('succeeded', $machine->fresh()->agent_operation['status']);
            $next = MachineAgentService::queue($machine->id, 'restart')['operation'];
            $this->assertSame('pending', $next['status']);
            $this->assertArrayNotHasKey('result', $next);
        }
    }

    public function test_updated_and_restart_results_still_require_a_new_process(): void
    {
        foreach ([['upgrade', 'updated'], ['upgrade', null], ['restart', 'up_to_date'], ['restart', 'current_newer']] as [$action, $code]) {
            $machine = $this->machine();
            MachineAgentService::exchange($machine, $this->report(), null);
            $operation = MachineAgentService::queue($machine->id, $action)['operation'];
            $result = ['id' => $operation['id'], 'status' => 'succeeded', 'result' => $code];
            $this->assertSame('running', MachineAgentService::exchange($machine, $this->report('first-process', $result), null)['status']);
            $this->assertNull(MachineAgentService::exchange($machine, $this->report('new-process', $result), null));
            $this->assertSame('succeeded', $machine->fresh()->agent_operation['status']);
        }
    }

    public function test_upgrade_failure_codes_are_validated_and_preserved(): void
    {
        foreach (['release_query_failed', 'current_version_failed', 'current_version_invalid', 'latest_version_invalid'] as $code) {
            $machine = $this->machine();
            MachineAgentService::exchange($machine, $this->report(), null);
            $operation = MachineAgentService::queue($machine->id, 'upgrade')['operation'];
            $result = ['id' => $operation['id'], 'status' => 'failed', 'error' => $code];
            $this->postJson('/api/v2/server/machine/control', [
                ...$this->report('first-process', $result), 'machine_id' => $machine->id, 'token' => $machine->token,
            ])->assertOk()->assertJsonPath('command', null);
            $this->assertSame($code, $machine->fresh()->agent_operation['error']);
            $this->assertSame('failed', $machine->fresh()->agent_operation['status']);
        }
        $this->postJson('/api/v2/server/machine/control', [
            ...$this->report('first-process', ['id' => $operation['id'], 'status' => 'succeeded', 'result' => 'arbitrary-result']),
            'machine_id' => $machine->id, 'token' => $machine->token,
        ])->assertUnprocessable();
    }
}
