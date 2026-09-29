<?php

namespace Tests\Unit\Services;

use App\Services\RealtimeStateStore;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class RealtimeStateStoreTest extends TestCase
{
    private RealtimeStateStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->store = new RealtimeStateStore();
    }

    public function test_http_and_websocket_share_one_ordered_snapshot(): void
    {
        $session = $this->store->begin('node:1', str_repeat('a', 32));
        $this->store->accept('node:1', $session['epoch'], 2, ['connections' => 7]);
        $this->travel(1)->seconds();
        $before = $this->store->read('node:1');
        $old = $this->store->accept('node:1', $session['epoch'], 1, ['connections' => 20]);
        $this->assertFalse($old['accepted']);
        $this->assertSame($before, $this->store->read('node:1'));
        $this->assertSame(['connections' => 7], $before['data']);
    }

    public function test_duplicate_does_not_extend_freshness(): void
    {
        $session = $this->store->begin('node:1', str_repeat('a', 32));
        $this->store->accept('node:1', $session['epoch'], 1, []);
        $this->travel(RealtimeStateStore::FRESH_SECONDS + 1)->seconds();
        $this->assertFalse($this->store->accept('node:1', $session['epoch'], 1, [])['accepted']);
        $this->assertFalse($this->store->read('node:1')['fresh']);
    }

    public function test_empty_snapshot_is_fresh_but_initial_state_is_unknown(): void
    {
        $session = $this->store->begin('node:1', str_repeat('a', 32));
        $this->assertFalse($this->store->read('node:1')['fresh']);
        $this->assertNull($this->store->read('node:1')['data']);
        $this->store->accept('node:1', $session['epoch'], 1, ['connection_counts' => []]);
        $this->assertTrue($this->store->read('node:1')['fresh']);
        $this->assertSame([], $this->store->read('node:1')['data']['connection_counts']);
    }

    public function test_reconnect_keeps_epoch_and_sequence(): void
    {
        $session = $this->store->begin('node:1', str_repeat('a', 32));
        $this->store->accept('node:1', $session['epoch'], 8, []);
        $reconnected = $this->store->begin('node:1', str_repeat('a', 32));
        $this->assertSame($session['epoch'], $reconnected['epoch']);
        $this->assertSame(8, $reconnected['sequence']);
    }

    public function test_new_runtime_rejects_old_messages(): void
    {
        $old = $this->store->begin('node:1', str_repeat('a', 32));
        $new = $this->store->begin('node:1', str_repeat('b', 32));
        $this->assertNotSame($old['epoch'], $new['epoch']);
        $this->expectException(ConflictHttpException::class);
        $this->store->accept('node:1', $old['epoch'], 100, []);
    }

    public function test_retired_runtime_cannot_take_over_again(): void
    {
        $this->store->begin('node:1', str_repeat('a', 32));
        $this->store->begin('node:1', str_repeat('b', 32));
        $this->expectException(ConflictHttpException::class);
        $this->store->begin('node:1', str_repeat('a', 32));
    }

    public function test_lost_cache_requires_handshake_instead_of_accepting_old_data(): void
    {
        $session = $this->store->begin('node:1', str_repeat('a', 32));
        Cache::flush();
        $this->expectException(ConflictHttpException::class);
        $this->store->accept('node:1', $session['epoch'], 1, []);
    }

    public function test_same_version_cannot_change_contents(): void
    {
        $session = $this->store->begin('node:1', str_repeat('a', 32));
        $this->store->accept('node:1', $session['epoch'], 1, ['connection_counts' => []]);
        $this->expectException(ConflictHttpException::class);
        $this->store->accept('node:1', $session['epoch'], 1, ['connection_counts' => [1 => 1]]);
    }

    public function test_sources_are_isolated_and_batch_read_preserves_freshness(): void
    {
        $a = $this->store->begin('node:1', str_repeat('a', 32));
        $b = $this->store->begin('machine:1', str_repeat('a', 32));
        $this->store->accept('node:1', $a['epoch'], 3, ['online' => []]);
        $this->assertNotSame($a['epoch'], $b['epoch']);
        $states = $this->store->readMany(['node:1', 'machine:1', 'node:2']);
        $this->assertTrue($states['node:1']['fresh']);
        $this->assertFalse($states['machine:1']['fresh']);
        $this->assertNull($states['node:2']);
        $this->assertArrayNotHasKey('run', $states['node:1']);
    }
}
