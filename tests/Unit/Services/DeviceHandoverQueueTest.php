<?php

namespace Tests\Unit\Services;

use App\Services\DeviceHandoverService;
use App\Services\DeviceStateService;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class DeviceQueueRecordingStore extends ArrayStore
{
    public int $queueWrites = 0;
    public int $queueLocks = 0;
    public int $queueBatches = 0;
    public ?string $failQueue = null;
    public ?\Closure $afterQueueRead = null;

    public function forever($key, $value)
    {
        if (str_starts_with($key, 'device_handover:queue:')) {
            $this->queueWrites++;
            if ($key === $this->failQueue) {
                $this->failQueue = null;
                throw new \RuntimeException('queue-write-test-failure');
            }
        }
        return parent::forever($key, $value);
    }

    public function lock($name, $seconds = 0, $owner = null)
    {
        if (str_starts_with($name, 'device_handover:queue:')) $this->queueLocks++;
        return parent::lock($name, $seconds, $owner);
    }

    public function many(array $keys)
    {
        $result = parent::many($keys);
        if ($keys !== [] && str_starts_with($keys[0], 'device_handover:queue:')) {
            $this->queueBatches++;
            $callback = $this->afterQueueRead;
            $this->afterQueueRead = null;
            if ($callback !== null) $callback();
        }
        return $result;
    }

    public function resetCounts(): void
    {
        $this->queueWrites = $this->queueLocks = $this->queueBatches = 0;
    }
}

class DeviceHandoverQueueTest extends TestCase
{
    use RefreshDatabase;

    private const USER = 999001;
    private DeviceQueueRecordingStore $store;
    private DeviceHandoverService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = new DeviceQueueRecordingStore();
        Cache::swap(new Repository($this->store));
        $this->service = new DeviceHandoverService($this->createMock(DeviceStateService::class));
    }

    private function seedHolders(bool $queued): void
    {
        $holders = [];
        foreach (range(1001, 1029) as $node) {
            $holders[$node . ':queue-test'] = ['node_id' => $node];
            if ($queued) Cache::forever('device_handover:queue:' . $node, [self::USER, self::USER + 1]);
        }
        $sources = [];
        foreach (range(1, 3) as $index) {
            $sources['192.0.2.' . $index] = ['holders' => $holders, 'owners' => [], 'revoked' => false];
        }
        Cache::forever('device_handover:user:' . self::USER, ['sources' => $sources, 'cooldown' => [], 'pending' => null]);
        $this->store->resetCounts();
    }

    private function reconcileUnchanged(): void
    {
        (new \ReflectionMethod(DeviceHandoverService::class, 'locked'))->invoke($this->service, self::USER, fn (array &$state) => null);
    }

    public function test_steady_multi_node_sync_has_no_queue_writes_or_queue_locks(): void
    {
        $this->seedHolders(true);
        foreach (range(1, 29) as $_) $this->reconcileUnchanged();
        $this->assertSame(0, $this->store->queueWrites, '已有队列不应随来源数和持有节点数反复重写');
        $this->assertSame(0, $this->store->queueLocks);
        $this->assertSame(29, $this->store->queueBatches, '每次账号协调只批量读取一次目标队列');
        $this->assertSame([self::USER, self::USER + 1], Cache::get('device_handover:queue:1001'));
    }

    public function test_new_membership_is_written_once_per_node_across_sources(): void
    {
        $this->seedHolders(false);
        $this->reconcileUnchanged();
        $this->assertSame(29, $this->store->queueWrites);
        $this->assertSame(29, $this->store->queueLocks);
        foreach (range(1001, 1029) as $node) $this->assertSame([self::USER], Cache::get('device_handover:queue:' . $node));
    }

    public function test_missing_queue_is_repaired_even_when_authority_is_unchanged(): void
    {
        $this->seedHolders(true);
        Cache::forget('device_handover:queue:1007');
        $this->reconcileUnchanged();
        $this->assertSame(1, $this->store->queueWrites);
        $this->assertSame(1, $this->store->queueLocks);
        $this->assertSame([self::USER], Cache::get('device_handover:queue:1007'));
        $this->assertSame([self::USER, self::USER + 1], Cache::get('device_handover:queue:1008'));
    }

    public function test_queue_write_failure_is_repaired_on_retry_after_authority_was_saved(): void
    {
        $this->seedHolders(false);
        $this->store->failQueue = 'device_handover:queue:1001';
        try {
            $this->reconcileUnchanged();
            $this->fail('注入的队列写入故障必须传播');
        } catch (\RuntimeException $e) {
            $this->assertSame('queue-write-test-failure', $e->getMessage());
        }
        $this->assertCount(3, Cache::get('device_handover:user:' . self::USER)['sources']);
        $this->store->resetCounts();
        $this->reconcileUnchanged();
        $this->assertSame(29, $this->store->queueWrites);
        foreach (range(1001, 1029) as $node) $this->assertSame([self::USER], Cache::get('device_handover:queue:' . $node));
    }

    public function test_revoked_and_pending_legacy_owners_remain_queued(): void
    {
        $sources = [
            ['revoked' => true, 'owners' => [1001 => ['connect_sequence' => 5]]],
            ['revoked' => false, 'owners' => [1002 => ['connect_sequence' => 0]]],
            ['revoked' => false, 'owners' => [1003 => ['connect_sequence' => 5]]],
        ];
        Cache::forever('device_handover:user:' . self::USER, ['sources' => $sources, 'cooldown' => [], 'pending' => null]);
        $this->reconcileUnchanged();
        $this->assertSame([self::USER], Cache::get('device_handover:queue:1001'));
        $this->assertSame([self::USER], Cache::get('device_handover:queue:1002'));
        $this->assertNull(Cache::get('device_handover:queue:1003'));
        $this->store->resetCounts();
        $this->reconcileUnchanged();
        $this->assertSame(0, $this->store->queueWrites);
        $this->assertSame(0, $this->store->queueLocks);
    }

    public function test_other_accounts_added_after_batch_read_are_preserved(): void
    {
        $this->seedHolders(false);
        Cache::forever('device_handover:queue:1001', [self::USER + 1]);
        $this->store->afterQueueRead = fn () => Cache::forever('device_handover:queue:1001', [self::USER + 1, self::USER + 2]);
        $this->reconcileUnchanged();
        $this->assertSame([self::USER + 1, self::USER + 2, self::USER], Cache::get('device_handover:queue:1001'));
    }

    public function test_repeated_removal_does_not_rewrite_other_accounts(): void
    {
        Cache::forever('device_handover:queue:1001', [self::USER, self::USER + 1]);
        $queue = new \ReflectionMethod(DeviceHandoverService::class, 'queue');
        $queue->invoke($this->service, 1001, self::USER, false);
        $this->store->resetCounts();
        $queue->invoke($this->service, 1001, self::USER, false);
        $this->assertSame(0, $this->store->queueWrites);
        $this->assertSame([self::USER + 1], Cache::get('device_handover:queue:1001'));
    }
}
