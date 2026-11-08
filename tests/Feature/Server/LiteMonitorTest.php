<?php

namespace Tests\Feature\Server;

use App\Http\Controllers\V2\Admin\Server\LiteController;
use App\Http\Middleware\Admin;
use App\Models\LiteConnection;
use App\Models\ServerMachine;
use App\Models\User;
use App\Services\LiteClient;
use App\Services\LiteMonitor;
use App\Services\MachineTrafficService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class LiteMonitorTest extends TestCase
{
    use RefreshDatabase;

    private string $uuid;
    private array $nodes;
    private array $status;
    private string $key;
    private bool $redirect = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->uuid = (string) Str::uuid();
        $this->key = Str::random(32);
        $this->nodes = [$this->node($this->uuid)];
        $this->status = [$this->uuid => $this->report()];
        $this->partialMock(LiteClient::class, fn ($mock) => $mock->shouldReceive('resolve')->andReturn(['8.8.8.8']));
        Http::preventStrayRequests();
        Http::fake(fn ($request) => $this->redirect
            ? Http::response(['error' => 'sensitive ' . $this->key], 302, ['Location' => 'https://elsewhere.example.test'])
            : Http::response(['jsonrpc' => '2.0', 'id' => 1,
            'result' => match ($request['method']) {
                'admin:listClients' => $this->nodes,
                'common:getNodes' => collect($this->nodes)->keyBy('uuid')->map(fn ($node) => $node + ['traffic_reset_at' => now()->addDays(4)->toIso8601String()])->all(),
                'common:getNodesLatestStatus' => $this->status,
                'common:getRecords' => ['records' => [((array) $request['params'])['uuid'] => [$this->report()]]],
            }]));
        Route::middleware(Admin::class)->group(function () {
            Route::get('/_tests/lite/config', [LiteController::class, 'config']);
            Route::post('/_tests/lite/config', [LiteController::class, 'save']);
            Route::post('/_tests/lite/bind', [LiteController::class, 'bind']);
            Route::get('/_tests/lite/snapshot', [LiteController::class, 'snapshot']);
        });
    }

    private function node(string $uuid, string $ipv4 = '8.8.4.4', string $ipv6 = ''): array
    {
        return ['uuid' => $uuid, 'name' => 'Lite server', 'ipv4' => $ipv4, 'ipv6' => $ipv6,
            'region' => 'US', 'region_override' => 'JP', 'os' => 'Debian', 'arch' => 'amd64', 'cpu_name' => 'CPU',
            'cpu_cores' => 4, 'group' => 'production', 'tags' => 'edge', 'token' => 'must-not-leak',
            'traffic_limit' => 1000, 'effective_traffic_limit' => 2000, 'effective_traffic_type' => 'max',
            'traffic_reset_day' => 9, 'traffic_reset_time' => '15:30:00', 'traffic_reset_timezone' => 'America/Los_Angeles'];
    }

    private function report(): array
    {
        return ['time' => now()->toIso8601String(), 'online' => true, 'cpu' => 32.5, 'ram' => 100, 'ram_total' => 1000,
            'disk' => 200, 'disk_total' => 2000, 'net_in' => 10, 'net_out' => 20, 'net_total_up' => 300, 'net_total_down' => 500];
    }

    private function configure(): LiteMonitor
    {
        $monitor = app(LiteMonitor::class);
        $monitor->save(['url' => 'https://lite.example.test', 'api_key' => $this->key, 'enabled' => true]);
        return $monitor;
    }

    private function machine(array $runtime = []): ServerMachine
    {
        return ServerMachine::create(['name' => 'Panel server', 'token' => Str::random(32), 'is_active' => true,
            'agent_runtime' => $runtime, 'traffic_limit' => 0]);
    }

    public function test_routes_require_admin_and_configuration_never_returns_plaintext_key(): void
    {
        $this->getJson('/_tests/lite/config')->assertForbidden();
        $this->postJson('/_tests/lite/config', [])->assertForbidden();
        $this->getJson('/_tests/lite/snapshot')->assertForbidden();
        $this->postJson('/_tests/lite/bind', [])->assertForbidden();
        $user = User::create(['email' => 'lite-test@example.test', 'password' => bcrypt(Str::random(20)),
            'uuid' => (string) Str::uuid(), 'token' => Str::random(32), 'is_admin' => true]);
        $this->actingAs($user, 'sanctum');
        $response = $this->postJson('/_tests/lite/config', ['url' => 'https://lite.example.test', 'api_key' => $this->key, 'enabled' => true]);
        $response->assertOk()->assertJsonPath('data.configured', true);
        $this->assertStringNotContainsString($this->key, $response->getContent());
        $this->assertNotSame($this->key, DB::table('v2_lite_connection')->value('api_key'));
        $this->postJson('/_tests/lite/config', ['url' => 'https://lite.example.test', 'enabled' => false])->assertOk();
        $this->assertSame($this->key, LiteConnection::find(1)->api_key);
        Http::assertNothingSent();
    }

    public function test_auto_binding_is_unique_idempotent_and_normalizes_ipv6(): void
    {
        $monitor = $this->configure();
        $one = $this->machine(['public_ip' => '8.8.4.4']);
        $other = (string) Str::uuid();
        $this->nodes[] = $this->node($other, '', '2001:4860:4860::8888');
        $two = $this->machine(['public_ipv6' => '2001:4860:4860:0:0:0:0:8888']);
        $this->assertSame(2, $monitor->bind(['action' => 'auto'])['count']);
        $this->assertSame(0, $monitor->bind(['action' => 'auto'])['count']);
        $this->assertDatabaseHas('v2_lite_machine', ['machine_id' => $one->id, 'uuid' => $this->uuid]);
        $this->assertDatabaseHas('v2_lite_machine', ['machine_id' => $two->id, 'uuid' => $other]);
        Http::assertSentCount(2);
    }

    public function test_shared_ips_and_multiple_remote_candidates_are_not_automatically_bound(): void
    {
        $monitor = $this->configure();
        $this->machine(['public_ip' => '8.8.4.4']);
        $this->machine(['public_ip' => '8.8.4.4']);
        $this->assertSame(0, $monitor->bind(['action' => 'auto'])['count']);
        $this->nodes[] = $this->node((string) Str::uuid());
        Cache::flush();
        $this->assertSame(0, $monitor->bind(['action' => 'auto'])['count']);
        $this->assertDatabaseCount('v2_lite_machine', 0);
    }

    public function test_import_is_idempotent_and_does_not_enable_local_traffic_protection(): void
    {
        $monitor = $this->configure();
        $result = $monitor->bind(['action' => 'import', 'uuids' => [$this->uuid]]);
        $machine = ServerMachine::findOrFail($result['created_ids'][0]);
        $this->assertSame(0, $machine->traffic_limit);
        $this->assertFalse(MachineTrafficService::blocked($machine));
        $this->assertNull($machine->last_seen_at);
        $this->assertSame(0, $monitor->bind(['action' => 'import', 'uuids' => [$this->uuid]])['count']);
        $this->assertDatabaseCount('v2_server_machine', 1);
        $machine->delete();
        $this->assertDatabaseCount('v2_lite_machine', 0);
    }

    public function test_snapshot_preserves_local_counters_and_uses_effective_quota_flags_and_timezones(): void
    {
        $monitor = $this->configure();
        $machine = $this->machine(['public_ip' => '8.8.4.4']);
        $machine->update(['traffic_limit' => 600, 'traffic_state' => ['rx' => 17, 'tx' => 19]]);
        $monitor->bind(['action' => 'auto']);
        $before = $machine->fresh()->getAttributes();
        $view = (array) $monitor->snapshot()['machines'];
        $this->assertSame('JP', $view[$machine->id]['info']['country']);
        $this->assertSame(500, $view[$machine->id]['traffic']['used']);
        $this->assertSame(2000, $view[$machine->id]['traffic']['limit']);
        $this->assertSame('America/Los_Angeles', $view[$machine->id]['traffic']['timezone']);
        $this->assertSame(9, $view[$machine->id]['traffic']['reset_day']);
        $this->assertSame(32.5, $view[$machine->id]['load_status']['cpu']);
        $this->assertStringNotContainsString('must-not-leak', json_encode($view));
        $this->assertSame($before, $machine->fresh()->getAttributes());
        $this->assertDatabaseCount('v2_server_machine_load_history', 0);
        $monitor->snapshot();
        Http::assertSentCount(3);
    }

    public function test_all_traffic_modes_and_missing_values_are_not_coerced_to_zero(): void
    {
        $monitor = $this->configure();
        $id = $monitor->bind(['action' => 'import', 'uuids' => [$this->uuid]])['created_ids'][0];
        foreach (['up' => 300, 'down' => 500, 'sum' => 800, 'max' => 500, 'min' => 300, 'bad' => null] as $mode => $expected) {
            Cache::flush();
            $this->nodes[0]['effective_traffic_type'] = $mode;
            $this->assertSame($expected, ((array) $monitor->snapshot()['machines'])[$id]['traffic']['used']);
        }
        Cache::flush();
        unset($this->status[$this->uuid]['net_total_down']);
        $this->assertNull(((array) $monitor->snapshot()['machines'])[$id]['traffic']['used']);
    }

    public function test_stale_status_history_and_ip_changes_keep_binding_without_claiming_online_load(): void
    {
        $monitor = $this->configure();
        $id = $monitor->bind(['action' => 'import', 'uuids' => [$this->uuid]])['created_ids'][0];
        $this->status[$this->uuid]['time'] = now()->subHour()->toIso8601String();
        $snapshot = ((array) $monitor->snapshot()['machines'])[$id];
        $this->assertFalse($snapshot['fresh']);
        $this->assertNull($snapshot['load_status']);
        $history = $monitor->history($id, 6, 100);
        $this->assertCount(1, $history);
        $this->assertSame(20, $history[0]['net_out_speed']);
        Cache::flush();
        $this->nodes[0]['ipv4'] = '1.1.1.1';
        $this->assertSame('1.1.1.1', ((array) $monitor->snapshot()['machines'])[$id]['info']['ipv4']);
        $this->assertDatabaseHas('v2_lite_machine', ['machine_id' => $id, 'uuid' => $this->uuid]);
    }

    public function test_rebinding_conflict_is_rejected_and_unbind_works_with_disabled_or_unreachable_lite(): void
    {
        $monitor = $this->configure();
        $id = $monitor->bind(['action' => 'import', 'uuids' => [$this->uuid]])['created_ids'][0];
        $other = $this->machine();
        try {
            $monitor->bind(['action' => 'bind', 'machine_id' => $other->id, 'uuid' => $this->uuid]);
            $this->fail('绑定冲突应拒绝');
        } catch (\App\Exceptions\ApiException $error) { $this->assertSame(409, $error->getCode()); }
        try {
            $monitor->save(['url' => 'https://another.example.test', 'enabled' => true]);
            $this->fail('已有绑定不能更换站点');
        } catch (\App\Exceptions\ApiException $error) { $this->assertSame(409, $error->getCode()); }
        $monitor->save(['url' => 'https://lite.example.test', 'enabled' => false]);
        $this->assertFalse($monitor->snapshot()['enabled']);
        $this->assertSame(1, $monitor->bind(['action' => 'unbind', 'machine_id' => $id])['count']);
        $monitor->save(['url' => 'https://another.example.test', 'enabled' => true]);
    }

    public function test_lite_requests_cannot_redirect_or_leak_remote_errors_and_only_read_fixed_methods(): void
    {
        $monitor = $this->configure();
        $monitor->nodes();
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer ' . $this->key)
            && $request->url() === 'https://lite.example.test/api/rpc2' && $request['method'] === 'admin:listClients');
        Cache::flush();
        $this->redirect = true;
        try { $monitor->nodes(); $this->fail('重定向应拒绝'); }
        catch (\App\Exceptions\ApiException $error) {
            $this->assertSame(502, $error->getCode());
            $this->assertStringNotContainsString($this->key, $error->getMessage());
            $this->assertNull($error->getPrevious());
        }
    }

    public function test_url_and_private_network_addresses_are_rejected(): void
    {
        $client = new LiteClient();
        foreach (['http://example.test', 'https://user:pass@example.test', 'https://example.test?key=secret', 'https://example.test/#x'] as $url) {
            try { $client->normalizeUrl($url); $this->fail('非法 URL 应拒绝'); }
            catch (\App\Exceptions\ApiException $error) { $this->assertSame(422, $error->getCode()); }
        }
        foreach (['127.0.0.1', '10.0.0.1', '169.254.169.254', '100.64.0.1', '100.127.255.254',
            '192.0.0.8', '192.0.0.170', '192.0.2.1', '198.18.0.1', '198.51.100.1', '203.0.113.1',
            '224.0.0.1', '240.0.0.1', '[::1]', '[::ffff:127.0.0.1]', '[0:0:0:0:0:ffff:7f00:1]',
            '[64:ff9b::7f00:1]', '[64:ff9b:1::1]', '[fc00::1]', '[fe80::1]', '[ff02::1]',
            '[2001:db8::1]', '[2001:2::1]', '[2002:7f00:1::1]', '[3fff::1]'] as $host) {
            try { $client->resolve($host); $this->fail('内网地址应拒绝'); }
            catch (\App\Exceptions\ApiException $error) { $this->assertSame(422, $error->getCode()); }
        }
        foreach (['8.8.8.8', '1.1.1.1', '100.63.255.254', '100.128.0.1', '[2001:4860:4860::8888]', '[2606:4700:4700::1111]'] as $host) {
            $this->assertSame([trim($host, '[]')], $client->resolve($host));
        }
    }

    public function test_shared_addresses_are_not_used_for_automatic_binding(): void
    {
        $monitor = $this->configure();
        $this->nodes[0]['ipv4'] = '100.64.0.1';
        $this->machine(['public_ip' => '100.64.0.1']);
        $this->assertSame(0, $monitor->bind(['action' => 'auto'])['count']);
    }

    public function test_concurrent_reader_waits_for_existing_result_beyond_two_seconds(): void
    {
        $monitor = $this->configure();
        $key = (new \ReflectionMethod($monitor, 'cacheKey'))->invoke($monitor, $monitor->connection(), 'nodes');
        $lock = Cache::lock($key . ':lock', 35);
        $this->assertTrue($lock->get());
        $result = [$this->uuid => ['name' => 'shared result']];
        $ready = now()->addSeconds(3);
        Sleep::fake(syncWithCarbon: true);
        Sleep::whenFakingSleep(function () use ($ready, $key, $result) {
            if (now()->gte($ready)) Cache::put($key, $result, 60);
        });
        try {
            $this->assertSame($result, $monitor->nodes());
            Http::assertNothingSent();
        } finally {
            Sleep::fake(false);
            $this->travelBack();
            $lock->release();
        }
    }

    public function test_busy_cache_returns_retryable_error_and_recovers(): void
    {
        $monitor = $this->configure();
        $this->machine(['public_ip' => '8.8.4.4']);
        $monitor->bind(['action' => 'auto']);
        Cache::flush();
        $key = (new \ReflectionMethod($monitor, 'cacheKey'))->invoke($monitor, $monitor->connection(), 'nodes');
        $lock = Cache::lock($key . ':lock', 35);
        $this->assertTrue($lock->get());
        $this->withoutMiddleware(Admin::class);
        Sleep::fake(syncWithCarbon: true);
        try {
            $this->getJson('/_tests/lite/snapshot')->assertStatus(503)
                ->assertJsonPath('message', 'Lite 正在读取，请稍后重试。');
            Http::assertSentCount(2);
        } finally {
            Sleep::fake(false);
            $this->travelBack();
            $lock->release();
        }
        $this->getJson('/_tests/lite/snapshot')->assertOk();
    }
}
