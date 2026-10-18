<?php

namespace Tests\Feature\Server;

use App\Http\Controllers\V2\Admin\Server\RealityScanController;
use App\Models\ServerMachine;
use App\Models\User;
use App\Services\MachineAgentService;
use App\Services\RealityScanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RealityScanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::store('redis')->flush();
        Route::match(['GET', 'POST'], '/_tests/reality-scan', [RealityScanController::class, 'scan'])->middleware('admin');
    }

    private function machine(): ServerMachine
    {
        $machine = ServerMachine::create(['name' => '检测测试服务器', 'token' => Str::random(32), 'is_active' => true]);
        MachineAgentService::exchange($machine, $this->report(), null);
        return $machine->fresh();
    }

    private function report(?array $scan = null): array
    {
        return ['version' => 'v2.12.0', 'boot_id' => 'test-process', 'manageable' => true,
            'reality_scan_supported' => true, 'reality_scan' => $scan];
    }

    private function admin(): void
    {
        $user = User::create(['email' => 'scan-admin@example.invalid', 'password' => password_hash(Str::random(32), PASSWORD_DEFAULT),
            'uuid' => Str::uuid()->toString(), 'token' => Str::random(32), 'is_admin' => true]);
        $this->actingAs($user, 'sanctum');
    }

    public function test_defaults_validation_and_maximum_addresses(): void
    {
        $this->assertCount(7, RealityScanService::targets(null));
        $this->assertNotContains('www.amazon.com:443', RealityScanService::targets(null));
        $this->assertNotContains('dl.google.com:443', RealityScanService::targets(null));
        $this->assertNotContains('www.cloudflare.com:443', RealityScanService::targets(null));
        $this->assertSame(['www.cloudflare.com:443'], RealityScanService::targets('www.cloudflare.com:443'));
        $this->assertSame(['example.com:8443', '[2606:4700::1111]:443'], RealityScanService::targets("example.com:8443\n[2606:4700::1111]:443"));
        foreach (['https://example.com', 'example.com:0', 'example.com:65536', '8.8.0.0/16', '8.8.8.0/24,9.9.9.0/24,1.1.1.1', 'example.com/path'] as $input) {
            try { RealityScanService::targets($input); $this->fail('必须拒绝无效目标'); }
            catch (ValidationException $e) { $this->assertArrayHasKey('targets', $e->errors()); }
        }
    }

    public function test_admin_guard_and_response_exclude_process_identity(): void
    {
        $machine = $this->machine();
        $this->postJson('/_tests/reality-scan', ['machine_id' => $machine->id])->assertForbidden();
        $this->admin();
        MachineAgentService::exchange($machine, $this->report(), null);
        $machine->refresh();
        $response = $this->postJson('/_tests/reality-scan', ['machine_id' => $machine->id])->assertOk()
            ->assertJsonPath('data.status', 'pending');
        $this->assertArrayNotHasKey('boot_id', $response->json('data'));
        $this->getJson('/_tests/reality-scan?machine_id=' . $machine->id)->assertOk()->assertJsonPath('data.id', $response->json('data.id'));
    }

    public function test_same_task_is_idempotent_and_different_task_is_busy(): void
    {
        $machine = $this->machine();
        $first = RealityScanService::start($machine, 'example.com');
        $this->assertSame($first['id'], RealityScanService::start($machine, 'example.com')['id']);
        $this->expectException(ValidationException::class);
        RealityScanService::start($machine, 'example.net');
    }

    public function test_result_round_trip_keeps_restart_command_and_requires_machine_auth(): void
    {
        $machine = $this->machine();
        $task = RealityScanService::start($machine, 'example.com');
        $operation = MachineAgentService::queue($machine->id, 'restart')['operation'];
        $auth = ['machine_id' => $machine->id, 'token' => $machine->token];
        $this->postJson('/api/v2/server/machine/control', $this->report() + ['machine_id' => $machine->id, 'token' => Str::random(32)])
            ->assertForbidden();
        $this->postJson('/api/v2/server/machine/control', $this->report() + $auth)->assertOk()
            ->assertJsonPath('reality_scan.id', $task['id'])->assertJsonPath('command.id', $operation['id']);
        $result = [
            'target' => 'example.com:443', 'host' => 'example.com', 'ip' => '8.8.8.8', 'port' => 443,
            'feasible' => true, 'tls13' => true, 'tls_version' => '1.3', 'h2' => true, 'alpn' => 'h2',
            'x25519' => true, 'curve' => 'X25519', 'cert_valid' => true, 'cert_chain_valid' => true,
            'cert_chain_bytes' => 3600, 'cert_subject' => 'example.com', 'cert_issuer' => 'test CA',
            'not_after' => '2027-01-01T00:00:00Z', 'server_names' => ['example.com'], 'latency_ms' => 35, 'reason' => '',
        ];
        $report = $this->report(['id' => $task['id'], 'status' => 'succeeded', 'results' => [$result]]);
        $this->postJson('/api/v2/server/machine/control', $report + $auth)->assertOk()->assertJsonPath('reality_scan', null)
            ->assertJsonPath('command.id', $operation['id']);
        $saved = RealityScanService::view($machine->id);
        $this->assertSame('succeeded', $saved['status']);
        $this->assertSame(35, $saved['results'][0]['latency_ms']);
        $this->postJson('/api/v2/server/machine/control', $report + $auth)->assertOk();
        $this->assertSame($saved, RealityScanService::view($machine->id));
        $next = RealityScanService::start($machine->fresh(), 'example.net');
        $this->postJson('/api/v2/server/machine/control', $report + $auth)->assertOk()->assertJsonPath('reality_scan.id', $next['id']);
        $other = $this->machine();
        $this->assertNull(RealityScanService::exchange($other, $report));
        $this->assertSame('pending', RealityScanService::view($machine->id)['status']);
    }

    public function test_probe_failures_can_be_reported_without_an_ip_or_certificate(): void
    {
        $machine = $this->machine();
        $task = RealityScanService::start($machine, '127.0.0.1');
        $result = ['target' => '127.0.0.1:443', 'host' => '', 'ip' => '', 'port' => 443,
            'feasible' => false, 'tls13' => false, 'tls_version' => '', 'h2' => false, 'alpn' => '',
            'x25519' => false, 'curve' => '', 'cert_valid' => false, 'cert_chain_valid' => false,
            'cert_chain_bytes' => 0, 'cert_subject' => '', 'cert_issuer' => '', 'not_after' => '',
            'server_names' => [], 'latency_ms' => 0, 'reason' => 'private_target'];
        $report = $this->report(['id' => $task['id'], 'status' => 'succeeded', 'results' => [$result]]);
        $this->postJson('/api/v2/server/machine/control', $report + ['machine_id' => $machine->id, 'token' => $machine->token])
            ->assertOk()->assertJsonPath('reality_scan', null);
        $this->assertSame('private_target', RealityScanService::view($machine->id)['results'][0]['reason']);
    }

    public function test_restart_expiry_and_old_agents_are_handled(): void
    {
        $machine = $this->machine();
        RealityScanService::start($machine, null);
        RealityScanService::exchange($machine, array_replace($this->report(), ['boot_id' => 'new-process']));
        $this->assertSame('node_restarted', RealityScanService::view($machine->id)['error']);
        $task = RealityScanService::start($machine, null);
        $task['expires_at'] = time() - 1;
        Cache::store('redis')->put('reality_scan:machine:' . $machine->id, $task, 300);
        $this->assertSame('timeout', RealityScanService::view($machine->id)['error']);
        MachineAgentService::exchange($machine, array_diff_key($this->report(), ['reality_scan_supported' => true]), null);
        $this->assertFalse($machine->fresh()->agent_runtime['reality_scan_supported']);
        $this->expectException(ValidationException::class);
        RealityScanService::start($machine->fresh(), null);
    }

    public function test_diagnostics_round_trip_and_local_location_do_not_change_feasibility(): void
    {
        \Illuminate\Support\Facades\Http::fake();
        $this->admin();
        $machine = $this->machine();
        $task = RealityScanService::start($machine, 'example.com');
        $auth = ['machine_id' => $machine->id, 'token' => $machine->token];
        $this->postJson('/api/v2/server/machine/control', $this->report() + $auth)
            ->assertOk()->assertJsonPath('reality_scan.diagnostics', true);
        $base = [
            'target' => 'example.com:443', 'host' => 'example.com', 'ip' => '8.8.8.8', 'port' => 443,
            'feasible' => true, 'tls13' => true, 'tls_version' => '1.3', 'h2' => true, 'alpn' => 'h2',
            'x25519' => true, 'curve' => 'X25519', 'cert_valid' => true, 'cert_chain_valid' => true,
            'cert_chain_bytes' => 3600, 'cert_subject' => 'example.com', 'cert_issuer' => 'test CA',
            'not_after' => '', 'server_names' => ['example.com'], 'latency_ms' => 35, 'reason' => '',
        ];
        $result = $base + [
            'cdn' => ['status' => 'detected', 'providers' => ['Cloudflare'], 'evidence' => ['HTTP: Cf-Ray']],
            'http' => ['status' => 'received', 'status_code' => 403],
        ];
        foreach ([
            array_replace($result, ['http' => ['status' => 'received']]),
            array_replace($result, ['http' => ['status' => 'received', 'status_code' => 700]]),
            array_replace($result, ['http' => ['status' => 'timeout', 'status_code' => 200]]),
            array_replace($result, ['cdn' => ['status' => 'detected', 'providers' => ['Cloudflare']]]),
            array_replace($result, ['cdn' => ['status' => 'detected', 'providers' => ['Cloudflare'], 'evidence' => [], 'cookie' => 'rejected']]),
        ] as $invalid) {
            $this->postJson('/api/v2/server/machine/control', $this->report([
                'id' => $task['id'], 'status' => 'succeeded', 'results' => [$invalid],
            ]) + $auth)->assertStatus(422);
            $this->assertSame('pending', RealityScanService::view($machine->id)['status']);
        }
        $results = [$result,
            array_replace($base, ['target' => 'timeout.example:443', 'ip' => '2606:4700:4700::1111',
                'cdn' => ['status' => 'unknown', 'providers' => [], 'evidence' => []], 'http' => ['status' => 'timeout']]),
            array_replace($base, ['target' => 'plain.example:443',
                'cdn' => ['status' => 'not_detected', 'providers' => [], 'evidence' => []], 'http' => ['status' => 'received', 'status_code' => 200]]),
        ];
        $report = $this->report(['id' => $task['id'], 'status' => 'succeeded', 'results' => $results]);
        $this->postJson('/api/v2/server/machine/control', $report + $auth)->assertOk();
        $saved = RealityScanService::view($machine->id);
        $this->postJson('/api/v2/server/machine/control', $report + $auth)->assertOk();
        $this->assertSame($saved, RealityScanService::view($machine->id));
        $response = $this->getJson('/_tests/reality-scan?machine_id=' . $machine->id)->assertOk()
            ->assertJsonPath('data.results.0.feasible', true)
            ->assertJsonPath('data.results.0.cdn.providers.0', 'Cloudflare')
            ->assertJsonPath('data.results.0.http.status_code', 403)
            ->assertJsonPath('data.results.0.location.source', 'ip2region')
            ->assertJsonPath('data.results.1.location.source', 'ip2region');
        $this->assertNotEmpty($response->json('data.results.0.location.region'));
        $this->assertNotEmpty($response->json('data.results.1.location.region'));
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }
}
