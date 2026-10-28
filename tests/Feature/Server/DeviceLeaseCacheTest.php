<?php

namespace Tests\Feature\Server;

use App\Models\Server;
use App\Models\User;
use App\Services\DeviceHandoverProtocol;
use App\Services\DeviceHandoverService;
use App\Services\DeviceStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class DeviceLeaseCacheTest extends TestCase
{
    use RefreshDatabase;

    private array $nodes = [];
    private array $sequences = [];
    private User $user;
    private DeviceHandoverProtocol $protocol;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Carbon::setTestNow('2026-10-28 00:00:00');
        $this->mock(DeviceStateService::class, fn ($mock) => $mock->shouldReceive('getDeviceSources')->andReturn([]));
        $this->user = User::create(['email' => 'lease-cache@example.invalid', 'password' => 'unused-lease-cache-test',
            'uuid' => '44444444-4444-4444-4444-444444444444', 'token' => str_repeat('d', 32), 'group_id' => 1, 'device_limit' => 1,
            'transfer_enable' => 100000, 'u' => 0, 'd' => 0, 'expired_at' => now()->addDays(2)->timestamp, 'banned' => 0]);
        $this->protocol = app(DeviceHandoverProtocol::class);
        foreach ([1, 2, 3] as $id) {
            $this->nodes[$id] = Server::create(['name' => '许可缓存测试', 'type' => Server::TYPE_VMESS,
                'host' => '127.0.0.1', 'port' => 443, 'server_port' => 443, 'rate' => '1', 'group_ids' => [1], 'enabled' => true]);
            $this->sequences[$id] = 0;
            $reply = $this->protocol->handle($this->nodes[$id], 'begin', ['run' => $this->runID($id), 'lease_cache' => 1]);
            $this->assertSame(1, $reply['lease_cache']);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function runID(int $node): string { return str_repeat(dechex($node), 32); }

    private function admit(int $node = 1, string $ip = '8.8.8.8'): array
    {
        $sequence = ++$this->sequences[$node];
        return $this->protocol->handle($this->nodes[$node], 'admit', ['run' => $this->runID($node),
            'sequence' => $sequence, 'user_id' => $this->user->id, 'ip' => $ip, 'lease_cache' => 1,
            'snapshot' => ['run' => $this->runID($node), 'sequence' => ++$this->sequences[$node],
                'pending' => [$sequence], 'sources' => [], 'retired' => []]]);
    }

    private function sync(int $node, array $sources = [], array $retired = []): array
    {
        return $this->protocol->handle($this->nodes[$node], 'sync', ['run' => $this->runID($node),
            'sequence' => ++$this->sequences[$node], 'lease_cache' => 1, 'pending' => [], 'sources' => $sources, 'retired' => $retired]);
    }

    private function retired(array $lease): array
    {
        return ['user_id' => $this->user->id, 'ip' => '8.8.8.8', 'lease' => $lease['lease']];
    }

    public function test_same_account_and_ip_are_synced_to_permitted_nodes_as_one_slot(): void
    {
        $first = $this->admit();
        $this->assertSame(DeviceHandoverService::LEASE_GRACE_MS, $first['expires_at'] - $first['confirmed_at']);
        $synced = $this->sync(2);
        $this->assertSame($first['lease'], $synced['leases'][0]['lease']);
        $again = $this->admit(2);
        $this->assertSame($first['lease'], $again['lease']);
        $state = Cache::get('device_handover:user:' . $this->user->id);
        $this->assertCount(1, $state['sources']);
        $this->assertCount(2, $state['sources']['8.8.8.8']['holders']);
    }

    public function test_idle_and_disconnected_holders_reserve_slot_until_last_expiry(): void
    {
        $first = $this->admit();
        $this->sync(1);
        Carbon::setTestNow(now()->addMinutes(10));
        $second = $this->sync(2)['leases'][0];
        $this->assertSame('waiting', $this->admit(3, '1.1.1.1')['status']);
        Carbon::setTestNow(Carbon::createFromTimestampMs($first['expires_at']));
        $this->assertSame('waiting', $this->admit(3, '1.1.1.1')['status']);
        Carbon::setTestNow(Carbon::createFromTimestampMs($second['expires_at'] - 1));
        $this->assertSame('waiting', $this->admit(3, '1.1.1.1')['status']);
        Carbon::setTestNow(now()->addMillisecond());
        $this->assertSame('allowed', $this->admit(3, '1.1.1.1')['status']);
        $this->assertCount(1, Cache::get('device_handover:user:' . $this->user->id)['sources']);
    }

    public function test_repeated_sync_does_not_extend_confirmation_but_new_verified_sync_does(): void
    {
        $this->admit();
        $first = $this->sync(1);
        Carbon::setTestNow(now()->addMinutes(20));
        $repeat = $this->protocol->handle($this->nodes[1], 'sync', ['run' => $this->runID(1), 'lease_cache' => 1,
            'sequence' => $first['sequence'], 'pending' => [], 'sources' => [], 'retired' => []]);
        $this->assertSame($first['leases'], $repeat['leases']);
        $renewed = $this->protocol->handle($this->nodes[1], 'sync', ['run' => $this->runID(1), 'lease_cache' => 1,
            'sequence' => ++$this->sequences[1], 'unchanged' => true, 'base_sequence' => $first['sequence']]);
        $this->assertSame(now()->getTimestampMs(), $renewed['leases'][0]['confirmed_at']);
        $this->assertGreaterThan($first['leases'][0]['expires_at'], $renewed['leases'][0]['expires_at']);
    }

    public function test_revoked_license_waits_for_every_holder_to_retire_and_never_renews(): void
    {
        $first = $this->admit();
        $this->sync(1);
        $this->sync(2);
        $this->assertSame('waiting', $this->admit(3, '1.1.1.1')['status']);
        $reply = $this->sync(1);
        $this->assertSame([], $reply['leases']);
        $this->assertSame($first['lease'], $reply['revoked'][0]['lease']);
        $this->sync(1, [], [$this->retired($first)]);
        $this->assertSame('waiting', $this->admit(3, '1.1.1.1')['status']);
        $this->assertSame([], $this->sync(2)['leases']);
        $this->sync(2, [], [$this->retired($first)]);
        $this->assertSame('allowed', $this->admit(3, '1.1.1.1')['status']);
        $recovered = $this->sync(1);
        $this->assertNotContains($first['lease'], array_column($recovered['leases'], 'lease'));
    }

    public function test_banned_user_and_removed_node_permission_cannot_renew_cached_license(): void
    {
        $first = $this->admit();
        $this->sync(2);
        $this->nodes[2]->forceFill(['group_ids' => [2]])->save();
        $reply = $this->sync(2);
        $this->assertSame([], $reply['leases']);
        $this->assertSame($first['lease'], $reply['revoked'][0]['lease']);
        $this->user->forceFill(['banned' => 1])->saveQuietly();
        $reply = $this->sync(1);
        $this->assertSame([], $reply['leases']);
        $this->assertSame($first['lease'], $reply['revoked'][0]['lease']);
    }

    public function test_expired_license_removes_idle_account_from_node_queue(): void
    {
        $first = $this->admit();
        $snapshot = $this->sync(1);
        $key = 'device_handover:queue:' . $this->nodes[1]->id;
        $this->assertContains($this->user->id, Cache::get($key, []));
        Carbon::setTestNow(Carbon::createFromTimestampMs($first['expires_at']));
        $reply = $this->protocol->handle($this->nodes[1], 'sync', ['run' => $this->runID(1), 'lease_cache' => 1,
            'sequence' => ++$this->sequences[1], 'unchanged' => true, 'base_sequence' => $snapshot['sequence']]);
        $this->assertSame([], $reply['leases']);
        $this->assertSame([], $reply['revoked']);
        $this->assertSame([], Cache::get('device_handover:user:' . $this->user->id)['sources']);
        $this->assertNotContains($this->user->id, Cache::get($key, []));
        $this->sync(1);
        $this->assertNotContains($this->user->id, Cache::get($key, []));
        $this->assertSame('allowed', $this->admit()['status']);
        $this->assertContains($this->user->id, Cache::get($key, []));
    }

    public function test_queue_entry_without_coordination_record_is_removed(): void
    {
        $this->admit();
        $key = 'device_handover:queue:' . $this->nodes[1]->id;
        $this->assertContains($this->user->id, Cache::get($key, []));
        Cache::forget('device_handover:user:' . $this->user->id);
        // 已失去节点权限的旧账号也必须清理，不能依赖当前可用账号列表。
        $this->nodes[1]->forceFill(['group_ids' => [2]])->save();
        $reply = $this->sync(1);
        $this->assertSame([], $reply['leases']);
        $this->assertSame([], $reply['revoked']);
        $this->assertNotContains($this->user->id, Cache::get($key, []));
    }

    public function test_revocation_keeps_queue_until_local_holder_retires(): void
    {
        $first = $this->admit();
        $this->sync(2);
        $key = 'device_handover:queue:' . $this->nodes[1]->id;
        $this->assertSame('waiting', $this->admit(3, '1.1.1.1')['status']);
        $reply = $this->sync(1);
        $this->assertSame($first['lease'], $reply['revoked'][0]['lease']);
        $this->assertContains($this->user->id, Cache::get($key, []));
        $this->sync(1, [], [$this->retired($first)]);
        $this->assertNotContains($this->user->id, Cache::get($key, []));
        $this->assertContains($this->user->id, Cache::get('device_handover:queue:' . $this->nodes[2]->id, []));
        $this->assertSame($first['lease'], $this->sync(2)['revoked'][0]['lease']);
    }

    public function test_unknown_report_keeps_queue_until_node_reports_no_sources(): void
    {
        $first = $this->admit();
        $key = 'device_handover:queue:' . $this->nodes[1]->id;
        Cache::forget('device_handover:user:' . $this->user->id);
        $reply = $this->sync(1, [['user_id' => $this->user->id, 'ip' => '8.8.8.8', 'lease' => $first['lease'],
            'connect_sequence' => 1, 'age_ms' => 0]]);
        $this->assertSame($first['lease'], $reply['revoked'][0]['lease']);
        $this->assertContains($this->user->id, Cache::get($key, []));
        $this->sync(1);
        $this->assertNotContains($this->user->id, Cache::get($key, []));
    }

    public function test_node_restart_and_unknown_report_do_not_recreate_authoritative_license(): void
    {
        $first = $this->admit();
        $this->sync(1);
        $this->assertSame('waiting', $this->admit(3, '1.1.1.1')['status']);
        app(DeviceHandoverService::class)->begin((int) $this->nodes[1]->id, str_repeat('f', 32), true);
        $this->assertSame('waiting', $this->admit(3, '1.1.1.1')['status'], '换代不能提前释放离线缓存占位');
        Carbon::setTestNow(Carbon::createFromTimestampMs($first['expires_at']));
        $this->assertSame('allowed', $this->admit(3, '1.1.1.1')['status']);
        $reply = $this->sync(2, [['user_id' => $this->user->id, 'ip' => '8.8.8.8', 'lease' => $first['lease'],
            'connect_sequence' => 1, 'age_ms' => 0]]);
        $this->assertNotContains($first['lease'], array_column($reply['leases'], 'lease'));
        $this->assertSame($first['lease'], $reply['revoked'][0]['lease']);
    }
}
