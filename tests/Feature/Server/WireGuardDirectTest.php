<?php

namespace Tests\Feature\Server;

use App\Http\Controllers\V2\Admin\Server\ManageController;
use App\Models\Server;
use App\Models\User;
use App\Protocols\ClashMeta;
use App\Protocols\General;
use App\Protocols\SingBox;
use App\Services\NodeSyncService;
use App\Services\ServerService;
use App\Services\WireGuardService;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class WireGuardDirectTest extends TestCase
{
    use RefreshDatabase;
    private array $pushes = [];

    protected function setUp(): void
    {
        parent::setUp();
        Route::post('/_tests/wg/save', [ManageController::class, 'save']);
        Redis::shouldReceive('publish')->byDefault()->andReturnUsing(function ($channel, $message) {
            $this->pushes[] = json_decode($message, true);
            return 1;
        });
    }

    private function node(array $extra = []): Server
    {
        return Server::create(array_replace(['type' => 'wireguard', 'name' => '普通 WG',
            'host' => 'wg.example.invalid', 'port' => '51820', 'server_port' => 51820,
            'rate' => 1, 'show' => true, 'enabled' => true, 'group_ids' => ['1'],
            'protocol_settings' => ['mtu' => 1420, 'keepalive' => 25]], $extra));
    }

    private function user(array $extra = []): User
    {
        return User::create(array_replace(['email' => Helper::randomChar(12) . '@example.invalid',
            'password' => 'testing-only', 'uuid' => Helper::guid(), 'token' => Helper::guid(),
            'group_id' => 1, 'transfer_enable' => 1000000000, 'expired_at' => null,
            'banned' => 0, 'u' => 0, 'd' => 0, 'speed_limit' => 10, 'device_limit' => 2], $extra));
    }

    public function test_direct_save_and_sources_without_entry(): void
    {
        foreach (['xray', 'singbox'] as $kernel) {
            $this->postJson('/_tests/wg/save', ['type' => 'wireguard', 'name' => '普通 WG',
                'host' => 'wg.example.invalid', 'port' => '51820', 'server_port' => 51820,
                'rate' => 1, 'kernel_type' => $kernel, 'relay_entry_id' => 0,
                'protocol_settings' => ['mtu' => 1420], 'source_policy' => ['block_cn' => true]])->assertOk();
            $config = ServerService::buildNodeConfig(Server::latest('id')->firstOrFail());
            $this->assertArrayNotHasKey('relay', $config);
            $this->assertTrue($config['source_policy']['block_cn']);
            $this->assertCount(2, $config['wireguard']['address']);
        }
    }

    public function test_keys_are_stable_separate_and_only_client_receives_its_private_key(): void
    {
        $node = $this->node(); $user = $this->user(); $other = $this->user();
        $first = WireGuardService::client($node, $user);
        $this->assertSame($first, WireGuardService::client($node->fresh(), $user->fresh()));
        $this->assertNotSame($first['private_key'], WireGuardService::client($node, $other)['private_key']);
        $this->assertNotSame($first['address'], WireGuardService::client($node, $other)['address']);
        $this->assertNotSame($first['private_key'], WireGuardService::client($this->node(), $user)['private_key']);
        $peer = collect(ServerService::getAvailableUsers($node))->firstWhere('id', $user->id)->wireguard;
        $this->assertArrayNotHasKey('private_key', $peer);
        $this->assertSame(base64_encode(\ParagonIE_Sodium_Compat::crypto_scalarmult_base(base64_decode($first['private_key']))), $peer['public_key']);
        $servers = ServerService::getAvailableServers($user);
        $this->assertSame($first, $servers[0]['wireguard']);
        $this->assertFalse(str_contains(json_encode($servers), WireGuardService::nodeConfig($node)['private_key']));
        $user->uuid = Helper::guid(); $user->save();
        $this->assertNotSame($first['private_key'], WireGuardService::client($node, $user)['private_key']);
        $this->assertSame($first['address'], WireGuardService::client($node, $user)['address']);
    }

    public function test_permissions_quotas_expiry_and_user_push(): void
    {
        $node = $this->node(); $user = $this->user(['expired_at' => time() + 3600]);
        $this->user(['group_id' => 2]); $this->user(['banned' => 1]);
        $this->user(['expired_at' => time() - 1]); $this->user(['u' => 1000000000]);
        $users = ServerService::getAvailableUsers($node);
        $this->assertCount(1, $users);
        $this->assertSame(2, $users[0]->device_limit);
        $this->assertSame(10, $users[0]->speed_limit);
        $this->assertSame($user->expired_at, $users[0]->wireguard['expires_at']);
        Cache::put("node_ws_alive:{$node->id}", true);
        NodeSyncService::notifyUserChanged($user);
        $push = collect($this->pushes)->where('event', 'sync.users')->last();
        $this->assertSame($users[0]->wireguard, $push['data']['users'][0]['wireguard']);
        $user->banned = 1; $user->save();
        $this->assertSame([], ServerService::getAvailableServers($user));
        $this->assertCount(0, ServerService::getAvailableUsers($node));
    }

    public function test_subscriptions_include_only_user_identity_and_modern_endpoint(): void
    {
        $node = $this->node(); $user = $this->user(); $servers = ServerService::getAvailableServers($user);
        $wg = $servers[0]['wireguard'];
        $mihomo = ClashMeta::buildWireGuard($servers[0]);
        $this->assertSame($wg['private_key'], $mihomo['private-key']);
        $this->assertSame($wg['public_key'], $mihomo['public-key']);
        $uri = General::buildWireGuard($servers[0]);
        $this->assertStringStartsWith('wireguard://', $uri);
        parse_str(parse_url(trim($uri), PHP_URL_QUERY), $query);
        $this->assertSame(implode(',', $wg['address']), $query['address']);
        foreach (['1.10.0', '1.14.0'] as $version) {
            $response = (new SingBox($user->toArray(), $servers, 'sing-box', $version))->handle();
            $config = json_decode($response->getContent(), true);
            $items = version_compare($version, '1.11.0', '>=') ? $config['endpoints'] : $config['outbounds'];
            $endpoint = collect($items)->firstWhere('type', 'wireguard');
            $this->assertSame($wg['private_key'], $endpoint['private_key']);
            if (version_compare($version, '1.11.0', '>=')) {
                $this->assertSame($wg['keepalive'], $endpoint['peers'][0]['persistent_keepalive_interval']);
            } else {
                $this->assertArrayNotHasKey('persistent_keepalive_interval', $endpoint);
            }
            $this->assertFalse(str_contains($response->getContent(), WireGuardService::nodeConfig($node)['private_key']));
        }
    }
}
