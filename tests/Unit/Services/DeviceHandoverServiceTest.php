<?php

namespace Tests\Unit\Services;

use App\Services\DeviceHandoverService;
use App\Services\DeviceStateService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class DeviceHandoverServiceTest extends TestCase
{
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
