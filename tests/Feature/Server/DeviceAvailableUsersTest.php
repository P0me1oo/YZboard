<?php

namespace Tests\Feature\Server;

use App\Models\Server;
use App\Models\User;
use App\Services\Plugin\HookManager;
use App\Services\ServerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DeviceAvailableUsersTest extends TestCase
{
    use RefreshDatabase;

    private function node(array $attributes = []): Server
    {
        return Server::create($attributes + ['name' => '设备资格测试', 'type' => Server::TYPE_VMESS,
            'host' => '127.0.0.1', 'port' => 443, 'server_port' => 443, 'rate' => '1', 'group_ids' => [1], 'enabled' => true]);
    }

    private function user(array $attributes = []): User
    {
        return User::create($attributes + ['email' => uniqid('device-ids-') . '@example.invalid', 'password' => 'unused-device-ids-test',
            'uuid' => '55555555-5555-5555-5555-555555555555', 'token' => str_repeat('e', 32),
            'group_id' => 1, 'transfer_enable' => 100000, 'u' => 0, 'd' => 0, 'banned' => 0, 'expired_at' => time() + 3600]);
    }

    public function test_ids_keep_group_quota_expiry_and_ban_filters_without_loading_credentials(): void
    {
        $node = $this->node();
        $allowed = $this->user();
        $permanent = $this->user(['expired_at' => null]);
        $this->user(['group_id' => 2]);
        $this->user(['banned' => 1]);
        $this->user(['expired_at' => time() - 60]);
        $this->user(['u' => 100000]);
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains($query->sql, 'v2_user')) $queries[] = $query->sql;
        });
        $this->assertSame([$allowed->id, $permanent->id], ServerService::getAvailableUserIds($node));
        $this->assertCount(1, $queries);
        $this->assertMatchesRegularExpression('/select ["`]?id["`]? from/', $queries[0]);
        $this->assertStringNotContainsString('uuid', $queries[0]);
        $this->assertStringNotContainsString('auto_speed_limit', $queries[0]);
        $allowed->update(['banned' => 1]);
        $this->assertSame([$permanent->id], ServerService::getAvailableUserIds($node));
    }

    public function test_plugin_filter_still_receives_full_user_fields_and_controls_ids(): void
    {
        $node = $this->node();
        $first = $this->user(['auto_speed_limit' => ['limit' => 20]]);
        $this->user();
        $filter = function ($users) use ($first) {
            $this->assertSame($first->uuid, $users->first()->uuid);
            $this->assertSame(['limit' => 20], $users->first()->auto_speed_limit);
            return $users->take(1);
        };
        HookManager::registerFilter('server.users.get', $filter);
        try {
            $this->assertSame([$first->id], ServerService::getAvailableUserIds($node));
        } finally {
            HookManager::remove('server.users.get', $filter);
        }
    }

    public function test_relay_entry_ids_match_route_permissions_and_child_has_no_panel_users(): void
    {
        $entry = $this->node(['type' => Server::TYPE_VLESS]);
        $child = $this->node(['type' => Server::TYPE_VLESS, 'relay_entry_id' => $entry->id, 'group_ids' => [2]]);
        $first = $this->user();
        $second = $this->user(['group_id' => 2]);
        $this->user(['group_id' => 3]);
        $this->assertSame([$first->id, $second->id], ServerService::getAvailableUserIds($entry));
        $this->assertSame(ServerService::getAvailableUsers($entry)->pluck('id')->all(), ServerService::getAvailableUserIds($entry));
        $this->assertSame([], ServerService::getAvailableUserIds($child));
    }

    public function test_wireguard_qualification_does_not_derive_peer_credentials(): void
    {
        $node = $this->node(['type' => Server::TYPE_WIREGUARD]);
        $user = $this->user();
        $expected = ServerService::getAvailableUsers($node)->pluck('id')->all();
        config(['app.key' => '']);
        $this->assertSame([$user->id], $expected);
        $this->assertSame($expected, ServerService::getAvailableUserIds($node));
    }
}
