<?php

namespace Tests\Feature\Server;

use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\User;
use App\Services\DeviceHandoverProtocol;
use App\Services\DeviceHandoverService;
use App\Services\DeviceStateService;
use App\Services\MachineTrafficService;
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
            'transfer_enable' => 100000, 'u' => 0, 'd' => 0, 'expired_at' => time() + 2 * 86400, 'banned' => 0]);
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

    public function test_machine_report_timeout_and_recovery_reconfirm_shared_lease_without_changing_identity(): void
    {
        $machine = ServerMachine::create(['name' => '许可恢复测试', 'is_active' => true, 'token' => bin2hex(random_bytes(16))]);
        MachineTrafficService::configure($machine, ['traffic_limit_gb' => 1]);
        $machine->saveQuietly();
        $this->nodes[1]->forceFill(['machine_id' => $machine->id])->saveQuietly();
        $view = MachineTrafficService::view($machine);
        $report = ['epoch' => $view['epoch'], 'period_start' => $view['period_start'], 'sequence' => 1,
            'initialized' => true, 'rx' => 0, 'tx' => 0];
        $this->assertFalse(MachineTrafficService::exchange($machine->id, $report)['blocked']);
        $first = $this->admit();
        $this->sync(1);
        $other = $this->sync(2)['leases'][0];
        Carbon::setTestNow(now()->addSeconds(MachineTrafficService::LEASE_SECONDS));
        $this->assertTrue(MachineTrafficService::nodeBlocked($this->nodes[1]));
        $denied = $this->sync(1);
        $this->assertSame([], $denied['leases']);
        $this->assertSame([$first['lease']], array_column($denied['revoked'], 'lease'));
        $this->assertSame($other['lease'], $this->sync(2)['leases'][0]['lease']);
        // 节点已真实关闭并停用本地许可；另一台仍持有同一个设备名额。
        $this->sync(1, [], [$this->retired($first)]);
        $this->assertTrue(MachineTrafficService::exchange($machine->id, $report)['blocked'], '旧报告不能恢复机器权限');
        $report['sequence']++;
        $this->assertFalse(MachineTrafficService::exchange($machine->id, $report)['blocked']);
        $restored = $this->sync(1);
        $this->assertSame($first['lease'], $restored['leases'][0]['lease']);
        $this->assertSame($restored['sequence'], $restored['leases'][0]['confirmed_sequence']);
        $this->assertSame($other['lease'], $this->sync(2)['leases'][0]['lease']);
        $state = Cache::get('device_handover:user:' . $this->user->id);
        $this->assertCount(1, $state['sources']);
        $this->assertCount(2, $state['sources']['8.8.8.8']['holders']);
    }

    public function test_permission_recovery_uses_new_verification_for_same_pending_admission(): void
    {
        $sequence = ++$this->sequences[1];
        $request = ['run' => $this->runID(1), 'sequence' => $sequence, 'user_id' => $this->user->id,
            'ip' => '8.8.8.8', 'lease_cache' => 1, 'snapshot' => ['run' => $this->runID(1),
                'sequence' => ++$this->sequences[1], 'pending' => [$sequence], 'sources' => [], 'retired' => []]];
        $first = $this->protocol->handle($this->nodes[1], 'admit', $request);
        $this->assertSame($request['snapshot']['sequence'], $first['confirmed_sequence']);
        $this->sync(2);
        $this->nodes[1]->forceFill(['group_ids' => [2]])->saveQuietly();
        $blocked = $request['snapshot'];
        $blocked['sequence'] = ++$this->sequences[1];
        $denied = $this->protocol->handle($this->nodes[1], 'sync', $blocked + ['lease_cache' => 1]);
        $this->assertSame([$first['lease']], array_column($denied['revoked'], 'lease'));
        $this->nodes[1]->forceFill(['group_ids' => [1]])->saveQuietly();
        Carbon::setTestNow(now()->addSecond());
        $request['snapshot']['sequence'] = ++$this->sequences[1];
        $restored = $this->protocol->handle($this->nodes[1], 'admit', $request);
        $this->assertSame($first['lease'], $restored['lease']);
        $this->assertSame($request['snapshot']['sequence'], $restored['confirmed_sequence']);
        $this->assertGreaterThan($first['confirmed_at'], $restored['confirmed_at']);
        Carbon::setTestNow(now()->addSecond());
        $repeat = $this->protocol->handle($this->nodes[1], 'admit', $request);
        $this->assertSame($restored['confirmed_at'], $repeat['confirmed_at']);
        $this->assertSame($restored['expires_at'], $repeat['expires_at']);
        $this->assertSame($restored['confirmed_sequence'], $repeat['confirmed_sequence']);
        $this->sync(1);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException::class);
        $this->protocol->handle($this->nodes[1], 'admit', $request);
    }

    public function test_repeated_and_late_retirement_cannot_remove_new_confirmation_or_other_holder(): void
    {
        $first = $this->admit();
        $this->sync(1);
        $this->sync(2);
        $request = ['run' => $this->runID(1), 'sequence' => ++$this->sequences[1], 'lease_cache' => 1,
            'pending' => [], 'sources' => [], 'retired' => [$this->retired($first)]];
        $restored = $this->protocol->handle($this->nodes[1], 'sync', $request);
        $fresh = $this->sync(1);
        Carbon::setTestNow(now()->addSecond());
        foreach (range(1, 2) as $_) {
            $repeat = $this->protocol->handle($this->nodes[1], 'sync', $request);
            $this->assertSame($fresh['leases'], $repeat['leases']);
        }
        $this->assertSame($restored['leases'][0]['lease'], $fresh['leases'][0]['lease']);
        $state = Cache::get('device_handover:user:' . $this->user->id);
        $this->assertCount(1, $state['sources']);
        $this->assertCount(2, $state['sources']['8.8.8.8']['holders']);
    }

    private function redisCache(callable $lookup, ?callable $read = null): void
    {
        $backing = Cache::getStore();
        $prefix = 'lease-read-test:';
        $connection = \Mockery::mock(\Illuminate\Redis\Connections\Connection::class);
        $connection->shouldReceive('eval')->andReturnUsing($lookup);
        $connection->shouldReceive('exists')->andReturnUsing(fn ($key) => (int) ($backing->get(substr($key, strlen($prefix))) !== null));
        $factory = \Mockery::mock(\Illuminate\Contracts\Redis\Factory::class);
        $factory->shouldReceive('connection')->with('cache')->andReturn($connection);
        $store = \Mockery::mock(\Illuminate\Cache\RedisStore::class . '[get,many,put,forever,forget,lock]', [$factory, $prefix, 'cache']);
        $store->shouldReceive('get')->andReturnUsing(function ($key) use ($backing, $read) {
            if ($read) $read([$key], false);
            return $backing->get($key);
        });
        $store->shouldReceive('many')->andReturnUsing(function ($keys) use ($backing, $read) {
            if ($read) $read($keys, true);
            return $backing->many($keys);
        });
        foreach (['put', 'forever', 'forget', 'lock'] as $method) {
            $store->shouldReceive($method)->andReturnUsing(fn (...$arguments) => $backing->$method(...$arguments));
        }
        Cache::swap(new \Illuminate\Cache\Repository($store));
    }

    public function test_redis_renewal_reads_snapshot_once_and_screens_large_user_list_without_loading_records(): void
    {
        $first = $this->admit();
        $base = $this->sync(1);
        $users = [];
        for ($index = 0; $index < 599; $index++) {
            $row = $this->user->getAttributes();
            unset($row['id']);
            $row['email'] = 'lease-bulk-' . $index . '@example.invalid';
            $row['uuid'] = sprintf('00000000-0000-0000-0000-%012d', $index);
            $row['token'] = hash('md5', 'lease-bulk-' . $index);
            $users[] = $row;
        }
        foreach (array_chunk($users, 50) as $chunk) User::insert($chunk);
        $backing = Cache::getStore();
        $snapshotReads = $lookups = 0;
        $this->redisCache(function ($script, $count, ...$keys) use ($backing, &$lookups): array {
            $lookups++;
            $this->assertLessThanOrEqual(256, $count);
            $this->assertCount($count, $keys);
            foreach ($keys as $key) $this->assertStringStartsWith('lease-read-test:device_handover:user:', $key);
            return array_map(fn ($key) => (int) ($backing->get(substr($key, strlen('lease-read-test:'))) !== null), $keys);
        }, function ($keys, $bulk) use (&$snapshotReads): void {
            foreach ($keys as $key) {
                if ($bulk) $this->assertStringStartsNotWith('device_handover:user:', $key);
                if (!$bulk && $key === 'device_handover:node:' . $this->nodes[1]->id) $snapshotReads++;
            }
        });
        Carbon::setTestNow(now()->addSecond());
        $payload = ['run' => $this->runID(1), 'lease_cache' => 1, 'sequence' => ++$this->sequences[1],
            'unchanged' => true, 'base_sequence' => $base['sequence']];
        $reply = $this->protocol->handle($this->nodes[1], 'sync', $payload);
        $this->assertSame(1, $snapshotReads);
        $this->assertSame(3, $lookups);
        $this->assertSame([$first['lease']], array_column($reply['leases'], 'lease'));
        Carbon::setTestNow(now()->addSecond());
        $repeat = $this->protocol->handle($this->nodes[1], 'sync', $payload);
        $this->assertSame($reply['leases'], $repeat['leases']);
    }

    public function test_invalid_redis_presence_response_cannot_confirm_or_extend_license(): void
    {
        $this->admit();
        $base = $this->sync(1);
        $key = 'device_handover:user:' . $this->user->id;
        $original = Cache::get($key);
        $this->redisCache(fn () => ['1']);
        Carbon::setTestNow(now()->addMinute());
        try {
            $this->protocol->handle($this->nodes[1], 'sync', ['run' => $this->runID(1), 'lease_cache' => 1,
                'sequence' => ++$this->sequences[1], 'unchanged' => true, 'base_sequence' => $base['sequence']]);
            $this->fail('无效缓存响应不能确认许可');
        } catch (\RuntimeException $error) {
            $this->assertSame('设备许可存在状态无效', $error->getMessage());
        }
        $this->assertSame($original, Cache::get($key));
    }

    public function test_partial_snapshot_write_cannot_merge_old_progress_or_confirm_license(): void
    {
        $this->admit();
        $base = $this->sync(1);
        $key = 'device_handover:node:' . $this->nodes[1]->id;
        $snapshot = Cache::get($key);
        $snapshot['sequence'] = ++$this->sequences[1];
        Cache::forever($key, $snapshot);
        $progressKey = $key . ':progress';
        $progress = Cache::get($progressKey);
        $stateKey = 'device_handover:user:' . $this->user->id;
        $state = Cache::get($stateKey);
        try {
            $this->protocol->handle($this->nodes[1], 'sync', ['run' => $this->runID(1), 'lease_cache' => 1,
                'sequence' => ++$this->sequences[1], 'unchanged' => true, 'base_sequence' => $base['sequence']]);
            $this->fail('完整快照与进度不一致时必须要求重新同步');
        } catch (\Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException) {
            $this->assertSame($progress, Cache::get($progressKey));
            $this->assertSame($state, Cache::get($stateKey));
        }
        $this->assertNotEmpty($this->sync(1)['leases']);
    }

    public function test_unchanged_snapshot_still_checks_ban_expiry_quota_and_node_permissions(): void
    {
        $first = $this->admit();
        $base = $this->sync(1);
        $original = $this->user->getAttributes();
        foreach ([['banned' => 1], ['expired_at' => time() - 1], ['u' => 100000]] as $patch) {
            $this->user->forceFill($patch)->saveQuietly();
            $reply = $this->protocol->handle($this->nodes[1], 'sync', ['run' => $this->runID(1), 'lease_cache' => 1,
                'sequence' => ++$this->sequences[1], 'unchanged' => true, 'base_sequence' => $base['sequence']]);
            $this->assertSame([], $reply['leases']);
            $this->assertSame($first['lease'], $reply['revoked'][0]['lease']);
            $this->user->forceFill($original)->saveQuietly();
        }
        $this->nodes[1]->forceFill(['group_ids' => [2]])->save();
        $reply = $this->protocol->handle($this->nodes[1], 'sync', ['run' => $this->runID(1), 'lease_cache' => 1,
            'sequence' => ++$this->sequences[1], 'unchanged' => true, 'base_sequence' => $base['sequence']]);
        $this->assertSame([], $reply['leases']);
        $this->assertSame($first['lease'], $reply['revoked'][0]['lease']);
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
