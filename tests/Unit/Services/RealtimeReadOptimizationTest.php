<?php

namespace Tests\Unit\Services;

use App\Models\Server;
use App\Services\RealtimeReadContext;
use App\Services\RealtimeStateStore;
use App\Utils\CacheKey;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class RealtimeReadOptimizationTest extends TestCase
{
    private function node(): Server
    {
        return new Server(['id' => 901, 'parent_id' => 900, 'type' => 'vless']);
    }

    private function legacy(array $values): void
    {
        foreach ($values as $suffix => $value) Cache::put(CacheKey::get('SERVER_VLESS_' . $suffix, 900), $value, 600);
    }

    private function oldView(Server $node, ?array $state): array
    {
        $data = [];
        foreach (['last_check_at', 'last_push_at', 'online', 'is_online', 'available_status', 'load_status', 'metrics', 'online_conn'] as $field) {
            $data[$field] = $node->$field;
        }
        if ($state !== null) {
            $data['realtime_stale'] = !$state['fresh'];
            $data['is_online'] = $state['fresh'] ? 1 : 0;
            $data['last_check_at'] = $state['received_at'] ? intdiv($state['received_at'], 1000) : null;
            $data['metrics'] = $state['fresh'] ? ($state['data']['metrics'] ?? null) : null;
            $data['online_conn'] = $state['fresh'] ? ($data['metrics']['active_connections'] ?? null) : null;
            $data['online'] = $state['fresh'] && isset($state['data']['online']) ? count(array_filter($state['data']['online'])) : null;
            if (!$state['fresh']) $data['available_status'] = Server::STATUS_OFFLINE;
            unset($data['load_status']); // 新状态的负载格式仍由快照服务生成。
        }
        return $data;
    }

    public function test_runtime_projection_preserves_legacy_fresh_stale_empty_and_parent_semantics(): void
    {
        $now = time();
        $states = [null,
            ['fresh' => false, 'received_at' => null, 'data' => null],
            ['fresh' => true, 'received_at' => $now * 1000, 'data' => []],
            ['fresh' => true, 'received_at' => $now * 1000, 'data' => ['metrics' => ['active_connections' => 7], 'online' => [1 => 1, 2 => 0, 3 => 2]]],
            ['fresh' => false, 'received_at' => ($now - 36) * 1000, 'data' => ['online' => [1 => 2]]],
        ];
        foreach ([null, $now, $now - 300, $now - 301] as $check) {
            foreach ([null, $now, $now - 300, $now - 301] as $push) {
                Cache::flush();
                $this->legacy(['LAST_CHECK_AT' => $check, 'LAST_PUSH_AT' => $push,
                    'ONLINE_USER' => 19, 'METRICS' => ['active_connections' => 30], 'LOAD_STATUS' => ['cpu' => 50]]);
                foreach ($states as $state) {
                    $this->assertEquals($this->oldView($this->node(), $state), $this->node()->runtimeView($state));
                }
            }
        }
        Cache::flush();
        $this->assertEquals($this->oldView($this->node(), null), $this->node()->runtimeView(null));
    }

    public function test_online_node_reads_twelve_old_fields_but_only_two_fallback_values_with_runtime(): void
    {
        $this->legacy(['LAST_CHECK_AT' => time(), 'LAST_PUSH_AT' => time(), 'ONLINE_USER' => 1,
            'METRICS' => ['active_connections' => 7], 'LOAD_STATUS' => ['cpu' => 50]]);
        $reads = 0;
        app('events')->listen([CacheHit::class, CacheMissed::class], function () use (&$reads) { $reads++; });
        $state = ['fresh' => true, 'received_at' => time() * 1000, 'data' => ['online' => [], 'metrics' => []]];
        $expected = $this->oldView($this->node(), $state);
        $this->assertSame(12, $reads);
        $reads = 0;
        $this->assertEquals($expected, $this->node()->runtimeView($state));
        $this->assertSame(2, $reads);
        $reads = 0;
        $state['fresh'] = false;
        $this->assertSame(Server::STATUS_OFFLINE, $this->node()->runtimeView($state)['available_status']);
        $this->assertSame(1, $reads);
    }

    public function test_missing_records_are_reused_only_until_next_read_context(): void
    {
        $store = app(RealtimeStateStore::class);
        $context = new RealtimeReadContext($store);
        $this->assertSame(['node:1' => null], $context->readMany(['node:1', 'node:1']));
        $epoch = $store->begin('node:1', str_repeat('a', 32))['epoch'];
        $store->accept('node:1', $epoch, 1, []);
        $this->assertSame(['node:1' => null], $context->readMany(['node:1']));
        $this->assertTrue((new RealtimeReadContext($store))->readMany(['node:1'])['node:1']['fresh']);
        $this->travel(36)->seconds();
        $this->assertFalse((new RealtimeReadContext($store))->readMany(['node:1'])['node:1']['fresh']);
    }

    public function test_empty_read_avoids_cache_and_failed_read_is_retried(): void
    {
        Cache::shouldReceive('many')->never();
        $this->assertSame([], (new RealtimeStateStore())->readMany([]));
        $store = \Mockery::mock(RealtimeStateStore::class);
        $store->shouldReceive('readMany')->once()->with(['node:1'])->andThrow(new \RuntimeException('read failed'));
        $store->shouldReceive('readMany')->once()->with(['node:1'])->andReturn(['node:1' => null]);
        $context = new RealtimeReadContext($store);
        try {
            $context->readMany(['node:1']);
            $this->fail('Must fail');
        } catch (\RuntimeException) {
            $this->assertSame(['node:1' => null], $context->readMany(['node:1']));
        }
    }
}
