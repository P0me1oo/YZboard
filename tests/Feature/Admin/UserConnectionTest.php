<?php

namespace Tests\Feature\Admin;

use App\Http\Controllers\V2\Admin\UserController;
use App\Http\Controllers\V2\Server\ServerController;
use App\Models\Server;
use App\Models\User;
use App\Services\UserConnectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class UserConnectionTest extends TestCase
{
    use RefreshDatabase;

    private function node(array $overrides = []): Server
    {
        return Server::create(array_replace([
            'name' => '连接数测试节点', 'type' => 'vless', 'host' => 'node.example.invalid',
            'port' => 443, 'server_port' => 443, 'group_ids' => [1], 'enabled' => true,
            'show' => true, 'rate' => 1,
        ], $overrides));
    }

    private function user(): User
    {
        return User::create([
            'email' => 'connections@example.invalid', 'password' => password_hash('unused-password', PASSWORD_DEFAULT),
            'uuid' => \App\Utils\Helper::guid(true), 'token' => \App\Utils\Helper::guid(),
            'group_id' => 1, 'group_ids' => [1], 'transfer_enable' => 1073741824,
        ]);
    }

    public function test_snapshots_replace_repeat_clear_and_recover(): void
    {
        $node = $this->node();
        $user = $this->user();
        $service = app(UserConnectionService::class);
        $this->assertNull($service->forUsers([$user])[$user->id]['connection_count']);
        foreach ([12, 12, 5] as $count) {
            $service->replace($node, [$user->id => $count]);
            $this->assertSame($count, $service->forUsers([$user])[$user->id]['connection_count']);
        }
        $service->replace($node, []);
        $this->assertSame(0, $service->forUsers([$user])[$user->id]['connection_count']);
        $this->travel(181)->seconds();
        $this->assertNull($service->forUsers([$user])[$user->id]['connection_count']);
        $service->replace($node, [$user->id => 3]);
        $this->assertSame(3, $service->forUsers([$user])[$user->id]['connection_count']);
    }

    public function test_totals_exclude_relay_landings_and_mark_partial_reports(): void
    {
        $entry = $this->node();
        $other = $this->node();
        $landing = $this->node(['relay_entry_id' => $entry->id]);
        $unrelated = $this->node(['group_ids' => [2]]);
        $user = $this->user();
        $service = app(UserConnectionService::class);
        $service->replace($entry, [$user->id => 12]);
        $service->replace($landing, [$user->id => 12]);
        $row = $service->forUsers([$user])[$user->id];
        $this->assertSame(12, $row['connection_count']);
        $this->assertTrue($row['connection_count_partial']);
        $service->replace($other, [$user->id => 8]);
        $row = $service->forUsers([$user])[$user->id];
        $this->assertSame(20, $row['connection_count']);
        $this->assertFalse($row['connection_count_partial']);
        // 插件额外授权的连接不能因为权限组不同而丢失。
        $service->replace($unrelated, [$user->id => 2]);
        $this->assertSame(22, $service->forUsers([$user])[$user->id]['connection_count']);
    }

    public function test_stale_nodes_mark_partial_and_multi_group_access_is_included(): void
    {
        $first = $this->node();
        $second = $this->node(['group_ids' => ['2']]);
        $this->node(['enabled' => false]);
        $user = $this->user();
        $user->group_ids = [1, 2];
        $service = app(UserConnectionService::class);
        $service->replace($first, [$user->id => 4]);
        $oldest = now()->timestamp;
        $this->travel(60)->seconds();
        $service->replace($second, [$user->id => 6]);
        $row = $service->forUsers([$user])[$user->id];
        $this->assertSame(10, $row['connection_count']);
        $this->assertSame($oldest, $row['connection_count_updated_at']);
        $this->assertFalse($row['connection_count_partial']);
        $this->travel(121)->seconds();
        $row = $service->forUsers([$user])[$user->id];
        $this->assertSame(6, $row['connection_count']);
        $this->assertTrue($row['connection_count_partial']);
    }

    public function test_invalid_reports_do_not_clear_or_extend_previous_snapshot(): void
    {
        $node = $this->node();
        $user = $this->user();
        $service = app(UserConnectionService::class);
        $service->replace($node, [$user->id => 7]);
        foreach ([['bad' => 9], [$user->id => -1], [$user->id => true], [$user->id => []], [$user->id => 1.5]] as $counts) {
            $service->replace($node, $counts);
            $this->assertSame(7, $service->forUsers([$user])[$user->id]['connection_count']);
        }
        $this->travel(181)->seconds();
        $service->replace($node, [$user->id => -1]);
        $this->assertNull($service->forUsers([$user])[$user->id]['connection_count']);
    }

    public function test_report_endpoint_caches_total_even_when_relay_breakdown_exists(): void
    {
        $entry = $this->node();
        $child = $this->node(['relay_entry_id' => $entry->id]);
        $user = $this->user();
        $report = function (array $data) use ($entry): void {
            $request = Request::create('/_tests/report', 'POST', $data);
            $request->attributes->set('node_info', $entry);
            $this->assertSame(200, app(ServerController::class)->report($request)->getStatusCode());
        };
        $report(['connection_counts' => [$user->id => 15], 'relay_connection_counts' => [$user->id => [0 => 5, $child->id => 10]]]);
        $service = app(UserConnectionService::class);
        $this->assertSame(15, $service->forUsers([$user])[$user->id]['connection_count']);
        $report([]);
        $this->assertSame(15, $service->forUsers([$user])[$user->id]['connection_count']);
        $report(['connection_counts' => []]);
        $this->assertSame(0, $service->forUsers([$user])[$user->id]['connection_count']);
    }

    public function test_user_list_returns_connection_counts_without_persisting_them(): void
    {
        $node = $this->node();
        $user = $this->user();
        app(UserConnectionService::class)->replace($node, [$user->id => 11]);
        Route::post('/_tests/users', [UserController::class, 'fetch']);
        $this->postJson('/_tests/users')->assertOk()
            ->assertJsonPath('data.0.connection_count', 11)
            ->assertJsonPath('data.0.connection_count_partial', false);
        $this->assertArrayNotHasKey('connection_count', $user->fresh()->getAttributes());
    }

    public function test_cache_read_failure_returns_unknown(): void
    {
        $this->node();
        $user = $this->user();
        Cache::shouldReceive('many')->once()->andThrow(new \RuntimeException('测试缓存不可用'));
        $this->assertNull(app(UserConnectionService::class)->forUsers([$user])[$user->id]['connection_count']);
    }
}
