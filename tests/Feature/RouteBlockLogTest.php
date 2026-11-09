<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\ServerRoute;
use App\Models\User;
use App\Services\LogSettings;
use App\Services\RouteBlockLog;
use App\Support\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RouteBlockLogTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\IsolatesLogStorage;

    private Server $node;
    private ServerRoute $rule;
    private User $user;
    private User $admin;
    private string $credential;

    protected function setUp(): void
    {
        parent::setUp();
        $this->isolateLogStorage();
        config(['app.timezone' => 'Asia/Shanghai']);
        $this->travelTo(now()->startOfMinute());
        $this->credential = Str::random(32);
        $credential = $this->credential;
        $this->mock(Setting::class, fn ($mock) => $mock->shouldReceive('get')->andReturnUsing(fn ($key) => $key === 'server_token' ? $credential : null));
        $this->node = Server::create(['name' => '拦截测试节点', 'type' => 'vless', 'host' => '127.0.0.1', 'port' => 443,
            'server_port' => 443, 'rate' => 1, 'group_ids' => [], 'enabled' => true]);
        $this->rule = ServerRoute::create(['remarks' => '禁止邮件', 'action' => 'block', 'match' => [], 'port' => '25']);
        $this->user = $this->makeUser();
        $this->admin = $this->makeUser(true);
        Sanctum::actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        $this->removeLogStorage();
        parent::tearDown();
    }

    private function makeUser(bool $admin = false): User
    {
        return User::create(['email' => Str::random(12) . '@example.test', 'password' => Str::random(32),
            'uuid' => Str::uuid(), 'token' => Str::random(32), 'is_admin' => $admin]);
    }

    private function batch(array $changes = []): array
    {
        return ['batch_id' => bin2hex(random_bytes(16)), 'records' => [array_replace([
            'node_id' => $this->node->id, 'user_id' => $this->user->id, 'rule_id' => $this->rule->id,
            'connected_at' => now()->timestamp - 10, 'destination' => 'Mail.EXAMPLE.test.', 'port' => 25, 'network' => 'tcp',
        ], $changes)]];
    }

    private function push(array $batch, array $auth = [])
    {
        return $this->postJson('/api/v2/server/block-log', $batch + $auth + ['node_id' => $this->node->id, 'token' => $this->credential]);
    }

    private function url(string $path = 'block'): string
    {
        return '/api/v2/' . hash('crc32b', config('app.key')) . '/logs/' . $path;
    }

    private function fetch(array $query = [])
    {
        return $this->getJson($this->url() . '?' . http_build_query($query));
    }

    public function test_authenticated_batch_retry_preserves_first_record_and_filters(): void
    {
        $batch = $this->batch();
        $this->push($batch)->assertOk()->assertJsonPath('data.batch_id', $batch['batch_id']);
        $this->node->update(['name' => '节点新名']);
        $this->rule->update(['remarks' => '规则新名']);
        $batch['records'][0]['destination'] = 'different.example.test';
        $this->push($batch)->assertOk();
        $this->assertDatabaseCount('v2_route_block_log', 1);
        $this->fetch(['user' => $this->user->email, 'node' => '拦截测试', 'rule' => '禁止邮件', 'network' => 'tcp', 'port' => 25, 'target' => 'example.test'])
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.list.0.destination', 'mail.example.test')->assertJsonPath('data.list.0.rule_id', $this->rule->id)
            ->assertJsonPath('data.list.0.node_name', '拦截测试节点')->assertJsonMissingPath('data.list.0.batch_id');
        $this->fetch(['node' => (string) $this->node->id, 'rule' => (string) $this->rule->id, 'user' => (string) $this->user->id])->assertJsonPath('data.total', 1);
        foreach ([['network' => 'udp'], ['port' => 443], ['target' => '%'], ['user' => 'not-found'], ['rule' => '9999']] as $filter) $this->fetch($filter)->assertJsonPath('data.total', 0);
        $this->assertSame(0, (int) $this->user->fresh()->u);
        $this->assertSame(0, (int) $this->user->fresh()->d);
    }

    public function test_rejects_untrusted_identity_payloads_and_non_admin_queries(): void
    {
        $this->push($this->batch(), ['token' => Str::random(32)])->assertUnprocessable();
        foreach ([['node_id' => $this->node->id + 100], ['user_id' => 0], ['network' => 'quic'], ['rule_id' => 0],
            ['destination' => 'https://example.test/private?secret=1'], ['destination' => "example.test\n"],
            ['connected_at' => now()->timestamp + 301], ['port' => 65536], ['password' => 'must-not-store']] as $patch) {
            $this->push($this->batch($patch))->assertUnprocessable();
        }
        $batch = $this->batch(); $batch['records']['invalid'] = $batch['records'][0];
        $this->push($batch)->assertUnprocessable();
        $this->assertDatabaseCount('v2_route_block_log', 0);
        Sanctum::actingAs($this->user);
        $this->fetch()->assertForbidden();
    }

    public function test_landing_records_unknown_user_and_machine_auth_stays_node_scoped(): void
    {
        $machine = ServerMachine::create(['name' => '测试机器', 'token' => Str::random(32), 'is_active' => true]);
        $landing = $this->node->replicate(); $landing->name = '落地'; $landing->relay_entry_id = $this->node->id; $landing->machine_id = $machine->id; $landing->save();
        $batch = $this->batch(['node_id' => $landing->id, 'user_id' => 0]);
        $auth = ['node_id' => $landing->id, 'machine_id' => $machine->id, 'token' => $machine->token];
        $this->push($batch, $auth)->assertOk();
        $this->push($batch, $auth)->assertOk();
        $this->fetch(['user' => '0'])->assertJsonPath('data.total', 1)->assertJsonPath('data.list.0.user_id', null)
            ->assertJsonPath('data.list.0.user_name', null)->assertJsonPath('data.list.0.node_id', $landing->id);
        $batch['records'][0]['node_id'] = $this->node->id;
        $this->push($batch, $auth)->assertUnprocessable();
    }

    public function test_default_recording_ignores_log_switches_and_preserves_privacy_and_retention(): void
    {
        $old = $this->batch();
        $settings = LogSettings::view();
        foreach ($settings as &$module) $module['enabled'] = false;
        unset($module);
        LogSettings::save($settings);
        // 开发阶段曾保存的开关、恢复时间与保留期不能再控制拦截记录。
        $settings['block'] = ['enabled' => false, 'record_from' => now()->addDay()->timestamp, 'retention_days' => 1];
        (new Setting())->set('log_settings', $settings);
        $this->push($old)->assertOk();
        $this->assertDatabaseCount('v2_route_block_log', 1);
        $this->travel(1)->minutes();
        $this->push($old)->assertOk();
        $this->push($this->batch(['connected_at' => now()->timestamp]))->assertOk();
        $this->assertDatabaseCount('v2_route_block_log', 2);
        $this->assertSame(7, RouteBlockLog::days());
        $this->getJson($this->url('settings'))->assertOk()->assertJsonMissingPath('data.block');
        $machine = ServerMachine::create(['name' => '隐私机器', 'token' => Str::random(32), 'is_active' => true, 'agent_log_disabled' => true]);
        $this->node->update(['machine_id' => $machine->id]);
        $this->push($this->batch(['connected_at' => now()->timestamp]))->assertOk();
        $this->assertDatabaseCount('v2_route_block_log', 2);
        $this->travel(8)->days();
        $this->fetch()->assertJsonPath('data.total', 0);
        $this->push($old)->assertOk();
        $this->artisan('management-log:prune')->assertSuccessful();
        $this->assertDatabaseCount('v2_route_block_log', 0);
    }

    public function test_write_failure_retries_and_cleanup_resumes_without_losing_live_records(): void
    {
        $batch = $this->batch();
        $this->failNextQueryMatching('/insert into ["`]?v2_route_block_log/i');
        $this->push($batch)->assertStatus(500);
        $this->assertDatabaseCount('v2_route_block_log', 0);
        $this->push($batch)->assertOk();
        $this->travel(8)->days();
        for ($i = 0; $i < 3; $i++) {
            DB::table('v2_route_block_log')->insert(array_replace((array) DB::table('v2_route_block_log')->first(), ['id' => 100 + $i, 'batch_id' => bin2hex(random_bytes(16))]));
        }
        $this->push($this->batch())->assertOk();
        $this->artisan('management-log:prune --batch=1 --batches=6')->assertSuccessful();
        $this->assertDatabaseCount('v2_route_block_log', 4);
        $this->artisan('management-log:prune --resume --batch=1')->assertSuccessful();
        $this->assertDatabaseCount('v2_route_block_log', 1);
    }

    public function test_query_dates_pagination_deleted_rule_and_old_settings_remain_compatible(): void
    {
        $this->push($this->batch())->assertOk();
        $this->push($this->batch(['connected_at' => now()->timestamp - 70, 'network' => 'udp']))->assertOk();
        $this->rule->delete();
        $this->fetch(['page_size' => 1, 'page' => 2])->assertJsonPath('data.total', 2)->assertJsonPath('data.last_page', 2)->assertJsonPath('data.list.0.network', 'udp');
        $this->fetch(['start_at' => now()->subMinute()->format('Y-m-d\TH:i'), 'end_at' => now()->format('Y-m-d\TH:i')])->assertJsonPath('data.total', 1);
        foreach ([['start_at' => now()->subDays(8)->format('Y-m-d\TH:i')], ['start_at' => '2026-02-30T10:00'], ['page_size' => 101], ['network' => 'invalid']] as $filter) $this->fetch($filter)->assertUnprocessable();
        $this->postJson($this->url('block/settings'), ['retention_days' => 30])->assertOk()->assertJsonPath('data.retention_days', 30);
        $settings = LogSettings::view();
        $this->postJson($this->url('settings'), ['settings' => $settings])->assertOk()->assertJsonMissingPath('data.block');
        $this->getJson($this->url('block/settings'))->assertJsonPath('data.retention_days', 30);
        $this->postJson('/api/v2/server/handshake', ['node_id' => $this->node->id, 'token' => $this->credential])->assertOk()
            ->assertJsonPath('block_log.version', 1)->assertJsonPath('block_log.retention_days', 30);
    }

    public function test_retention_settings_require_admin_and_validate_before_saving(): void
    {
        $url = $this->url('block/settings');
        Sanctum::actingAs($this->user);
        $this->getJson($url)->assertForbidden();
        $this->postJson($url, ['retention_days' => 30])->assertForbidden();
        Sanctum::actingAs($this->admin);
        $this->getJson($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('data', ['retention_days' => 7]);
        foreach ([0, -1, 366, 1.5, 'invalid', null] as $days) {
            $this->postJson($url, ['retention_days' => $days])->assertUnprocessable();
            $this->assertSame(7, RouteBlockLog::days());
        }
        $this->postJson($url, ['retention_days' => 30, 'enabled' => false])->assertUnprocessable();
        $settings = LogSettings::view();
        $settings['block'] = ['enabled' => false, 'retention_days' => 30];
        $this->postJson($this->url('settings'), ['settings' => $settings])->assertUnprocessable();
        $this->postJson($url, ['retention_days' => 365])->assertOk()->assertJsonPath('data.retention_days', 365);
        $this->app->forgetScopedInstances();
        $this->assertSame(365, RouteBlockLog::days());
    }

    public function test_retention_changes_control_queries_and_cleanup_independently(): void
    {
        $this->push($this->batch())->assertOk();
        $this->travel(8)->days();
        $this->fetch()->assertJsonPath('data.total', 0);
        $this->postJson($this->url('block/settings'), ['retention_days' => 30])->assertOk();
        $this->fetch()->assertJsonPath('data.total', 1);
        $this->artisan('management-log:prune')->assertSuccessful();
        $this->assertDatabaseCount('v2_route_block_log', 1);
        $this->postJson($this->url('block/settings'), ['retention_days' => 1])->assertOk();
        $this->push($this->batch())->assertOk();
        $this->fetch()->assertJsonPath('data.total', 1);
        $this->artisan('management-log:prune')->assertSuccessful();
        $this->assertDatabaseCount('v2_route_block_log', 1);
    }

    public function test_groups_users_across_rules_and_filters_counts_before_pagination(): void
    {
        $other = $this->makeUser();
        $this->push($this->batch())->assertOk();
        $this->push($this->batch(['rule_id' => 999, 'network' => 'udp', 'port' => 443, 'connected_at' => now()->timestamp - 20]))->assertOk();
        $this->push($this->batch(['user_id' => $other->id]))->assertOk();
        $summary = $this->fetch(['view' => 'users', 'page_size' => 1])->assertOk()->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.list.0.user_id', $this->user->id)->assertJsonPath('data.list.0.block_count', 2)
            ->assertJsonPath('data.list.0.last_at', now()->timestamp - 10)->assertJsonPath('data.last_page', 2);
        $snapshot = ['snapshot_id' => $summary->json('data.snapshot_id'), 'snapshot_at' => $summary->json('data.snapshot_at')];
        $this->push($this->batch(['user_id' => $other->id]))->assertOk();
        $this->push($this->batch(['user_id' => $other->id]))->assertOk();
        $this->fetch(['view' => 'users', 'page_size' => 1, 'page' => 2] + $snapshot)->assertJsonPath('data.list.0.user_id', $other->id)
            ->assertJsonPath('data.list.0.block_count', 1);
        $this->fetch(['view' => 'records', 'user_id' => $this->user->id] + $snapshot)->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.list.0.user_id', $this->user->id)->assertJsonPath('data.list.1.user_id', $this->user->id);
        $this->fetch(['view' => 'users', 'network' => 'udp'])->assertJsonPath('data.total', 1)->assertJsonPath('data.list.0.block_count', 1);
        $this->fetch(['view' => 'users', 'port' => 25])->assertJsonPath('data.total', 2)->assertJsonPath('data.list.0.user_id', $other->id)
            ->assertJsonPath('data.list.0.block_count', 3);
        $this->fetch(['view' => 'users', 'rule' => '999'])->assertJsonPath('data.list.0.block_count', 1);
        $this->fetch(['view' => 'users', 'target' => 'absent.example.test'])->assertJsonPath('data.total', 0)->assertJsonPath('data.list', []);
        $this->fetch(['view' => 'users', 'user' => $this->user->email])->assertJsonPath('data.total', 1)->assertJsonPath('data.list.0.block_count', 2);
        $this->fetch(['view' => 'users', 'start_at' => now()->format('Y-m-d\TH:i')])->assertJsonPath('data.total', 0);
    }

    public function test_unknown_and_deleted_users_have_separate_groups_and_exact_details(): void
    {
        $this->push($this->batch())->assertOk();
        $removed = $this->makeUser();
        $this->push($this->batch(['user_id' => $removed->id]))->assertOk();
        $removed->delete();
        $landing = $this->node->replicate(); $landing->relay_entry_id = $this->node->id; $landing->save();
        foreach (['tcp', 'udp'] as $network) {
            $this->push($this->batch(['node_id' => $landing->id, 'user_id' => 0, 'network' => $network]), ['node_id' => $landing->id])->assertOk();
        }
        $summary = $this->fetch(['view' => 'users'])->assertOk()->assertJsonPath('data.total', 3)
            ->assertJsonPath('data.list.0.user_id', null)->assertJsonPath('data.list.0.block_count', 2);
        $names = collect($summary->json('data.list'))->pluck('user_name', 'user_id');
        $this->assertSame('#' . $removed->id, $names[$removed->id]);
        $this->fetch(['view' => 'records', 'user_id' => 0])->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.list.0.user_id', null)->assertJsonPath('data.list.1.user_id', null);
        $this->fetch(['view' => 'records', 'user_id' => $removed->id])->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.list.0.user_name', '#' . $removed->id);
        foreach ([['view' => 'all'], ['user_id' => -1], ['snapshot_at' => now()->timestamp + 1], ['snapshot_id' => -1]] as $filter) $this->fetch($filter)->assertUnprocessable();
        Sanctum::actingAs($this->user);
        $this->fetch(['view' => 'users'])->assertForbidden();
        $this->fetch(['view' => 'records', 'user_id' => $removed->id])->assertForbidden();
    }
}
