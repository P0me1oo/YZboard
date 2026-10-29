<?php

namespace Tests\Feature\Server;

use App\Models\Server;
use App\Models\User;
use App\Services\DeviceHandoverService;
use App\Services\DeviceStateService;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CoordinationRecordingStore extends ArrayStore
{
    public int $batches = 0;
    public int $snapshotReads = 0;
    public int $authorityWrites = 0;
    public int $userLocks = 0;
    public int $queueWrites = 0;
    public int $queueLocks = 0;
    public ?\Closure $beforeUserLock = null;
    public ?\Closure $afterBatch = null;
    public bool $failAuthorityWrite = false;

    public function get($key)
    {
        if (preg_match('/^device_handover:node:\d+$/', $key)) $this->snapshotReads++;
        return parent::get($key);
    }

    public function many(array $keys)
    {
        foreach ($keys as $key) {
            if (str_starts_with($key, 'device_handover:node:') || str_starts_with($key, 'device_handover:queue:')) {
                $this->batches++;
                break;
            }
        }
        $result = parent::many($keys);
        if ($this->afterBatch && array_filter($keys, fn ($key) => str_starts_with($key, 'device_handover:queue:'))) {
            $callback = $this->afterBatch;
            $this->afterBatch = null;
            $callback();
        }
        return $result;
    }

    public function put($key, $value, $seconds)
    {
        if (preg_match('/^device_handover:user:\d+$/', $key)) {
            $this->authorityWrites++;
            if ($this->failAuthorityWrite) {
                $this->failAuthorityWrite = false;
                return false;
            }
        }
        if (str_starts_with($key, 'device_handover:queue:')) $this->queueWrites++;
        return parent::put($key, $value, $seconds);
    }

    public function lock($name, $seconds = 0, $owner = null)
    {
        if (str_starts_with($name, 'device_handover:queue:')) $this->queueLocks++;
        if (preg_match('/^device_handover:user:(\d+):lock$/', $name, $match)) {
            $this->userLocks++;
            if ($this->beforeUserLock) ($this->beforeUserLock)((int) $match[1]);
        }
        return parent::lock($name, $seconds, $owner);
    }

    public function resetCounts(): void
    {
        $this->batches = $this->snapshotReads = $this->authorityWrites = $this->userLocks = $this->queueWrites = $this->queueLocks = 0;
    }
}

class DeviceCoordinationPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private CoordinationRecordingStore $store;
    private DeviceHandoverService $service;
    private array $nodes = [];
    private array $users = [];
    private int $existenceQueries = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
        $this->mock(DeviceStateService::class, fn ($mock) => $mock->shouldReceive('getDeviceSources')->andReturn([]));
        $this->service = app(DeviceHandoverService::class);
        foreach (range(1, 4) as $index) {
            $this->nodes[] = Server::create(['name' => '协调测试', 'type' => 'vmess', 'host' => 'test.invalid',
                'port' => '443', 'server_port' => 443, 'rate' => 1, 'group_ids' => [1], 'enabled' => true]);
        }
        foreach (range(1, 12) as $index) {
            $this->users[] = User::create(['email' => 'coordination-' . $index . '@example.invalid', 'password' => 'test-only',
                'uuid' => sprintf('00000000-0000-0000-0000-%012d', $index), 'token' => hash('md5', 'coordination-' . $index),
                'group_id' => 1, 'device_limit' => 1, 'transfer_enable' => 100000, 'u' => 0, 'd' => 0, 'banned' => 0,
                'expired_at' => time() + 86400]);
        }
        $this->store = new CoordinationRecordingStore(true);
        Cache::swap(new Repository($this->store));
        $this->seedCoordination(true);
        DB::listen(function ($query): void {
            $sql = str_replace(['"', chr(96)], '', $query->sql);
            if (str_starts_with($sql, 'select id from v2_server where')) $this->existenceQueries++;
        });
    }

    private function runID(int $nodeId): string { return str_pad(dechex($nodeId), 32, '0', STR_PAD_LEFT); }

    private function seedCoordination(bool $active): void
    {
        $reported = [];
        foreach ($this->users as $index => $user) {
            $lease = str_pad(dechex($user->id), 32, 'a', STR_PAD_LEFT);
            $ip = '198.51.100.' . ($index + 1);
            $owners = $holders = [];
            foreach ($this->nodes as $node) {
                $owners[$node->id] = ['run' => $this->runID($node->id), 'sequence' => 1, 'connect_sequence' => 1, 'last_new_at' => now()->getTimestampMs()];
                $holders[$node->id . ':' . $this->runID($node->id)] = ['node_id' => $node->id, 'run' => $this->runID($node->id),
                    'sequence' => 1, 'confirmed_at' => now()->getTimestampMs(), 'expires_at' => now()->addHour()->getTimestampMs()];
            }
            $state = ['sources' => $active ? [$ip => ['user_id' => $user->id, 'ip' => $ip, 'lease' => $lease,
                'revoked' => false, 'owners' => $owners, 'holders' => $holders]] : [], 'cooldown' => [], 'pending' => null];
            if ($active) Cache::forever('device_handover:user:' . $user->id, $state);
            else Cache::put('device_handover:user:' . $user->id, $state, 120);
            if ($active) $reported[$lease] = ['user_id' => $user->id, 'ip' => $ip, 'lease' => $lease,
                'connect_sequence' => 1, 'age_ms' => 0, 'last_new_at' => now()->getTimestampMs()];
        }
        foreach ($this->nodes as $node) {
            $key = 'device_handover:node:' . $node->id;
            Cache::forever($key, ['run' => $this->runID($node->id), 'sequence' => 1, 'pending' => [], 'sources' => $reported,
                'version' => 1, 'seen_at' => now()->getTimestampMs(), 'lease_cache' => true]);
            Cache::forever($key . ':progress', ['run' => $this->runID($node->id), 'sequence' => 1, 'base_sequence' => 1, 'seen_at' => now()->getTimestampMs()]);
            Cache::forever('device_handover:queue:' . $node->id, $active ? array_map(fn ($user) => $user->id, $this->users) : []);
        }
        $this->store->resetCounts();
    }

    private function sync(int $sequence = 2): array
    {
        return $this->service->sync($this->nodes[0]->id, ['run' => $this->runID($this->nodes[0]->id),
            'lease_cache' => 1, 'sequence' => $sequence, 'unchanged' => true, 'base_sequence' => 1]);
    }

    private function state(int $index): array
    {
        return Cache::get('device_handover:user:' . $this->users[$index]->id);
    }

    public function test_multi_user_sync_combines_reads_and_queries_existing_nodes_once(): void
    {
        $reply = $this->sync();
        $this->assertCount(12, $reply['leases']);
        $this->assertSame([], $reply['revoked']);
        if ($path = getenv('YZ_TEST_COORDINATION_METRICS')) {
            file_put_contents($path, json_encode(['users' => 12, 'nodes' => 4, 'existence_queries' => $this->existenceQueries,
                'read_batches' => $this->store->batches, 'snapshot_reads' => $this->store->snapshotReads,
                'authority_writes' => $this->store->authorityWrites]) . "\n", FILE_APPEND);
        }
        $this->assertSame(1, $this->existenceQueries);
        $this->assertSame(12, $this->store->batches);
        $this->assertLessThanOrEqual(38, $this->store->snapshotReads);
    }

    public function test_unchanged_idle_accounts_expire_despite_continuing_node_sync(): void
    {
        $this->seedCoordination(false);
        $this->sync();
        $this->assertSame(0, $this->store->queueLocks);
        $this->travel(60)->seconds();
        $this->sync(3);
        $this->travel(61)->seconds();
        foreach ($this->users as $user) $this->assertNull(Cache::get('device_handover:user:' . $user->id));
        $this->store->resetCounts();
        $this->sync(4);
        $this->assertSame(0, $this->store->userLocks);
        $this->assertSame(0, $this->store->authorityWrites);
    }

    public function test_identical_confirmation_avoids_authority_writes_but_repairs_missing_queue(): void
    {
        $first = $this->sync();
        Cache::forget('device_handover:queue:' . $this->nodes[1]->id);
        $this->store->resetCounts();
        $this->travel(1)->seconds();
        $repeat = $this->sync();
        $this->assertSame($first['leases'], $repeat['leases']);
        $this->assertSame(0, $this->store->authorityWrites);
        $this->assertSame(12, $this->store->queueWrites);
        $this->assertSame(array_map(fn ($user) => $user->id, $this->users), Cache::get('device_handover:queue:' . $this->nodes[1]->id));
    }

    public function test_foreign_node_restart_between_accounts_is_read_after_each_account_lock(): void
    {
        $target = $this->users[1]->id;
        $node = $this->nodes[1]->id;
        $this->store->beforeUserLock = function ($userId) use ($target, $node): void {
            if ($userId !== $target) return;
            $this->store->beforeUserLock = null;
            $key = 'device_handover:node:' . $node;
            $snapshot = Cache::get($key);
            $snapshot['run'] = str_repeat('f', 32);
            Cache::forever($key, $snapshot);
            Cache::forget($key . ':progress');
        };
        $this->sync();
        $this->assertArrayHasKey($node, array_values($this->state(0)['sources'])[0]['owners']);
        $this->assertArrayNotHasKey($node, array_values($this->state(1)['sources'])[0]['owners']);
    }

    public function test_node_deletion_is_rechecked_on_next_sync_without_reusing_existence_across_rounds(): void
    {
        $node = $this->nodes[1]->id;
        $target = $this->users[1]->id;
        $this->store->beforeUserLock = function ($userId) use ($target, $node): void {
            if ($userId !== $target) return;
            $this->store->beforeUserLock = null;
            DB::table('v2_server')->where('id', $node)->delete();
        };
        $this->sync();
        $this->sync(3);
        foreach (range(0, 11) as $index) $this->assertArrayNotHasKey($node, array_values($this->state($index)['sources'])[0]['owners']);
    }

    public function test_missing_node_result_does_not_hide_a_node_created_before_next_account_lock(): void
    {
        $node = $this->nodes[1];
        DB::table('v2_server')->where('id', $node->id)->delete();
        $target = $this->users[1]->id;
        $this->store->beforeUserLock = function ($userId) use ($target, $node): void {
            if ($userId !== $target) return;
            $this->store->beforeUserLock = null;
            DB::table('v2_server')->insert($node->getAttributes());
        };
        $this->sync();
        $this->assertArrayNotHasKey($node->id, array_values($this->state(0)['sources'])[0]['owners']);
        $this->assertArrayHasKey($node->id, array_values($this->state(1)['sources'])[0]['owners']);
    }

    public function test_authority_evicted_during_unchanged_confirmation_is_restored_before_reply(): void
    {
        $first = $this->sync();
        $key = 'device_handover:user:' . $this->users[0]->id;
        $state = Cache::get($key);
        $this->store->afterBatch = fn () => Cache::forget($key);
        $this->store->resetCounts();
        $repeat = $this->sync();
        $this->assertSame($first['leases'], $repeat['leases']);
        $this->assertSame($state, Cache::get($key));
        $this->assertSame(1, $this->store->authorityWrites);
    }

    public function test_failed_confirmation_write_is_not_acknowledged_and_same_sequence_can_retry(): void
    {
        $state = $this->state(0);
        $this->store->failAuthorityWrite = true;
        try {
            $this->sync();
            $this->fail('许可写入失败不能确认');
        } catch (\RuntimeException $error) {
            $this->assertSame('设备来源名额写入失败', $error->getMessage());
        }
        $this->assertSame($state, $this->state(0));
        $retry = $this->sync();
        $this->assertCount(12, $retry['leases']);
        foreach ($retry['leases'] as $lease) $this->assertSame(2, $lease['confirmed_sequence']);
    }
}
