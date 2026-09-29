<?php

namespace Tests\Feature\Server;

use App\Jobs\ProcessNodeReportBatch;
use App\Models\NodeReportBatch;
use App\Models\Server;
use App\Models\User;
use App\Services\NodeStateService;
use App\Services\RealtimeStateStore;
use App\Support\Setting;
use App\WebSocket\NodeEventHandlers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;
use Workerman\Connection\TcpConnection;

class RealtimeServerTest extends TestCase
{
    use RefreshDatabase;

    private Server $node;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->mock(Setting::class, function ($mock): void {
            $mock->shouldReceive('get')->andReturnUsing(fn ($key) => [
                'server_token' => 'realtime-test-only', 'server_ws_enable' => 0,
            ][$key] ?? null);
        });
        $this->node = Server::create([
            'name' => 'realtime-test', 'type' => Server::TYPE_VMESS,
            'host' => '127.0.0.1', 'port' => 443, 'server_port' => 443,
            'rate' => '1', 'group_ids' => [1], 'enabled' => true,
        ]);
    }

    private function auth(): array
    {
        return ['token' => 'realtime-test-only', 'node_id' => $this->node->id];
    }

    private function begin(string $run = 'a'): array
    {
        return $this->postJson('/api/v2/server/realtime/begin', $this->auth() + ['run' => str_repeat($run, 32)])
            ->assertOk()->json('data');
    }

    public function test_handshake_advertises_separate_status_and_traffic_intervals(): void
    {
        $this->postJson('/api/v2/server/handshake', $this->auth())->assertOk()->assertJson([
            'realtime' => ['version' => 1, 'state_interval' => 1, 'fallback_interval' => 10, 'traffic_ack' => true],
            'settings' => ['push_interval' => 60],
        ]);
    }

    public function test_http_state_cannot_replace_a_newer_websocket_snapshot(): void
    {
        $session = $this->begin();
        app(NodeStateService::class)->accept($this->node, [
            'epoch' => $session['epoch'], 'sequence' => 2, 'state' => ['connection_counts' => [1 => 7]],
        ]);
        $this->postJson('/api/v2/server/realtime/state', $this->auth() + [
            'epoch' => $session['epoch'], 'sequence' => 1, 'state' => ['connection_counts' => [1 => 20]],
        ])->assertOk()->assertJsonPath('data.accepted', false);
        $this->assertSame([1 => 7], app(RealtimeStateStore::class)->read('node:' . $this->node->id)['data']['connection_counts']);
    }

    public function test_restart_rejects_previous_session_and_malformed_state_is_not_accepted(): void
    {
        $old = $this->begin();
        $new = $this->begin('b');
        $this->postJson('/api/v2/server/realtime/state', $this->auth() + [
            'epoch' => $old['epoch'], 'sequence' => 1, 'state' => [],
        ])->assertStatus(409);
        $this->postJson('/api/v2/server/realtime/state', $this->auth() + [
            'epoch' => $new['epoch'], 'sequence' => 1, 'state' => ['connection_counts' => [1 => -1]],
        ])->assertStatus(422);
        $this->assertSame(0, app(RealtimeStateStore::class)->read('node:' . $this->node->id)['sequence']);
    }

    public function test_deduplicated_traffic_does_not_modify_current_state(): void
    {
        Bus::fake();
        $session = $this->begin();
        $this->postJson('/api/v2/server/realtime/state', $this->auth() + [
            'epoch' => $session['epoch'], 'sequence' => 3, 'state' => ['connection_counts' => [1 => 7]],
        ])->assertOk();
        $before = app(RealtimeStateStore::class)->read('node:' . $this->node->id);
        $payload = ['realtime' => true, 'report_id' => 'realtime-test-batch', 'traffic' => [1 => [100, 200]], 'connection_counts' => [1 => 99]];
        $this->postJson('/api/v2/server/report', $this->auth() + $payload)->assertOk()
            ->assertJsonPath('receipt.report_id', 'realtime-test-batch');
        $this->postJson('/api/v2/server/report', $this->auth() + $payload)->assertOk();
        $this->assertSame(1, NodeReportBatch::count());
        $this->assertSame($before, app(RealtimeStateStore::class)->read('node:' . $this->node->id));
        Bus::assertDispatchedTimes(ProcessNodeReportBatch::class, 1);
    }

    public function test_lost_websocket_ack_then_http_retry_is_only_settled_once(): void
    {
        Bus::fake();
        Redis::shouldReceive('sadd')->andReturn(1);
        $user = User::create([
            'email' => 'realtime@example.invalid', 'password' => 'unused-test-password',
            'uuid' => '22222222-2222-2222-2222-222222222222', 'token' => str_repeat('b', 32),
            'group_id' => 1, 'transfer_enable' => 100000, 'expired_at' => time() + 3600,
        ]);
        $payload = ['report_id' => 'lost-ack-test', 'traffic' => [$user->id => [100, 200]]];
        $connection = \Mockery::mock(TcpConnection::class);
        $connection->realtime = true;
        $connection->shouldReceive('send')->once()->withArgs(function ($data): bool {
            $message = json_decode($data, true);
            return $message['event'] === 'traffic.ack' && $message['data']['report_id'] === 'lost-ack-test';
        });
        NodeEventHandlers::handleTrafficReport($connection, $this->node->id, $payload);
        $this->postJson('/api/v2/server/report', $this->auth() + $payload + ['realtime' => true])->assertOk();
        $this->assertSame(1, NodeReportBatch::count());
        $job = new ProcessNodeReportBatch(NodeReportBatch::firstOrFail()->id);
        $job->handle();
        $job->handle();
        $this->assertSame(100, (int) $user->fresh()->u);
        $this->assertSame(200, (int) $user->fresh()->d);
    }

    public function test_empty_connection_snapshot_clears_counts_instead_of_being_omitted(): void
    {
        $session = $this->begin();
        foreach ([1 => [1 => 5], 2 => []] as $sequence => $counts) {
            $this->postJson('/api/v2/server/realtime/state', $this->auth() + [
                'epoch' => $session['epoch'], 'sequence' => $sequence,
                'state' => ['connection_counts' => $counts],
            ])->assertOk();
        }
        $this->assertSame([], app(RealtimeStateStore::class)->read('node:' . $this->node->id)['data']['connection_counts']);
    }

    public function test_realtime_report_requires_a_batch_id_and_never_accepts_unidentified_traffic(): void
    {
        $this->postJson('/api/v2/server/report', $this->auth() + [
            'realtime' => true, 'traffic' => [1 => [1, 2]],
        ])->assertStatus(422);
        $this->assertSame(0, NodeReportBatch::count());
    }
}
