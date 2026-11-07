<?php

namespace Tests\Feature\Server;

use App\Jobs\ProcessNodeReportBatch;
use App\Jobs\TrafficFetchJob;
use App\Models\NodeReportBatch;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\User;
use App\Services\NodeTrafficAuthorization;
use App\Services\ServerService;
use App\Services\TrafficPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TrafficAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private ServerMachine $machine;
    private Server $node;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Redis::shouldReceive('sadd')->andReturn(1);
        Redis::shouldReceive('publish')->andReturn(0);
        $this->machine = ServerMachine::create(['name' => 'traffic-test', 'token' => 'traffic-test-only', 'is_active' => true]);
        $this->node = Server::create(['name' => 'traffic-test', 'machine_id' => $this->machine->id,
            'type' => 'vmess', 'host' => '127.0.0.1', 'port' => 443, 'server_port' => 443,
            'rate' => 1, 'group_ids' => [1], 'enabled' => true]);
        $this->user = User::create(['email' => 'traffic-test@example.invalid', 'password' => 'unused-test',
            'uuid' => '11111111-1111-4111-8111-111111111111', 'token' => str_repeat('a', 32),
            'group_id' => 1, 'u' => 100, 'd' => 100, 'transfer_enable' => 10000, 'expired_at' => time() + 3600]);
    }

    private function auth(): array
    {
        return ['machine_id' => $this->machine->id, 'node_id' => $this->node->id, 'token' => $this->machine->token];
    }

    private function report(string $id = 'traffic-test-report')
    {
        return $this->postJson('/api/v2/server/report', $this->auth() + [
            'realtime' => true, 'report_id' => $id, 'traffic' => [$this->user->id => [700, 900]],
        ]);
    }

    public function test_machine_cannot_bill_a_user_outside_its_node_groups(): void
    {
        $this->user->update(['group_id' => 2]);
        $this->report()->assertStatus(422);
        $this->postJson('/api/v2/server/push?' . http_build_query($this->auth()), [$this->user->id => [20, 30]])->assertStatus(422);
        $this->assertSame(100, (int) $this->user->fresh()->u);
        $this->assertSame(0, NodeReportBatch::count());
        Bus::assertNotDispatched(TrafficFetchJob::class);
    }

    public function test_invalid_increments_are_rejected_by_both_report_paths(): void
    {
        foreach ([[-1, 2], [2, -1], [1.5, 2], ['1e3', 2], ['9223372036854775808', 2], [true, 2],
            [TrafficPayload::MAX_BYTES, 1], [0 => 1, 2 => 2]] as $pair) {
            $this->postJson('/api/v2/server/push?' . http_build_query($this->auth()), [$this->user->id => $pair])->assertStatus(422);
            $this->postJson('/api/v2/server/report', $this->auth() + ['traffic' => [$this->user->id => $pair]])->assertStatus(422);
        }
        $this->assertSame(100, (int) $this->user->fresh()->u);
        Bus::assertNotDispatched(TrafficFetchJob::class);
        $this->assertSame(0, NodeReportBatch::count());
    }

    public function test_queued_legacy_negative_increment_cannot_modify_account(): void
    {
        $job = new TrafficFetchJob(['id' => $this->node->id, 'rate' => 1], [$this->user->id => [-50, -75]], 'vmess', time());
        try { $job->handle(); $this->fail('旧负数任务必须失败'); }
        catch (ValidationException) { $this->assertSame(100, (int) $this->user->fresh()->u); }
    }

    public function test_exhausted_expired_and_banned_users_can_settle_previous_traffic_once(): void
    {
        $this->user->update(['expired_at' => time() - 1, 'transfer_enable' => 1, 'banned' => true]);
        $this->assertCount(0, ServerService::getAvailableUsers($this->node));
        $this->report()->assertOk();
        $job = new ProcessNodeReportBatch(NodeReportBatch::firstOrFail()->id);
        $job->handle(); $job->handle();
        $this->assertSame(800, (int) $this->user->fresh()->u);
        $this->assertSame(1000, (int) $this->user->fresh()->d);
    }

    public function test_delivered_permission_survives_group_change_cache_loss_and_replay(): void
    {
        $this->getJson('/api/v2/server/user?' . http_build_query($this->auth()))->assertOk();
        $this->user->update(['group_id' => 2]);
        Cache::flush();
        $this->report()->assertOk();
        $this->travel(NodeTrafficAuthorization::GRACE_SECONDS + 1)->seconds();
        $this->report()->assertOk();
        $this->report('new-expired-grant')->assertStatus(422);
        $this->assertSame(1, NodeReportBatch::count());
    }

    public function test_historical_permission_does_not_follow_node_to_a_new_machine(): void
    {
        ServerService::getUsersForSync($this->node);
        $this->user->update(['group_id' => 2]);
        $this->machine = ServerMachine::create(['name' => 'other', 'token' => 'other-test-token', 'is_active' => true]);
        $this->node->update(['machine_id' => $this->machine->id]);
        $this->report()->assertStatus(422);
    }

    public function test_string_integers_and_auth_in_legacy_body_remain_supported(): void
    {
        $this->postJson('/api/v2/server/push', $this->auth() + [$this->user->id => ['20', '30']])->assertOk();
        $job = Bus::dispatched(TrafficFetchJob::class)->first();
        $this->assertNotNull($job); $job->handle();
        $this->assertSame(120, (int) $this->user->fresh()->u);
        $this->assertSame(130, (int) $this->user->fresh()->d);
    }

    public function test_relay_user_data_is_authorized_and_child_reads_are_batched(): void
    {
        $child = Server::withoutEvents(fn () => Server::create(['name' => 'child', 'relay_entry_id' => $this->node->id,
            'type' => 'vmess', 'host' => '127.0.0.1', 'port' => 444, 'server_port' => 444, 'group_ids' => [1], 'rate' => 1]));
        $traffic = array_fill(1, 100, [$child->id => [1, 2]]);
        DB::enableQueryLog(); DB::flushQueryLog();
        $normalized = ServerService::normalizeRelayUserTraffic($this->node, $traffic);
        $queries = DB::getQueryLog(); DB::disableQueryLog();
        $this->assertCount(100, $normalized);
        $this->assertLessThanOrEqual(2, count($queries));
        DB::enableQueryLog(); DB::flushQueryLog();
        $this->assertSame([], ServerService::normalizeRelayUserTraffic($this->node, []));
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
        $this->user->update(['group_id' => 2]);
        $this->postJson('/api/v2/server/report', $this->auth() + ['relay_user_traffic' => [$this->user->id => [$child->id => [1, 2]]]])->assertStatus(422);
    }

    public function test_grant_renewal_is_bounded_and_cleanup_preserves_live_permissions(): void
    {
        ServerService::getUsersForSync($this->node);
        $before = DB::table('v2_node_traffic_grant')->first()->expires_at;
        $this->travel(60)->seconds();
        ServerService::getUsersForSync($this->node);
        $this->assertEquals($before, DB::table('v2_node_traffic_grant')->first()->expires_at);
        $this->artisan('node:prune-traffic-grants')->assertSuccessful();
        $this->assertSame(1, DB::table('v2_node_traffic_grant')->count());
        $this->travel(NodeTrafficAuthorization::GRACE_SECONDS)->seconds();
        $this->artisan('node:prune-traffic-grants')->assertSuccessful();
        $this->assertSame(0, DB::table('v2_node_traffic_grant')->count());
    }
}
