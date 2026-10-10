<?php

namespace Tests\Unit\Services;

use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Services\DeviceHandoverService;
use App\Services\DeviceStateService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;
use Tests\TestCase;

class DeviceHandoverServiceTest extends TestCase
{
    use RefreshDatabase;
    private DeviceHandoverService $service;
    private array $sequence = [];
    private array $legacy = [];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Carbon::setTestNow('2026-10-08 00:00:00');
        $devices = \Mockery::mock(DeviceStateService::class);
        $devices->shouldReceive('getDeviceSources')->andReturnUsing(fn (int $id) => $this->legacy[$id] ?? []);
        $this->service = new DeviceHandoverService($devices);
        Server::withoutEvents(function (): void {
            foreach ([1, 2, 3, 99] as $id) {
                Server::forceCreate(['id' => $id, 'name' => '设备清理测试', 'type' => 'vmess',
                    'host' => '127.0.0.1', 'port' => 443, 'server_port' => 443, 'rate' => '1', 'group_ids' => []]);
            }
        });
        foreach ([1, 2, 3] as $node) {
            $this->sequence[$node] = 0;
            $this->service->begin($node, $this->runID($node));
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function runID(int $node): string { return str_repeat(dechex($node), 32); }

    public function test_deleted_node_with_stale_snapshot_no_longer_blocks_replacement(): void
    {
        $a = $this->admit('8.8.8.8', 1, 1);
        $this->sync([$this->source($a, '8.8.8.8', 1)]);
        $this->assertSame('waiting', $this->admit('1.1.1.1', 2, 1)['status']);
        // 模拟旧版本删除或批量删除，未经过模型事件，快照仍保留。
        Server::query()->whereKey(1)->delete();
        Carbon::setTestNow(now()->addHours(3));
        $reply = $this->admit('1.1.1.1', 2, 1);
        $this->assertSame('allowed', $reply['status']);
        $this->assertSame($reply['lease'], $this->admit('1.1.1.1', 2, 1)['lease']);
        $this->assertNull(Cache::get('device_handover:user:10')['pending']);
    }

    public function test_deleted_node_cleanup_keeps_other_owners_and_is_idempotent(): void
    {
        $a = $this->admit('8.8.8.8', 1, 1);
        $this->admit('8.8.8.8', 2, 1);
        $this->sync([$this->source($a, '8.8.8.8', 1)], 1);
        $this->sync([$this->source($a, '8.8.8.8', 1)], 2);
        $this->assertSame('waiting', $this->admit('1.1.1.1', 3, 1)['status']);
        Server::query()->whereKey(1)->delete();
        $this->service->deletedNode(1);
        $this->service->deletedNode(1);
        $this->assertFalse(Cache::has('device_handover:node:1'));
        $this->assertFalse(Cache::has('device_handover:node:1:progress'));
        $this->assertFalse(Cache::has('device_handover:queue:1'));
        $this->assertSame([2], array_keys(Cache::get('device_handover:user:10')['sources']['8.8.8.8']['owners']));
        $this->assertSame('waiting', $this->admit('1.1.1.1', 3, 1)['status']);
        $this->sync([], 2);
        $this->assertSame('allowed', $this->admit('1.1.1.1', 3, 1)['status']);
    }

    public function test_existing_node_with_missing_or_stale_state_keeps_its_slot(): void
    {
        $a = $this->admit('8.8.8.8', 1, 1);
        $this->sync([$this->source($a, '8.8.8.8', 1)]);
        Carbon::setTestNow(now()->addHours(3));
        $this->service->deletedNode(1);
        $this->assertTrue(Cache::has('device_handover:node:1'));
        $this->assertSame('waiting', $this->admit('1.1.1.1', 2, 1)['status']);
        Cache::forget('device_handover:node:1');
        $this->assertSame('waiting', $this->admit('1.1.1.1', 2, 1)['status']);
    }

    public function test_deleted_legacy_node_does_not_consume_a_slot(): void
    {
        $this->legacy[10] = ['8.8.8.8' => [99]];
        Server::query()->whereKey(99)->delete();
        $this->assertSame('allowed', $this->admit('1.1.1.1', 1, 1)['status']);
    }

    public function test_pending_grant_without_node_snapshot_is_cleaned_after_deletion(): void
    {
        $this->admit('8.8.8.8', 1, 1);
        Server::query()->whereKey(1)->delete();
        $this->service->deletedNode(1);
        $this->assertSame([], Cache::get('device_handover:user:10')['sources']);
        $this->assertSame('allowed', $this->admit('1.1.1.1', 2, 1)['status']);
    }

    public function test_database_failure_does_not_release_device_ownership(): void
    {
        $this->admit('8.8.8.8', 1, 1);
        $before = Cache::get('device_handover:user:10');
        $this->failNextQueryMatching('/select .*v2_server/i');
        try {
            $this->admit('1.1.1.1', 2, 1);
            $this->fail('数据库查询失败时不能推断节点已删除');
        } catch (\Illuminate\Database\QueryException) {
            $this->assertSame($before, Cache::get('device_handover:user:10'));
        }
    }

    private function admit(string $ip, int $node = 1, int $limit = 2, int $user = 10): array
    {
        return $this->service->admit($node, $user, $limit, [
            'run' => $this->runID($node), 'sequence' => ++$this->sequence[$node], 'ip' => $ip,
        ]);
    }

    private function source(array $admission, string $ip, int $connectSequence, int $age = 0, int $user = 10): array
    {
        return ['lease' => $admission['lease'], 'ip' => $ip, 'user_id' => $user,
            'connect_sequence' => $connectSequence, 'age_ms' => $age];
    }

    private function sync(array $sources, int $node = 1, array $pending = []): array
    {
        return $this->service->sync($node, ['run' => $this->runID($node),
            'sequence' => ++$this->sequence[$node], 'pending' => $pending, 'sources' => $sources]);
    }

    private function renew(int $base, int $node = 1): array
    {
        return $this->service->sync($node, ['run' => $this->runID($node), 'sequence' => ++$this->sequence[$node],
            'unchanged' => true, 'base_sequence' => $base]);
    }

    public function test_renewal_preserves_sources_and_still_waits_for_actual_close(): void
    {
        $a = $this->admit('8.8.8.8', 1, 1);
        $base = $this->sync([$this->source($a, '8.8.8.8', 1)])['sequence'];
        $before = Cache::get('device_handover:node:1');
        $this->legacy[10] = ['8.8.8.8' => [1]];
        Carbon::setTestNow(now()->addSeconds(20));
        $this->renew($base);
        $this->assertSame($before, Cache::get('device_handover:node:1'), '续期不应重写完整来源');
        $this->assertSame(now()->getTimestampMs(), Cache::get('device_handover:node:1:progress')['seen_at']);
        $this->assertSame('waiting', $this->admit('1.1.1.1', 2, 1)['status']);
        $this->assertSame([$a['lease']], array_column($this->renew($base)['revoked'], 'lease'));
        $this->assertSame('waiting', $this->admit('1.1.1.1', 2, 1)['status']);
        $this->sync([]);
        $this->assertSame('allowed', $this->admit('1.1.1.1', 2, 1)['status']);
    }

    public function test_renewal_keeps_pending_grants_and_rejects_old_full_snapshots(): void
    {
        $this->admit('8.8.8.8', 1, 1);
        $base = $this->sync([], 1, [1])['sequence'];
        $renewal = $this->renew($base);
        $this->service->sync(1, ['run' => $this->runID(1), 'sequence' => $base, 'pending' => [], 'sources' => []]);
        $this->assertSame('waiting', $this->admit('1.1.1.1', 2, 1)['status']);
        $progress = Cache::get('device_handover:node:1:progress');
        Carbon::setTestNow(now()->addSecond());
        $this->service->sync(1, ['run' => $this->runID(1), 'sequence' => $renewal['sequence'], 'unchanged' => true, 'base_sequence' => $base]);
        $this->assertSame($progress, Cache::get('device_handover:node:1:progress'), '重复确认不应倒退或重新续期');
    }

    public function test_redis_renewal_avoids_loading_full_sources_and_rejects_eviction(): void
    {
        $base = $this->sync([])['sequence'];
        $prefix = 'renewal-test:';
        $nodeKey = $prefix . 'device_handover:node:1';
        $progressKey = $nodeKey . ':progress';
        $values = [
            $nodeKey => serialize(Cache::get('device_handover:node:1')),
            $progressKey => serialize(Cache::get('device_handover:node:1:progress')),
        ];
        $original = $values[$nodeKey];
        $connection = \Mockery::mock(\Illuminate\Redis\Connections\Connection::class);
        $connection->shouldReceive('get')->andReturnUsing(function (string $key) use (&$values, $nodeKey) {
            if ($key === $nodeKey) throw new \RuntimeException('稳定续期不应读取完整来源');
            return $values[$key] ?? null;
        });
        $connection->shouldReceive('set')->andReturnUsing(function (string $key, mixed $value) use (&$values): bool {
            $values[$key] = $value;
            return true;
        });
        $connection->shouldReceive('exists')->with($nodeKey)->andReturnUsing(function () use (&$values, $nodeKey): int {
            return (int) array_key_exists($nodeKey, $values);
        });
        $factory = \Mockery::mock(\Illuminate\Contracts\Redis\Factory::class);
        $factory->shouldReceive('connection')->with('cache')->andReturn($connection);
        $store = \Mockery::mock(\Illuminate\Cache\RedisStore::class . '[lock]', [$factory, $prefix, 'cache']);
        $locks = new \Illuminate\Cache\ArrayStore();
        $store->shouldReceive('lock')->andReturnUsing(fn ($name, $seconds) => $locks->lock($name, $seconds));
        Cache::swap(new \Illuminate\Cache\Repository($store));

        Carbon::setTestNow(now()->addSecond());
        $reply = $this->renew($base);
        $this->assertSame($original, $values[$nodeKey]);
        $progress = unserialize($values[$progressKey]);
        $this->assertSame($reply['sequence'], $progress['sequence']);
        $this->assertSame(now()->getTimestampMs(), $progress['seen_at']);

        unset($values[$nodeKey]);
        try {
            $this->renew($base);
            $this->fail('完整来源丢失时不能继续确认续期');
        } catch (PreconditionFailedHttpException $exception) {
            $this->assertSame(412, $exception->getStatusCode());
        }
        $this->assertSame($progress, unserialize($values[$progressKey]));
    }

    public function test_missing_renewal_metadata_requests_full_snapshot_without_destroying_session(): void
    {
        $a = $this->admit('8.8.8.8', 1, 1);
        $sources = [$this->source($a, '8.8.8.8', 1)];
        $base = $this->sync($sources)['sequence'];
        Cache::forget('device_handover:node:1:progress');
        try {
            $this->renew($base);
            $this->fail('续期基线缺失时不能确认');
        } catch (PreconditionFailedHttpException $exception) {
            $this->assertSame(412, $exception->getStatusCode());
        }
        $base = $this->sync($sources)['sequence'];
        $this->renew($base);
        $this->assertSame('waiting', $this->admit('1.1.1.1', 2, 1)['status']);
    }

    public function test_old_renewal_baseline_cannot_overwrite_new_full_snapshot(): void
    {
        $base = $this->sync([])['sequence'];
        $a = $this->admit('8.8.8.8', 1, 1);
        $this->sync([$this->source($a, '8.8.8.8', $this->sequence[1])]);
        $this->expectException(PreconditionFailedHttpException::class);
        $this->renew($base);
    }

    public function test_failed_full_snapshot_write_cannot_publish_a_renewal_baseline(): void
    {
        $store = new class extends \Illuminate\Cache\ArrayStore {
            public bool $failWrite = false;
            public function forever($key, $value)
            {
                if ($this->failWrite && $key === 'device_handover:node:1') return false;
                return parent::forever($key, $value);
            }
        };
        Cache::swap(new \Illuminate\Cache\Repository($store));
        $this->service->begin(1, $this->runID(1));
        $store->failWrite = true;
        try {
            $this->sync([]);
            $this->fail('写入失败不能确认完整快照');
        } catch (\RuntimeException $exception) {
            $this->assertSame('设备来源完整快照写入失败', $exception->getMessage());
        }
        $this->assertFalse(Cache::has('device_handover:node:1:progress'));
        $this->assertSame(0, Cache::get('device_handover:node:1')['sequence']);
    }

    public function test_renewal_from_previous_run_is_a_session_conflict(): void
    {
        $base = $this->sync([])['sequence'];
        $this->service->begin(1, str_repeat('e', 32));
        $this->expectException(ConflictHttpException::class);
        $this->renew($base);
    }

    public function test_replaces_least_recent_new_connection_and_waits_for_actual_close(): void
    {
        $a = $this->admit('8.8.8.8');
        $this->sync([$this->source($a, '8.8.8.8', 1)]);
        Carbon::setTestNow(now()->addSeconds(10));
        $b = $this->admit('1.1.1.1');
        $this->sync([$this->source($a, '8.8.8.8', 1, 10000), $this->source($b, '1.1.1.1', 3)]);
        Carbon::setTestNow(now()->addSeconds(10));
        $this->sync([$this->source($a, '8.8.8.8', 5), $this->source($b, '1.1.1.1', 3, 10000)]);

        $c = $this->admit('9.9.9.9');
        $this->assertSame('waiting', $c['status']);
        $this->assertSame([$b['lease']], array_column($c['revoked'], 'lease'));
        $this->sync([$this->source($a, '8.8.8.8', 5), $this->source($b, '1.1.1.1', 3, 10000)]);
        $this->assertSame('waiting', $this->admit('9.9.9.9')['status']);

        $this->sync([$this->source($a, '8.8.8.8', 5)]);
        $this->assertSame('allowed', $this->admit('9.9.9.9')['status']);
        $this->assertSame('cooldown', $this->admit('1.1.1.1')['reason']);
        Carbon::setTestNow(now()->addSeconds(59));
        $this->assertSame('cooldown', $this->admit('1.1.1.1')['reason']);
        Carbon::setTestNow(now()->addSecond());
        $this->assertSame('waiting', $this->admit('1.1.1.1')['status']);
    }

    public function test_same_ip_on_two_nodes_must_close_on_both_before_replacement(): void
    {
        $a = $this->admit('8.8.8.8', 1, 1);
        $same = $this->admit('8.8.8.8', 2, 1);
        $this->assertSame($a['lease'], $same['lease']);
        $this->sync([$this->source($a, '8.8.8.8', 1)], 1);
        $this->sync([$this->source($a, '8.8.8.8', 1)], 2);
        $this->assertSame('waiting', $this->admit('1.1.1.1', 3, 1)['status']);
        $this->sync([], 1);
        $this->assertSame('waiting', $this->admit('1.1.1.1', 3, 1)['status']);
        $reply = $this->sync([$this->source($a, '8.8.8.8', 1)], 2);
        $this->assertSame([$a['lease']], array_column($reply['revoked'], 'lease'));
        $this->sync([], 2);
        $this->assertSame('allowed', $this->admit('1.1.1.1', 3, 1)['status']);
    }

    public function test_cooldown_allows_early_return_when_a_real_slot_is_free(): void
    {
        $a = $this->admit('8.8.8.8', 1, 1);
        $this->sync([$this->source($a, '8.8.8.8', 1)]);
        $this->admit('1.1.1.1', 1, 1);
        $this->sync([]);
        $b = $this->admit('1.1.1.1', 1, 1);
        $this->sync([$this->source($b, '1.1.1.1', $this->sequence[1])]);
        $this->assertSame('cooldown', $this->admit('8.8.8.8', 1, 1)['reason']);
        $this->sync([]);
        $returned = $this->admit('8.8.8.8', 1, 1);
        $this->assertSame('allowed', $returned['status']);
        $this->assertNotSame($a['lease'], $returned['lease']);
    }

    public function test_pending_http_grant_is_not_mistaken_for_a_closed_connection(): void
    {
        $a = $this->admit('8.8.8.8', 1, 1);
        $this->sync([], 1, [1]);
        $this->assertSame('waiting', $this->admit('1.1.1.1', 2, 1)['status']);
        $this->sync([$this->source($a, '8.8.8.8', 3)], 1);
        $this->assertSame('waiting', $this->admit('1.1.1.1', 2, 1)['status']);
        $this->sync([], 1);
        $this->assertSame('allowed', $this->admit('1.1.1.1', 2, 1)['status']);
    }

    public function test_repeated_snapshots_do_not_refresh_new_connection_recency(): void
    {
        $a = $this->admit('8.8.8.8');
        $this->sync([$this->source($a, '8.8.8.8', 1)]);
        Carbon::setTestNow(now()->addSeconds(20));
        $b = $this->admit('1.1.1.1');
        $this->sync([$this->source($a, '8.8.8.8', 1), $this->source($b, '1.1.1.1', 3)]);
        $replacement = $this->admit('9.9.9.9');
        $this->assertSame([$a['lease']], array_column($replacement['revoked'], 'lease'));
    }

    public function test_ipv6_prefix_is_one_slot_and_all_its_addresses_are_revoked(): void
    {
        $a = $this->admit('2400:cb00:1:2::1', 1, 1);
        $b = $this->admit('2400:cb00:1:2::2', 1, 1);
        $this->assertSame('allowed', $b['status']);
        $this->sync([$this->source($a, '2400:cb00:1:2::1', 1), $this->source($b, '2400:cb00:1:2::2', 2)]);
        $next = $this->admit('2400:cb00:1:3::1', 1, 1);
        $this->assertSame('waiting', $next['status']);
        $this->assertSame([$a['lease'], $b['lease']], array_column($next['revoked'], 'lease'));
        $this->sync([$this->source($b, '2400:cb00:1:2::2', 2)]);
        $this->assertSame('waiting', $this->admit('2400:cb00:1:3::1', 1, 1)['status']);
    }

    public function test_shared_public_ip_is_revoked_only_for_the_replaced_account(): void
    {
        $a = $this->admit('8.8.8.8', 1, 1, 10);
        $other = $this->admit('8.8.8.8', 1, 1, 20);
        $this->sync([$this->source($a, '8.8.8.8', 1), $this->source($other, '8.8.8.8', 2, 0, 20)]);
        $this->admit('1.1.1.1', 2, 1, 10);
        $result = $this->sync([$this->source($a, '8.8.8.8', 1), $this->source($other, '8.8.8.8', 2, 0, 20)]);
        $this->assertSame([$a['lease']], array_column($result['revoked'], 'lease'));
        $this->assertSame('allowed', $this->admit('8.8.8.8', 2, 1, 20)['status']);
    }

    public function test_legacy_node_cannot_be_silently_evicted_without_close_confirmation(): void
    {
        $this->legacy[10] = ['8.8.8.8' => [99]];
        $this->assertSame('legacy_node', $this->admit('1.1.1.1', 1, 1)['reason']);
        $this->assertSame('allowed', $this->admit('8.8.8.8', 1, 1)['status']);
    }

    public function test_old_snapshot_and_retired_node_run_cannot_clear_current_sources(): void
    {
        $a = $this->admit('8.8.8.8', 1, 1);
        $this->sync([$this->source($a, '8.8.8.8', 1)]);
        $this->service->sync(1, ['run' => $this->runID(1), 'sequence' => 1, 'pending' => [], 'sources' => []]);
        $this->assertSame('waiting', $this->admit('1.1.1.1', 2, 1)['status']);
        $this->service->begin(1, str_repeat('f', 32));
        $this->expectException(ConflictHttpException::class);
        $this->sync([], 1);
    }

    public function test_abandoned_candidate_does_not_hold_replacement_forever(): void
    {
        $a = $this->admit('8.8.8.8', 1, 1);
        $this->sync([$this->source($a, '8.8.8.8', 1)]);
        $this->admit('1.1.1.1', 2, 1);
        $this->assertSame('replacement_pending', $this->admit('9.9.9.9', 3, 1)['reason']);
        Carbon::setTestNow(now()->addSeconds(6));
        $this->assertSame('waiting', $this->admit('9.9.9.9', 3, 1)['status']);
        $this->sync([]);
        $this->assertSame('allowed', $this->admit('9.9.9.9', 3, 1)['status']);
    }

    public function test_rollback_to_legacy_node_restores_legacy_source_accounting(): void
    {
        $this->admit('8.8.8.8', 1, 1);
        $this->service->legacyNode(1);
        $this->legacy[10] = ['1.1.1.1' => [1]];
        $reply = $this->admit('9.9.9.9', 2, 1);
        $this->assertSame('legacy_node', $reply['reason']);
        $this->assertSame(1, $reply['observed']);
    }

    public function test_closed_source_is_removed_without_waiting_for_another_admission(): void
    {
        $a = $this->admit('8.8.8.8', 1, 1);
        $this->sync([$this->source($a, '8.8.8.8', 1)]);
        $this->service->sync(1, ['run' => $this->runID(1), 'sequence' => ++$this->sequence[1],
            'pending' => [], 'sources' => [], 'retired' => [['user_id' => 10, 'ip' => '8.8.8.8', 'lease' => $a['lease']]]]);
        $this->assertSame([], Cache::get('device_handover:user:10')['sources']);
        Carbon::setTestNow(now()->addSeconds(121));
        $this->assertFalse(Cache::has('device_handover:user:10'));
    }
}
