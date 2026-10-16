<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\User;
use App\Services\ProxyAccessLog;
use App\Support\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProxyAccessLogTest extends TestCase
{
    use RefreshDatabase;
    private Server $node;
    private User $user;
    private string $credential;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'Asia/Shanghai']);
        $this->travelTo(\Carbon\Carbon::parse('2026-10-16 15:00:00', 'Asia/Shanghai'));
        $this->credential = Str::random(32);
        $credential = $this->credential;
        $this->mock(Setting::class, fn ($mock) => $mock->shouldReceive('get')->andReturnUsing(fn ($key) => $key === 'server_token' ? $credential : null));
        $this->node = Server::create(['name' => '访问测试节点', 'type' => 'vless', 'host' => '127.0.0.1', 'port' => 443,
            'server_port' => 443, 'rate' => 1, 'group_ids' => [], 'enabled' => true]);
        $this->user = $this->makeUser();
        Sanctum::actingAs($this->makeUser(true));
    }

    private function makeUser(bool $admin = false): User
    {
        return User::create(['email' => Str::random(12) . '@example.test', 'password' => Str::random(32),
            'uuid' => Str::uuid()->toString(), 'token' => Str::random(32), 'is_admin' => $admin]);
    }

    private function batch(array $changes = []): array
    {
        return ['batch_id' => bin2hex(random_bytes(16)), 'records' => [array_replace([
            'user_id' => $this->user->id, 'connected_at' => now()->timestamp - 10, 'destination' => 'Example.COM.', 'port' => 443,
        ], $changes)]];
    }

    private function push(array $batch)
    {
        return $this->postJson('/api/v2/server/access-log', $batch + ['node_id' => $this->node->id, 'token' => $this->credential]);
    }

    private function fetch(array $params = [])
    {
        return $this->getJson('/api/v2/' . hash('crc32b', config('app.key')) . '/statistics/accessLogs?' . http_build_query($params));
    }

    public function test_retry_is_idempotent_and_does_not_change_traffic_or_history(): void
    {
        $batch = $this->batch();
        $batch['records'][] = $batch['records'][0];
        $batch['records'][1]['destination'] = '2001:0db8::1';
        $this->push($batch)->assertOk()->assertJsonPath('data.batch_id', $batch['batch_id']);
        $this->push($batch)->assertOk();
        $this->assertDatabaseCount('v2_proxy_access_log', 2);
        $this->assertSame(0, (int) $this->user->fresh()->u);
        $this->assertSame(0, (int) $this->user->fresh()->d);
        $result = $this->fetch()->assertOk()->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.list.0.destination', '2001:db8::1')
            ->assertJsonPath('data.list.1.destination', 'example.com')
            ->assertJsonPath('data.list.0.user_name', $this->user->email)
            ->assertJsonPath('data.list.0.connected_at', '2026-10-16T14:59:50+08:00');
        $this->node->update(['name' => '重命名节点']);
        $this->push($batch)->assertOk();
        $this->assertDatabaseHas('v2_proxy_access_log', ['server_name' => '访问测试节点']);
        $this->assertEqualsCanonicalizing(['id', 'user_id', 'user_name', 'server_id', 'server_name', 'connected_at', 'destination', 'port'], array_keys($result->json('data.list.0')));
    }

    public function test_rejects_urls_content_invalid_hosts_and_future_dates_before_any_write(): void
    {
        foreach (['https://example.test/path?q=x', 'example.test/path', 'user@example.test', "example.test\n", '999.0.0.1', '[::1]', 'fe80::1%eth0'] as $host) {
            $batch = $this->batch();
            $batch['records'][] = $this->batch(['destination' => $host])['records'][0];
            $this->push($batch)->assertUnprocessable();
        }
        $this->push($this->batch(['path' => '/private']))->assertUnprocessable();
        $this->push($this->batch(['connected_at' => now()->addMinutes(6)->timestamp]))->assertUnprocessable();
        $this->push($this->batch(['port' => 65536]))->assertUnprocessable();
        $this->assertDatabaseCount('v2_proxy_access_log', 0);
    }

    public function test_permissions_machine_scope_and_handshake(): void
    {
        $this->postJson('/api/v2/server/access-log', $this->batch() + ['node_id' => $this->node->id])->assertUnprocessable();
        $this->postJson('/api/v2/server/handshake', ['token' => $this->credential])->assertOk()->assertJsonPath('access_log.version', 1);
        $machine = ServerMachine::create(['name' => '测试机', 'token' => Str::random(32), 'is_active' => true]);
        $payload = $this->batch() + ['machine_id' => $machine->id, 'node_id' => $this->node->id, 'token' => $machine->token];
        $this->postJson('/api/v2/server/access-log', $payload)->assertStatus(400);
        $this->node->update(['machine_id' => $machine->id]);
        $this->postJson('/api/v2/server/access-log', $payload)->assertOk();
        Sanctum::actingAs($this->user);
        $this->fetch()->assertForbidden();
    }

    public function test_filters_pagination_and_snapshot_exclude_later_arrivals(): void
    {
        $this->push($this->batch())->assertOk();
        $this->push($this->batch(['destination' => '203.0.113.5', 'port' => 80, 'connected_at' => now()->subDay()->timestamp]))->assertOk();
        $this->fetch(['target' => '203.0.113'])->assertOk()->assertJsonPath('data.total', 0);
        $first = $this->fetch(['period' => '7d', 'page_size' => 1])->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.total', 2)->assertJsonPath('data.last_page', 2)->json('data');
        $this->push($this->batch(['destination' => 'new.example']))->assertOk();
        $this->fetch(['period' => '7d', 'page_size' => 1, 'page' => 2, 'snapshot_id' => $first['snapshot_id']])->assertOk()
            ->assertJsonPath('data.total', 2)->assertJsonPath('data.list.0.destination', '203.0.113.5');
        $this->fetch(['period' => '7d', 'user' => $this->user->id, 'node' => $this->node->id, 'port' => 80])->assertOk()->assertJsonPath('data.total', 1);
        $this->fetch(['target' => '%'])->assertOk()->assertJsonPath('data.total', 0);
        $this->fetch(['target' => 'EXAMPLE.COM'])->assertOk()->assertJsonPath('data.total', 1);
        $this->fetch(['period' => 'custom', 'start_at' => '2026-10-15T14:59', 'end_at' => '2026-10-15T15:01'])->assertOk()->assertJsonPath('data.total', 1);
        $this->fetch(['period' => 'custom', 'start_at' => '2026-10-01T00:00', 'end_at' => '2026-10-15T00:00'])->assertUnprocessable();
    }

    public function test_retention_prunes_and_expired_retry_does_not_restore_records(): void
    {
        $batch = $this->batch(['connected_at' => now()->subDays(7)->timestamp]);
        $this->push($batch)->assertOk();
        $this->assertDatabaseCount('v2_proxy_access_log', 1);
        $this->travel(1)->seconds();
        $this->fetch(['period' => '7d'])->assertOk()->assertJsonPath('data.total', 0);
        $this->artisan('access-log:prune')->assertSuccessful();
        $this->push($batch)->assertOk();
        $this->assertDatabaseCount('v2_proxy_access_log', 0);
    }

    public function test_database_failure_can_be_retried_and_same_batch_is_scoped_to_node(): void
    {
        $batch = $this->batch();
        $this->failNextQueryMatching('/insert into.*v2_proxy_access_log/i');
        $this->push($batch)->assertStatus(500);
        $this->assertDatabaseCount('v2_proxy_access_log', 0);
        $this->push($batch)->assertOk();
        $other = $this->node->replicate(); $other->name = '另一个节点'; $other->save();
        app(ProxyAccessLog::class)->accept($other, $batch);
        $this->assertDatabaseCount('v2_proxy_access_log', 2);
        $this->user->delete(); $this->node->delete();
        $this->fetch()->assertOk()->assertJsonPath('data.list.0.user_name', '#' . $batch['records'][0]['user_id']);
    }
}
