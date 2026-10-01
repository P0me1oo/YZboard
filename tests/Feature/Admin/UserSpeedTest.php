<?php

namespace Tests\Feature\Admin;

use App\Http\Controllers\V2\Admin\UserController;
use App\Models\Server;
use App\Models\User;
use App\Services\DeviceStateService;
use App\Services\NodeStateService;
use App\Services\RealtimeSnapshotService;
use App\Services\RealtimeStateStore;
use App\Services\UserSpeedService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class UserSpeedTest extends TestCase
{
    use RefreshDatabase;

    private function node(array $values = []): Server
    {
        return Server::create(array_replace([
            'name' => '网速测试', 'type' => 'vless', 'host' => 'example.invalid',
            'port' => 443, 'server_port' => 443, 'group_ids' => [1], 'enabled' => true, 'rate' => 3,
        ], $values));
    }

    private function user(): User
    {
        return User::create([
            'email' => 'speed@example.invalid', 'password' => password_hash('test-only', PASSWORD_DEFAULT),
            'uuid' => \App\Utils\Helper::guid(true), 'token' => \App\Utils\Helper::guid(),
            'group_id' => 1, 'group_ids' => [1, 2], 'u' => 123, 'd' => 456,
        ]);
    }

    private function report(Server $node, array $state, int $sequence = 1): void
    {
        $store = app(RealtimeStateStore::class);
        $epoch = $store->begin('node:' . $node->id, str_repeat('a', 32))['epoch'];
        app(NodeStateService::class)->accept($node, compact('epoch', 'sequence', 'state'));
    }

    private function speed(User $user): array
    {
        return app(UserSpeedService::class)->forUsers([$user])[$user->id];
    }

    public function test_sum_uses_actual_entries_without_billing_multiplier_and_marks_missing_nodes(): void
    {
        $user = $this->user();
        $entry = $this->node();
        $second = $this->node(['group_ids' => [2]]);
        $landing = $this->node(['relay_entry_id' => $entry->id]);
        $extra = $this->node(['group_ids' => [3]]);
        $this->node(['enabled' => false]);
        $this->report($entry, ['user_speeds' => [$user->id => [100, 200]]]);
        $this->report($landing, ['user_speeds' => [$user->id => [100, 200]]]);
        $this->assertSame(100, $this->speed($user)['upload_speed']);
        $this->assertTrue($this->speed($user)['speed_partial']);
        $oldest = now()->timestamp;
        $this->travel(2)->seconds();
        $this->report($second, ['user_speeds' => [$user->id => [30, 40]]]);
        $this->report($extra, ['user_speeds' => [$user->id => [5, 10]]]);
        $this->assertSame([
            'upload_speed' => 135, 'download_speed' => 250,
            'speed_partial' => false, 'speed_updated_at' => $oldest,
        ], $this->speed($user));
        $this->assertSame(123, $user->fresh()->u);
        $this->assertSame(456, $user->fresh()->d);
    }

    public function test_empty_missing_expired_repeated_and_restarted_samples(): void
    {
        $user = $this->user();
        $node = $this->node();
        $this->assertNull($this->speed($user)['upload_speed']);
        $this->report($node, ['connection_counts' => []]);
        $this->assertNull($this->speed($user)['upload_speed']);
        $this->report($node, ['user_speeds' => [$user->id => [100, 200]]], 2);
        $this->travel(20)->seconds();
        $this->report($node, ['user_speeds' => [$user->id => [100, 200]]], 2);
        $this->report($node, ['user_speeds' => [$user->id => [900, 900]]], 1);
        $this->assertSame(100, $this->speed($user)['upload_speed']);
        $this->travel(16)->seconds();
        $this->assertNull($this->speed($user)['upload_speed']);
        $this->report($node, ['user_speeds' => []], 3);
        $this->assertSame(0, $this->speed($user)['upload_speed']);
        $store = app(RealtimeStateStore::class);
        $epoch = $store->begin('node:' . $node->id, str_repeat('b', 32))['epoch'];
        $this->assertNull($this->speed($user)['upload_speed']);
        app(NodeStateService::class)->accept($node, ['epoch' => $epoch, 'sequence' => 1, 'state' => ['user_speeds' => [$user->id => [7, 9]]]]);
        $this->assertSame(9, $this->speed($user)['download_speed']);
    }

    public function test_invalid_speed_reports_cannot_replace_or_refresh_valid_sample(): void
    {
        $node = $this->node();
        $user = $this->user();
        $this->report($node, ['user_speeds' => [$user->id => [10, 20]]]);
        $before = app(RealtimeStateStore::class)->read('node:' . $node->id);
        foreach ([['bad' => [1, 2]], [0 => [1, 2]], [$user->id => [-1, 2]], [$user->id => [true, 2]],
            [$user->id => [1.5, 2]], [$user->id => [1]], [$user->id => [1, 2, 3]],
            [$user->id => ['1', 2]], [$user->id => [9007199254740992, 2]], [$user->id => null], null] as $speeds) {
            try {
                $this->report($node, ['user_speeds' => $speeds], 2);
                $this->fail('无效网速报告被接受');
            } catch (ValidationException) {
                $this->assertSame($before, app(RealtimeStateStore::class)->read('node:' . $node->id));
            }
        }
    }

    public function test_user_list_and_subscribed_snapshot_have_speed_without_persistence(): void
    {
        $node = $this->node();
        $user = $this->user();
        $this->report($node, ['user_speeds' => [$user->id => [1024, 2048]]]);
        Route::post('/_tests/user-speeds', [UserController::class, 'fetch']);
        $this->postJson('/_tests/user-speeds')->assertOk()
            ->assertJsonPath('data.0.upload_speed', 1024)->assertJsonPath('data.0.download_speed', 2048);
        $this->mock(DeviceStateService::class, fn ($mock) => $mock->shouldReceive('getDeviceIPs')->andReturn([]));
        $snapshots = app(RealtimeSnapshotService::class);
        $frame = $snapshots->snapshot($snapshots->validate(['users' => [$user->id]]));
        $this->assertSame(1024, $frame['users'][$user->id]['upload_speed']);
        $this->assertSame(2048, $frame['users'][$user->id]['download_speed']);
        $this->assertArrayNotHasKey('upload_speed', $user->fresh()->getAttributes());
        $this->assertSame([], $snapshots->snapshot($snapshots->validate([]))['users']);
    }

    public function test_cache_failure_is_unknown(): void
    {
        $user = $this->user();
        $this->node();
        Cache::shouldReceive('many')->once()->andThrow(new \RuntimeException('测试读取失败'));
        $this->assertNull($this->speed($user)['download_speed']);
    }

    public function test_runtime_sorting_happens_before_pagination_and_keeps_unknown_last(): void
    {
        Route::post('/_tests/user-sort', [UserController::class, 'fetch']);
        $node = $this->node();
        $users = collect(range(1, 5))->map(function ($index) {
            return User::create([
                'email' => "sort{$index}@example.invalid", 'password' => 'test-only',
                'uuid' => \App\Utils\Helper::guid(true), 'token' => \App\Utils\Helper::guid(),
                'group_id' => $index === 5 ? 99 : 1,
            ]);
        });
        [$low, $high, $tie, $zero, $unknown] = $users->all();
        $this->report($node, [
            'connection_counts' => [$low->id => 2, $high->id => 20, $tie->id => 20],
            'user_speeds' => [$low->id => [20, 200], $high->id => [200, 2000], $tie->id => [200, 2000]],
        ]);
        foreach (['connection_count', 'upload_speed', 'download_speed'] as $field) {
            foreach ([false, true] as $desc) {
                $expected = $desc ? [$tie->id, $high->id, $low->id, $zero->id, $unknown->id]
                    : [$zero->id, $low->id, $tie->id, $high->id, $unknown->id];
                $actual = [];
                foreach ([1, 2, 3] as $page) {
                    $result = $this->postJson('/_tests/user-sort', [
                        'sort' => [['id' => $field, 'desc' => $desc]], 'pageSize' => 2, 'current' => $page,
                    ])->assertOk()->assertJsonPath('total', 5)->assertJsonPath('last_page', 3);
                    $actual = array_merge($actual, array_column($result->json('data'), 'id'));
                }
                $this->assertSame($expected, $actual);
            }
        }
        $this->postJson('/_tests/user-sort', [
            'sort' => [['id' => 'connection_count', 'desc' => true]],
            'filter' => [['id' => 'email', 'value' => 'sort1@']], 'pageSize' => 2,
        ])->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $low->id);
        $this->report($node, ['connection_counts' => [], 'user_speeds' => []], 2);
        $this->postJson('/_tests/user-sort', ['sort' => [['id' => 'upload_speed', 'desc' => true]]])
            ->assertOk()->assertJsonPath('data.0.id', $zero->id)->assertJsonPath('data.0.upload_speed', 0);
        $this->travel(36)->seconds();
        $this->postJson('/_tests/user-sort', ['sort' => [['id' => 'upload_speed', 'desc' => false]]])
            ->assertOk()->assertJsonPath('data.0.id', $unknown->id)->assertJsonPath('data.0.upload_speed', null);
        $this->report($node, ['user_speeds' => [$low->id => [999, 888]]], 3);
        $this->postJson('/_tests/user-sort', ['sort' => [['id' => 'download_speed', 'desc' => true]]])
            ->assertOk()->assertJsonPath('data.0.id', $low->id)->assertJsonPath('data.0.download_speed', 888);
    }
}
