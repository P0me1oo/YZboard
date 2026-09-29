<?php

namespace Tests\Feature\Admin;

use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\User;
use App\Services\DeviceStateService;
use App\Services\RealtimeSnapshotService;
use App\Services\RealtimeStateStore;
use App\Services\RealtimeTicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class RealtimeAdminTest extends TestCase
{
    use RefreshDatabase;

    private function user(bool $admin = false): User
    {
        return User::create([
            'email' => ($admin ? 'admin' : 'user') . '@realtime.example.invalid',
            'password' => password_hash('test-only', PASSWORD_DEFAULT),
            'uuid' => \App\Utils\Helper::guid(true), 'token' => \App\Utils\Helper::guid(),
            'group_id' => 1, 'group_ids' => [1], 'is_admin' => $admin,
        ]);
    }

    public function test_optional_machine_samples_keep_the_existing_display_shape(): void
    {
        $machine = ServerMachine::create(['name' => '测试机器', 'token' => 'test-only', 'is_active' => true]);
        $store = app(RealtimeStateStore::class);
        $epoch = $store->begin('machine:' . $machine->id, str_repeat('c', 32))['epoch'];
        $store->accept('machine:' . $machine->id, $epoch, 1, ['status' => ['cpu' => 25, 'mem' => ['total' => 100, 'used' => 20]]]);
        $snapshots = app(RealtimeSnapshotService::class);
        $frame = $snapshots->snapshot($snapshots->validate(['machines' => [$machine->id]]));
        $this->assertSame(['total' => 0, 'used' => 0], $frame['machines'][$machine->id]['load_status']['disk']);
        $this->assertSame(25.0, $frame['machines'][$machine->id]['load_status']['cpu']);
        $this->assertArrayNotHasKey('token', $frame['machines'][$machine->id]);
    }

    public function test_ticket_is_single_use_and_checks_current_permissions(): void
    {
        $admin = $this->user(true);
        $tickets = app(RealtimeTicketService::class);
        $ticket = $tickets->issue($admin);
        $identity = $tickets->consume($ticket);
        $this->assertSame($admin->id, $identity['user_id']);
        $this->assertNull($tickets->consume($ticket));
        $admin->forceFill(['is_admin' => false])->save();
        $this->assertFalse($tickets->isValid($identity));
        $this->assertNull($tickets->consume($tickets->issue($admin)));
    }

    public function test_ticket_expires_and_revoked_access_cannot_keep_streaming(): void
    {
        $admin = $this->user(true);
        $token = $admin->createToken('realtime-test');
        $admin->withAccessToken($token->accessToken);
        $tickets = app(RealtimeTicketService::class);
        $identity = $tickets->consume($tickets->issue($admin));
        $this->assertTrue($tickets->isValid($identity));
        $token->accessToken->delete();
        $this->assertFalse($tickets->isValid($identity));
        $admin->withAccessToken(null);
        $ticket = $tickets->issue($admin);
        $this->travel(31)->seconds();
        $this->assertNull($tickets->consume($ticket));
    }

    public function test_snapshot_has_current_counts_and_ips_but_no_account_fields(): void
    {
        Cache::flush();
        $user = $this->user();
        $node = Server::create([
            'name' => '实时测试', 'type' => 'vless', 'host' => 'example.invalid',
            'port' => 443, 'server_port' => 443, 'group_ids' => [1], 'enabled' => true, 'rate' => 1,
        ]);
        $this->mock(DeviceStateService::class, function ($mock): void {
            $mock->shouldReceive('getDeviceIPs')->andReturn([]);
        });
        $store = app(RealtimeStateStore::class);
        $epoch = $store->begin('node:' . $node->id, str_repeat('a', 32))['epoch'];
        $store->accept('node:' . $node->id, $epoch, 1, [
            'connection_counts' => [$user->id => 7], 'alive' => [$user->id => ['8.8.8.8']],
        ]);
        $snapshots = app(RealtimeSnapshotService::class);
        $subscription = $snapshots->validate(['users' => [$user->id], 'devices' => [$user->id]]);
        $frame = $snapshots->snapshot($subscription);
        $this->assertSame(7, $frame['users'][$user->id]['connection_count']);
        $this->assertSame(['8.8.8.8'], $frame['devices'][$user->id]['ips']);
        foreach (['email', 'password', 'uuid', 'token', 'subscribe_url'] as $field) {
            $this->assertArrayNotHasKey($field, $frame['users'][$user->id]);
        }
        $store->accept('node:' . $node->id, $epoch, 2, ['connection_counts' => [], 'alive' => []]);
        $frame = $snapshots->snapshot($subscription);
        $this->assertSame(0, $frame['users'][$user->id]['connection_count']);
        $this->assertSame(0, $frame['users'][$user->id]['online_count']);
        $this->assertSame([], $frame['devices'][$user->id]['ips']);
        $this->travel(36)->seconds();
        $frame = $snapshots->snapshot($subscription);
        $this->assertNull($frame['users'][$user->id]['connection_count']);
        $this->assertNull($frame['users'][$user->id]['online_count']);
        $this->assertFalse($frame['devices'][$user->id]['known']);
    }
}
