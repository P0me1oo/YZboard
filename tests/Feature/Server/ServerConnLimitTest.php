<?php

namespace Tests\Feature\Server;

use App\Models\Plan;
use App\Models\Server;
use App\Models\User;
use App\Services\Plugin\HookManager;
use App\Services\ServerService;
use App\Services\UserService;
use App\Support\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 连接数限制：字段下发、节点超限上报与套餐同步。
 */
class ServerConnLimitTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array<string, mixed>> 钩子收到的原始载荷 */
    private array $received = [];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.key', 'base64:' . base64_encode(str_repeat('a', 32)));
        $this->mock(Setting::class, function (MockInterface $mock): void {
            $settings = [
                'server_token' => 'server-token',
                'server_ws_enable' => 0,
                'server_push_interval' => 60,
            ];
            $mock->shouldReceive('get')->andReturnUsing(
                fn(string $key) => $settings[$key] ?? null
            );
        });

        $this->received = [];
        HookManager::register('server.limit.exceeded', function ($payload): void {
            $this->received[] = $payload;
        });
    }

    public function test_available_users_carry_conn_limit_fields(): void
    {
        $node = $this->makeServer();
        $user = $this->makeUser('conn-fields@example.invalid', 'a');
        $user->forceFill(['conn_limit' => 64, 'conn_rate_limit' => 8])->save();

        $users = ServerService::getAvailableUsers($node);
        $first = $users->firstWhere('id', $user->id);

        $this->assertNotNull($first);
        $this->assertSame(64, (int) $first->conn_limit);
        $this->assertSame(8, (int) $first->conn_rate_limit);
    }

    public function test_report_forwards_normalized_limit_events(): void
    {
        Bus::fake();

        $node = $this->makeServer();
        $user = $this->makeUser('conn-report@example.invalid', 'b');

        $this->postJson('/api/v2/server/report', [
            'token' => 'server-token',
            'node_id' => $node->id,
            'limit_events' => [
                ['user_id' => $user->id, 'kind' => 'conn', 'limit' => 32, 'observed' => 40, 'count' => 9],
                ['user_id' => (string) $user->id, 'kind' => 'rate', 'limit' => 5, 'observed' => 12, 'count' => 3],
            ],
        ])->assertOk()->assertJson(['data' => true]);

        $this->assertCount(1, $this->received);
        $payload = $this->received[0];
        $this->assertSame($node->id, $payload['node_id']);
        $this->assertSame('test-node', $payload['node_name']);
        $this->assertSame(Server::TYPE_VMESS, $payload['node_type']);
        $this->assertSame([
            ['user_id' => $user->id, 'kind' => 'conn', 'limit' => 32, 'observed' => 40, 'count' => 9],
            ['user_id' => $user->id, 'kind' => 'rate', 'limit' => 5, 'observed' => 12, 'count' => 3],
        ], $payload['events']);
    }

    public function test_report_drops_illegal_events_and_clamps_negative_numbers(): void
    {
        Bus::fake();

        $node = $this->makeServer();

        $this->postJson('/api/v2/server/report', [
            'token' => 'server-token',
            'node_id' => $node->id,
            'limit_events' => [
                'not-an-array',
                ['kind' => 'conn', 'limit' => 1],                      // 缺用户
                ['user_id' => 0, 'kind' => 'conn'],                    // 用户非法
                ['user_id' => 7, 'kind' => 'speed'],                   // 类型非法
                ['user_id' => 7, 'kind' => 'conn', 'limit' => -1, 'observed' => -5, 'count' => -3],
            ],
        ])->assertOk();

        $this->assertCount(1, $this->received);
        $this->assertSame([
            ['user_id' => 7, 'kind' => 'conn', 'limit' => 0, 'observed' => 0, 'count' => 0],
        ], $this->received[0]['events']);
    }

    public function test_report_forwards_device_limit_events_with_rejected_sources(): void
    {
        Bus::fake();

        $node = $this->makeServer();

        $this->postJson('/api/v2/server/report', [
            'token' => 'server-token',
            'node_id' => $node->id,
            'limit_events' => [
                ['user_id' => 5, 'kind' => 'conn', 'limit' => 8, 'observed' => 8, 'count' => 1, 'ips' => ['8.8.8.8']],
                ['user_id' => 5, 'kind' => 'device', 'limit' => 2, 'observed' => 2, 'count' => 6,
                    'ips' => ['8.8.8.8', '2001:db8::1', 'not-an-ip', 8, '8.8.8.8', '1.1.1.1', '1.0.0.1', '9.9.9.9', '4.4.4.4']],
                ['user_id' => 6, 'kind' => 'device', 'limit' => -1, 'observed' => -1, 'count' => 2, 'ips' => 'bad'],
            ],
        ])->assertOk();

        $this->assertCount(1, $this->received);
        $this->assertSame([
            // 并发和速率事件不带来源字段，旧插件看到的格式不变。
            ['user_id' => 5, 'kind' => 'conn', 'limit' => 8, 'observed' => 8, 'count' => 1],
            ['user_id' => 5, 'kind' => 'device', 'limit' => 2, 'observed' => 2, 'count' => 6,
                'ips' => ['8.8.8.8', '2001:db8::1', '1.1.1.1', '1.0.0.1', '9.9.9.9']],
            ['user_id' => 6, 'kind' => 'device', 'limit' => 0, 'observed' => 0, 'count' => 2, 'ips' => []],
        ], $this->received[0]['events']);
    }

    public function test_report_without_usable_limit_events_does_not_fire_hook(): void
    {
        Bus::fake();

        $node = $this->makeServer();
        $base = [
            'token' => 'server-token',
            'node_id' => $node->id,
        ];

        $this->postJson('/api/v2/server/report', $base)->assertOk();
        $this->postJson('/api/v2/server/report', $base + ['limit_events' => []])->assertOk();
        $this->postJson('/api/v2/server/report', $base + ['limit_events' => 'bad'])->assertOk();
        $this->postJson('/api/v2/server/report', $base + ['limit_events' => [['user_id' => 1, 'kind' => 'x']]])->assertOk();

        $this->assertSame([], $this->received);
    }

    public function test_repeated_reports_fire_one_hook_each_without_persisting(): void
    {
        Bus::fake();

        $node = $this->makeServer();
        $payload = [
            'token' => 'server-token',
            'node_id' => $node->id,
            'limit_events' => [
                ['user_id' => 3, 'kind' => 'conn', 'limit' => 10, 'observed' => 11, 'count' => 1],
            ],
        ];

        $this->postJson('/api/v2/server/report', $payload)->assertOk();
        $this->postJson('/api/v2/server/report', $payload)->assertOk();

        // 超限事件只透传给插件，面板不落库，因此重复上报必须原样触发两次。
        $this->assertCount(2, $this->received);
        $this->assertSame($this->received[0], $this->received[1]);
    }

    public function test_assign_plan_syncs_conn_limit_to_user(): void
    {
        $user = $this->makeUser('conn-plan@example.invalid', 'c');
        $user->forceFill(['conn_limit' => 999, 'conn_rate_limit' => 99])->save();

        $plan = Plan::create([
            'name' => 'Conn Limit Plan',
            'group_id' => null,
            'transfer_enable' => 10,
            'speed_limit' => null,
            'device_limit' => 3,
            'conn_limit' => 128,
            'conn_rate_limit' => 16,
            'show' => 1,
            'sort' => 0,
            'renew' => 1,
            'prices' => [Plan::PERIOD_MONTHLY => 10],
            'reset_traffic_method' => Plan::RESET_TRAFFIC_MONTHLY,
            'capacity_limit' => null,
        ]);

        (new UserService())->assignPlan($user, $plan, 30);
        $user->refresh();

        $this->assertSame(128, (int) $user->conn_limit);
        $this->assertSame(16, (int) $user->conn_rate_limit);

        // 未配置上限的套餐会清空用户上限，避免换套餐后继续沿用旧限制。
        $free = Plan::create([
            'name' => 'No Conn Limit Plan',
            'group_id' => null,
            'transfer_enable' => 10,
            'speed_limit' => null,
            'device_limit' => null,
            'show' => 1,
            'sort' => 1,
            'renew' => 1,
            'prices' => [Plan::PERIOD_MONTHLY => 10],
            'reset_traffic_method' => Plan::RESET_TRAFFIC_MONTHLY,
            'capacity_limit' => null,
        ]);

        (new UserService())->assignPlan($user, $free, 30);
        $user->refresh();

        $this->assertNull($user->conn_limit);
        $this->assertNull($user->conn_rate_limit);
    }

    #[DataProvider('createdUserLimits')]
    public function test_created_user_inherits_plan_limits(bool $trial, ?int $limit): void
    {
        $plan = Plan::create([
            'name' => '测试套餐',
            'transfer_enable' => 10,
            'device_limit' => $limit,
            'conn_limit' => $limit,
            'conn_rate_limit' => $limit,
            'prices' => [Plan::PERIOD_MONTHLY => 10],
        ]);
        $this->mock(Setting::class, function (MockInterface $mock) use ($trial, $plan): void {
            $settings = ['try_out_plan_id' => $trial ? $plan->id : 0, 'try_out_hour' => 1];
            $mock->shouldReceive('get')->andReturnUsing(
                fn(string $key) => $settings[$key] ?? null
            );
        });

        $data = ['email' => 'created-limits@example.invalid'];
        if (!$trial) {
            $data['plan_id'] = $plan->id;
            $data['expired_at'] = time() + 7200;
        }
        $user = (new UserService())->createUser($data);
        $user->save();
        $user->refresh();

        $this->assertSame($plan->id, $user->plan_id);
        foreach (['device_limit', 'conn_limit', 'conn_rate_limit'] as $field) {
            $this->assertSame($limit, $user->{$field}, $field);
        }
        $this->assertGreaterThan(time(), $user->expired_at);
    }

    public static function createdUserLimits(): array
    {
        return [
            '指定套餐有限制' => [false, 3],
            '试用套餐有限制' => [true, 3],
            '指定套餐空值' => [false, null],
            '试用套餐空值' => [true, null],
            '指定套餐零值' => [false, 0],
            '试用套餐零值' => [true, 0],
        ];
    }

    private function makeServer(string $type = Server::TYPE_VMESS): Server
    {
        return Server::create([
            'name' => 'test-node',
            'type' => $type,
            'host' => '127.0.0.1',
            'port' => 443,
            'server_port' => 443,
            'rate' => '1',
            'group_ids' => [1],
            'enabled' => true,
        ]);
    }

    private function makeUser(string $email, string $seed): User
    {
        return User::create([
            'email' => $email,
            'password' => 'unused',
            'uuid' => str_repeat($seed, 8) . '-' . str_repeat($seed, 4) . '-' . str_repeat($seed, 4)
                . '-' . str_repeat($seed, 4) . '-' . str_repeat($seed, 12),
            'token' => str_repeat($seed, 32),
            'group_id' => 1,
            'transfer_enable' => 1024 * 1024 * 1024,
            'expired_at' => time() + 3600,
        ]);
    }
}
