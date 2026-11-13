<?php

namespace Tests\Feature\Server;

use App\Http\Controllers\V2\Admin\Server\LoadBalancerController;
use App\Models\BalancerDnsPreset;
use App\Models\BalancerPool;
use App\Models\Server;
use App\Models\ServerMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PoolMemberValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        Redis::shouldReceive('publish')->andReturn(1);
        Route::post('/_tests/pool-members/save', [LoadBalancerController::class, 'save']);
    }

    private function payload(array $carrierGroups): array
    {
        $preset = BalancerDnsPreset::create(['name' => '测试 DNS', 'provider' => 'powerdns',
            'domain' => 'example.test', 'zone_id' => md5('example.test'), 'api_url' => 'https://dns-api.example.test',
            'server_id' => 'localhost', 'api_token' => Str::random(40)]);
        $members = [];
        foreach ($carrierGroups as $index => $carriers) {
            $machine = ServerMachine::create(['name' => '测试前置', 'token' => Str::random(32),
                'is_active' => true, 'agent_runtime' => ['version' => 'v2.30.0']]);
            $node = Server::create(['name' => '测试前置 ' . $index, 'machine_id' => $machine->id,
                'host' => '192.0.2.' . ($index + 1), 'type' => 'vless', 'kernel_type' => 'xray',
                'port' => '443', 'server_port' => 443, 'enabled' => true, 'show' => true, 'rate' => 1, 'group_ids' => [1],
                'protocol_settings' => ['network' => 'tcp', 'tls' => 2, 'reality_settings' => [
                    'private_key' => str_repeat('a', 43), 'public_key' => str_repeat('b', 43),
                    'short_id' => 'ab12', 'server_name' => 'handshake.example.test', 'server_port' => 443]]]);
            $members[] = ['node_id' => $node->id, 'priority' => 1, 'carriers' => $carriers];
        }
        return ['name' => '测试前置池', 'domain' => 'pool.example.test', 'dns_preset_id' => $preset->id,
            'enabled' => true, 'reality_sync_enabled' => false, 'members' => $members];
    }

    public static function sharedCarrierGroups(): array
    {
        return [
            'seven default members' => [array_fill(0, 7, ['default'])],
            'overlapping carriers' => [[['default', 'telecom'], ['telecom', 'unicom'], ['default', 'mobile']]],
            'all four carriers' => [array_fill(0, 2, ['default', 'telecom', 'unicom', 'mobile'])],
        ];
    }

    #[DataProvider('sharedCarrierGroups')]
    public function test_members_can_share_carriers_on_create_and_update(array $carrierGroups): void
    {
        $payload = $this->payload($carrierGroups);
        $response = $this->postJson('/_tests/pool-members/save', $payload)->assertOk();
        $pool = BalancerPool::findOrFail($response->json('data.id'));
        $this->assertSame($carrierGroups, $pool->members()->orderBy('position')->pluck('carriers')->all());

        $payload['id'] = $pool->id;
        $payload['name'] = '更新前置池';
        $payload['members'] = array_reverse($payload['members']);
        $this->postJson('/_tests/pool-members/save', $payload)->assertOk()->assertJsonPath('data.id', $pool->id);
        $this->assertSame('更新前置池', $pool->fresh()->name);
        $this->assertSame(array_reverse($carrierGroups), $pool->members()->orderBy('position')->pluck('carriers')->all());
        $this->assertSame(1, BalancerPool::count());
    }

    public static function invalidCarrierGroups(): array
    {
        return [
            'duplicate default' => [['default', 'default'], 'members.0.carriers'],
            'duplicate telecom' => [['telecom', 'default', 'telecom'], 'members.0.carriers'],
            'unknown carrier' => [['other'], 'members.0.carriers.0'],
            'empty list' => [[], 'members.0.carriers'],
            'not an array' => ['default', 'members.0.carriers'],
            'null list' => [null, 'members.0.carriers'],
            'too many options' => [['default', 'telecom', 'unicom', 'mobile', 'default'], 'members.0.carriers'],
            'nested array' => [[['default']], 'members.0.carriers.0'],
        ];
    }

    #[DataProvider('invalidCarrierGroups')]
    public function test_invalid_carriers_are_rejected_without_saving(mixed $carriers, string $field): void
    {
        $response = $this->postJson('/_tests/pool-members/save', $this->payload([$carriers, ['default']]))
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertArrayNotHasKey('members.1.carriers.0', $response->json('errors'));
        $this->assertDatabaseCount('v2_balancer_pool', 0);
        $this->assertDatabaseCount('v2_balancer_member', 0);
    }

    public function test_duplicate_carriers_in_a_later_member_leave_the_existing_pool_unchanged(): void
    {
        $payload = $this->payload([['default'], ['default', 'telecom']]);
        $response = $this->postJson('/_tests/pool-members/save', $payload)->assertOk();
        $pool = BalancerPool::findOrFail($response->json('data.id'));
        $beforePool = $pool->getAttributes();
        $beforeMembers = $pool->members()->orderBy('position')->get()->toArray();

        $payload['id'] = $pool->id;
        $payload['name'] = '不应保存的名称';
        $payload['members'][1]['carriers'] = ['default', 'telecom', 'default'];
        $response = $this->postJson('/_tests/pool-members/save', $payload)->assertUnprocessable()
            ->assertJsonValidationErrors('members.1.carriers');
        $this->assertSame(['同一成员的运营商分组不能重复'], $response->json('errors')['members.1.carriers']);
        $this->assertSame(['members.1.carriers'], array_keys($response->json('errors')));
        $this->assertSame($beforePool, $pool->fresh()->getAttributes());
        $this->assertSame($beforeMembers, $pool->members()->orderBy('position')->get()->toArray());
    }

    public function test_duplicate_nodes_are_still_rejected(): void
    {
        $payload = $this->payload([['default'], ['telecom']]);
        $payload['members'][1]['node_id'] = $payload['members'][0]['node_id'];
        $this->postJson('/_tests/pool-members/save', $payload)->assertUnprocessable()
            ->assertJsonValidationErrors(['members.0.node_id', 'members.1.node_id']);
        $this->assertDatabaseCount('v2_balancer_pool', 0);
    }

    public function test_omitted_carriers_default_on_create_and_preserve_existing_groups_on_update(): void
    {
        $payload = $this->payload([['default'], ['telecom', 'unicom']]);
        unset($payload['members'][0]['carriers']);
        $response = $this->postJson('/_tests/pool-members/save', $payload)->assertOk();
        $pool = BalancerPool::findOrFail($response->json('data.id'));
        $expected = [['default'], ['telecom', 'unicom']];
        $this->assertSame($expected, $pool->members()->orderBy('position')->pluck('carriers')->all());
        $payload['id'] = $pool->id;
        unset($payload['members'][1]['carriers']);
        $this->postJson('/_tests/pool-members/save', $payload)->assertOk();
        $this->assertSame($expected, $pool->members()->orderBy('position')->pluck('carriers')->all());
    }
}
