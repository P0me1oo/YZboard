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
            'node_id' => $this->node->id,
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

    public function test_relay_records_use_only_the_selected_landing_and_direct_exit(): void
    {
        $first = $this->node->replicate(); $first->name = '落地甲'; $first->relay_entry_id = $this->node->id; $first->save();
        $second = $first->replicate(); $second->name = '落地乙'; $second->save();
        $batch = $this->batch(['node_id' => $first->id]);
        $batch['records'][] = $this->batch(['node_id' => $second->id])['records'][0];
        $batch['records'][] = $this->batch()['records'][0];
        $this->push($batch)->assertOk();
        $this->push($batch)->assertOk();
        $this->assertDatabaseCount('v2_proxy_access_log', 3);
        foreach ([$first, $second, $this->node] as $exit) {
            $this->fetch(['node' => (string) $exit->id])->assertOk()->assertJsonPath('data.total', 1)
                ->assertJsonPath('data.list.0.server_id', $exit->id)->assertJsonPath('data.list.0.server_name', $exit->name);
        }
        $this->fetch(['view' => 'targets', 'user_id' => $this->user->id])->assertOk()
            ->assertJsonPath('data.total', 1)->assertJsonPath('data.list.0.access_count', 3);
        $first->update(['name' => '落地甲改名']);
        $this->push($batch)->assertOk();
        $this->assertDatabaseHas('v2_proxy_access_log', ['server_id' => $first->id, 'server_name' => '落地甲']);
        $this->assertSame(0, (int) $this->user->fresh()->u);
        $this->assertSame(0, (int) $this->user->fresh()->d);
    }

    public function test_unknown_legacy_and_removed_routes_are_confirmed_without_wrong_attribution(): void
    {
        $child = $this->node->replicate(); $child->name = '已移除落地'; $child->relay_entry_id = $this->node->id; $child->save();
        $foreign = $this->node->replicate(); $foreign->name = '其他节点'; $foreign->save();
        $batch = $this->batch(['node_id' => $child->id]);
        $batch['records'][] = $this->batch(['node_id' => $foreign->id])['records'][0];
        $batch['records'][] = $this->batch(['node_id' => 4294967295])['records'][0];
        $legacy = $this->batch()['records'][0]; unset($legacy['node_id']);
        $batch['records'][] = $legacy;
        $batch['records'][] = $this->batch()['records'][0];
        $this->push($batch)->assertOk()->assertJsonPath('data.batch_id', $batch['batch_id']);
        $this->assertDatabaseCount('v2_proxy_access_log', 2);
        $child->delete();
        $this->push($batch)->assertOk();
        $this->assertDatabaseCount('v2_proxy_access_log', 2);
        $this->assertDatabaseHas('v2_proxy_access_log', ['server_id' => $child->id, 'server_name' => '已移除落地']);
        $batch['batch_id'] = bin2hex(random_bytes(16));
        $this->push($batch)->assertOk();
        $this->assertDatabaseCount('v2_proxy_access_log', 3);
        $this->push($this->batch(['node_id' => 0]))->assertUnprocessable();
        $this->push($this->batch(['node_id' => -1]))->assertUnprocessable();
    }

    public function test_machine_entry_can_report_its_landing_on_another_machine_only_once(): void
    {
        $machine = ServerMachine::create(['name' => '入口测试机', 'token' => Str::random(32), 'is_active' => true]);
        $other = ServerMachine::create(['name' => '落地测试机', 'token' => Str::random(32), 'is_active' => true]);
        $this->node->update(['machine_id' => $machine->id]);
        $child = $this->node->replicate(); $child->name = '跨机器落地'; $child->machine_id = $other->id;
        $child->relay_entry_id = $this->node->id; $child->save();
        $batch = $this->batch(['node_id' => $child->id]);
        $payload = $batch + ['machine_id' => $machine->id, 'node_id' => $this->node->id, 'token' => $machine->token];
        $this->postJson('/api/v2/server/access-log', $payload)->assertOk();
        $this->postJson('/api/v2/server/access-log', $payload)->assertOk();
        $this->assertDatabaseCount('v2_proxy_access_log', 1);
        $this->fetch()->assertOk()->assertJsonPath('data.list.0.server_id', $child->id);
        $landingPayload = $batch + ['machine_id' => $other->id, 'node_id' => $child->id, 'token' => $other->token];
        $landingPayload['batch_id'] = bin2hex(random_bytes(16));
        $this->postJson('/api/v2/server/access-log', $landingPayload)->assertOk();
        $this->assertDatabaseCount('v2_proxy_access_log', 1);
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
        $this->postJson('/api/v2/server/handshake', ['token' => $this->credential])->assertOk()->assertJsonPath('access_log.version', 2);
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
        $batch['records'][0]['node_id'] = $other->id;
        app(ProxyAccessLog::class)->accept($other, $batch);
        $this->assertDatabaseCount('v2_proxy_access_log', 2);
        $this->user->delete(); $this->node->delete();
        $this->fetch()->assertOk()->assertJsonPath('data.list.0.user_name', '#' . $batch['records'][0]['user_id']);
    }

    public function test_user_summary_target_groups_and_exact_details_keep_all_connections(): void
    {
        $other = $this->makeUser();
        $batch = $this->batch();
        $batch['records'] = array_fill(0, 3, $batch['records'][0]);
        $this->push($batch)->assertOk();
        $this->push($batch)->assertOk();
        $this->push($this->batch(['user_id' => $other->id, 'connected_at' => now()->timestamp]))->assertOk();
        $this->push($this->batch(['port' => 80]))->assertOk();
        $this->push($this->batch(['destination' => 'sub.example.com']))->assertOk();
        $node = $this->node->replicate(); $node->name = '另一入口'; $node->save();
        app(ProxyAccessLog::class)->accept($node, $this->batch(['node_id' => $node->id]));

        $this->fetch(['view' => 'users'])->assertOk()->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.list.0.user_id', $this->user->id)->assertJsonPath('data.list.0.access_count', 6);
        $this->fetch(['view' => 'users', 'sort' => 'time'])->assertOk()->assertJsonPath('data.list.0.user_id', $other->id);
        $this->fetch(['view' => 'targets', 'user_id' => $this->user->id])->assertOk()->assertJsonPath('data.total', 3)
            ->assertJsonPath('data.list.0.destination', 'example.com')->assertJsonPath('data.list.0.port', 443)
            ->assertJsonPath('data.list.0.access_count', 4);
        $details = $this->fetch(['view' => 'records', 'user_id' => $this->user->id, 'destination' => 'EXAMPLE.COM.', 'port' => 443])
            ->assertOk()->assertJsonPath('data.total', 4)->json('data.list');
        $this->assertEqualsCanonicalizing([$this->node->id, $node->id], array_values(array_unique(array_column($details, 'server_id'))));
        $this->assertDatabaseCount('v2_proxy_access_log', 7);
        $this->fetch(['view' => 'targets'])->assertUnprocessable();
        $this->fetch(['view' => 'unknown'])->assertUnprocessable();
        $this->fetch(['sort' => 'invalid'])->assertUnprocessable();
        $this->fetch(['destination' => 'https://example.com'])->assertUnprocessable();
    }

    public function test_summary_filters_snapshot_and_deleted_user_fallback(): void
    {
        $this->push($this->batch())->assertOk();
        $first = $this->fetch(['view' => 'users', 'period' => '7d', 'user' => $this->user->email, 'node' => $this->node->id,
            'target' => 'EXAMPLE.COM', 'port' => 443, 'page_size' => 1])->assertOk()
            ->assertJsonPath('data.total', 1)->assertJsonPath('data.list.0.access_count', 1)->json('data');
        $this->push($this->batch())->assertOk();
        $snapshot = ['snapshot_id' => $first['snapshot_id'], 'snapshot_at' => $first['snapshot_at']];
        $this->fetch(['view' => 'targets', 'period' => '7d', 'user_id' => $this->user->id] + $snapshot)->assertOk()
            ->assertJsonPath('data.list.0.access_count', 1);
        $this->fetch(['view' => 'users', 'target' => '%'])->assertOk()->assertJsonPath('data.total', 0);
        $this->fetch(['view' => 'users', 'user' => '%'])->assertOk()->assertJsonPath('data.total', 0);
        $this->user->delete();
        $this->fetch(['view' => 'users', 'user' => (string) $this->user->id])->assertOk()
            ->assertJsonPath('data.list.0.user_name', '#' . $this->user->id)->assertJsonPath('data.list.0.access_count', 2);
    }

    public function test_summary_pagination_uses_stable_tie_breakers_and_time_boundary(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-10-16 23:59:50', 'Asia/Shanghai'));
        $other = $this->makeUser();
        $this->push($this->batch())->assertOk();
        $this->push($this->batch(['user_id' => $other->id]))->assertOk();
        $first = $this->fetch(['view' => 'users', 'page_size' => 1])->assertOk()->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.list.0.user_id', $this->user->id)->json('data');
        $this->travel(20)->seconds();
        $this->push($this->batch())->assertOk();
        $this->fetch(['view' => 'users', 'page_size' => 1, 'page' => 2, 'snapshot_id' => $first['snapshot_id'], 'snapshot_at' => $first['snapshot_at']])
            ->assertOk()->assertJsonPath('data.total', 2)->assertJsonPath('data.list.0.user_id', $other->id);
        $this->fetch(['view' => 'users'])->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.list.0.access_count', 1);
    }

    public function test_today_summary_and_details_exclude_adjacent_days(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-10-16 23:59:50', 'Asia/Shanghai'));
        $midnight = now()->startOfDay()->timestamp;
        foreach ([$midnight - 1, $midnight, now()->timestamp - 1, $midnight + 86400] as $at) {
            $this->push($this->batch(['connected_at' => $at]))->assertOk();
        }
        $today = $this->fetch(['view' => 'users'])->assertOk()->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.list.0.access_count', 2)->json('data');
        $this->fetch(['view' => 'records'])->assertOk()->assertJsonPath('data.total', 2);
        $this->fetch(['view' => 'targets', 'user_id' => $this->user->id])->assertOk()->assertJsonPath('data.list.0.access_count', 2);
        $this->fetch(['view' => 'users', 'period' => '7d'])->assertOk()->assertJsonPath('data.list.0.access_count', 4);
        $this->fetch(['view' => 'users', 'period' => 'custom', 'start_at' => '2026-10-15T00:00', 'end_at' => '2026-10-16T00:00'])
            ->assertOk()->assertJsonPath('data.list.0.access_count', 1);
        $this->travel(20)->seconds();
        $this->fetch(['view' => 'users', 'snapshot_id' => $today['snapshot_id'], 'snapshot_at' => $today['snapshot_at']])
            ->assertOk()->assertJsonPath('data.list.0.access_count', 2);
        $this->fetch(['view' => 'users'])->assertOk()->assertJsonPath('data.list.0.access_count', 1);
    }

    public function test_mariadb_user_summary_reads_a_covering_time_range(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MariaDB 查询计划专项。');
        }
        $batch = $this->batch(['connected_at' => now()->subDays(3)->timestamp]);
        $batch['records'] = array_fill(0, 256, $batch['records'][0]);
        $this->push($batch)->assertOk();
        $this->push($this->batch())->assertOk();
        DB::enableQueryLog();
        try {
            $this->fetch(['view' => 'users'])->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.list.0.access_count', 1);
            $queries = array_values(array_filter(DB::getQueryLog(), fn ($query) => str_contains($query['query'], '`v2_proxy_access_log` as `a`')));
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        $this->assertCount(2, $queries);
        foreach ($queries as $query) {
            $plan = DB::select('EXPLAIN ' . $query['query'], $query['bindings'])[0];
            $this->assertSame('range', $plan->type);
            $this->assertSame('proxy_access_time_user', $plan->key);
            $this->assertStringContainsString('Using index', $plan->Extra);
        }
    }

    public function test_target_sorting_pagination_and_expired_snapshot(): void
    {
        $batch = $this->batch(['destination' => 'b.example']);
        $batch['records'][] = $batch['records'][0];
        $batch['records'][] = $this->batch(['destination' => 'a.example', 'connected_at' => now()->timestamp])['records'][0];
        $this->push($batch)->assertOk();
        $params = ['view' => 'targets', 'user_id' => $this->user->id, 'page_size' => 1];
        $first = $this->fetch($params)->assertOk()->assertJsonPath('data.total', 2)->assertJsonPath('data.last_page', 2)
            ->assertJsonPath('data.list.0.destination', 'b.example')->assertJsonPath('data.list.0.access_count', 2)->json('data');
        $this->fetch($params + ['page' => 2])->assertOk()->assertJsonPath('data.list.0.destination', 'a.example');
        $this->fetch($params + ['sort' => 'time'])->assertOk()->assertJsonPath('data.list.0.destination', 'a.example');
        $this->travel(8)->days();
        $this->fetch($params + ['snapshot_id' => $first['snapshot_id'], 'snapshot_at' => $first['snapshot_at']])
            ->assertOk()->assertJsonPath('data.total', 0);
        $this->artisan('access-log:prune')->assertSuccessful();
        $this->push($batch)->assertOk();
        $this->fetch(['view' => 'users'])->assertOk()->assertJsonPath('data.total', 0);
        $this->assertDatabaseCount('v2_proxy_access_log', 0);
    }
}
