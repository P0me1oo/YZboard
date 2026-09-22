<?php

namespace Tests\Feature\Admin;

use App\Http\Controllers\V2\Admin\UserController;
use App\Models\Plan;
use App\Models\User;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * 后台用户管理：换套餐同步额度、生成用户的随机密码与一次性返回。
 */
class UserManageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Route::post('/_tests/admin-user/update', [UserController::class, 'update']);
        Route::post('/_tests/admin-user/generate', [UserController::class, 'generate']);
    }

    private function plan(string $name, array $overrides = []): Plan
    {
        return Plan::create(array_replace([
            'name' => $name,
            'group_id' => 1,
            'transfer_enable' => 100,
            'speed_limit' => 100,
            'device_limit' => 3,
            'conn_limit' => 64,
            'conn_rate_limit' => 8,
            'prices' => [Plan::PERIOD_MONTHLY => 10],
        ], $overrides));
    }

    private function user(array $overrides = []): User
    {
        return User::create(array_replace([
            'email' => 'manage@example.invalid',
            'password' => password_hash('unused-password', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(),
            'plan_id' => null,
            'group_id' => null,
            'transfer_enable' => 5 * 1073741824,
            'speed_limit' => 20,
            'device_limit' => null,
            'conn_limit' => null,
            'conn_rate_limit' => null,
            'expired_at' => time() + 86400,
        ], $overrides));
    }

    public function test_changing_plan_syncs_group_and_limits_from_the_new_plan(): void
    {
        $old = $this->plan('旧套餐', ['group_id' => 1, 'device_limit' => null]);
        $new = $this->plan('新套餐', ['group_id' => 2, 'transfer_enable' => 200, 'speed_limit' => 300, 'device_limit' => 5, 'conn_limit' => 128, 'conn_rate_limit' => 16]);
        $user = $this->user(['plan_id' => $old->id, 'group_id' => $old->group_id]);

        $this->postJson('/_tests/admin-user/update', ['id' => $user->id, 'plan_id' => $new->id])
            ->assertOk()->assertJsonPath('data', true);

        $user->refresh();
        $this->assertSame($new->id, $user->plan_id);
        $this->assertSame(2, (int) $user->group_id);
        $this->assertSame(200 * 1073741824, (int) $user->transfer_enable);
        $this->assertSame(300, (int) $user->speed_limit);
        $this->assertSame(5, (int) $user->device_limit);
        $this->assertSame(128, (int) $user->conn_limit);
        $this->assertSame(16, (int) $user->conn_rate_limit);
    }

    public function test_plan_without_limits_clears_the_old_limits_when_switching(): void
    {
        $old = $this->plan('有限制套餐');
        $free = $this->plan('无限制套餐', ['group_id' => 3, 'speed_limit' => null, 'device_limit' => null, 'conn_limit' => null, 'conn_rate_limit' => null]);
        $user = $this->user(['plan_id' => $old->id, 'group_id' => 1, 'device_limit' => 3, 'conn_limit' => 64, 'conn_rate_limit' => 8]);

        $this->postJson('/_tests/admin-user/update', ['id' => $user->id, 'plan_id' => $free->id])->assertOk();

        $user->refresh();
        $this->assertSame(3, (int) $user->group_id);
        $this->assertNull($user->speed_limit);
        $this->assertNull($user->device_limit);
        $this->assertNull($user->conn_limit);
        $this->assertNull($user->conn_rate_limit);
    }

    public function test_values_typed_in_the_same_edit_win_over_the_plan(): void
    {
        $new = $this->plan('新套餐', ['group_id' => 2, 'device_limit' => 5, 'speed_limit' => 300]);
        $user = $this->user();

        $this->postJson('/_tests/admin-user/update', [
            'id' => $user->id,
            'plan_id' => $new->id,
            'device_limit' => 9,
            'speed_limit' => null,
        ])->assertOk();

        $user->refresh();
        $this->assertSame(2, (int) $user->group_id);
        $this->assertSame(9, (int) $user->device_limit);
        $this->assertNull($user->speed_limit);
        // 未手填的字段仍按套餐同步。
        $this->assertSame(64, (int) $user->conn_limit);
        $this->assertSame(100 * 1073741824, (int) $user->transfer_enable);
    }

    public function test_keeping_the_same_plan_or_clearing_it_leaves_limits_untouched(): void
    {
        $plan = $this->plan('当前套餐', ['group_id' => 2, 'device_limit' => 5]);
        $user = $this->user(['plan_id' => $plan->id, 'group_id' => 1, 'device_limit' => 1, 'conn_limit' => 7]);

        // 重复提交当前套餐只同步权限组，不覆盖单独调整过的额度。
        $this->postJson('/_tests/admin-user/update', ['id' => $user->id, 'plan_id' => $plan->id])->assertOk();
        $user->refresh();
        $this->assertSame(2, (int) $user->group_id);
        $this->assertSame(1, (int) $user->device_limit);
        $this->assertSame(7, (int) $user->conn_limit);
        $this->assertSame(5 * 1073741824, (int) $user->transfer_enable);

        // 改成“无套餐”保留原有权限组和额度，与此前行为一致。
        $this->postJson('/_tests/admin-user/update', ['id' => $user->id, 'plan_id' => null, 'remarks' => '清空套餐'])->assertOk();
        $user->refresh();
        $this->assertNull($user->plan_id);
        $this->assertSame(2, (int) $user->group_id);
        $this->assertSame(1, (int) $user->device_limit);
        $this->assertSame('清空套餐', $user->remarks);
    }

    public function test_missing_plan_is_rejected_without_changing_the_user(): void
    {
        $user = $this->user(['device_limit' => 2]);

        $this->postJson('/_tests/admin-user/update', ['id' => $user->id, 'plan_id' => 999])
            ->assertStatus(400)->assertJsonPath('message', '订阅计划不存在');

        $this->assertSame(2, (int) $user->fresh()->device_limit);
    }

    public function test_single_generation_returns_a_random_password_once(): void
    {
        $plan = $this->plan('生成套餐', ['device_limit' => 4]);

        $response = $this->postJson('/_tests/admin-user/generate', [
            'email_prefix' => 'single',
            'email_suffix' => 'example.invalid',
            'plan_id' => $plan->id,
            'password' => '',
        ])->assertOk();

        $rows = $response->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame('single@example.invalid', $rows[0]['email']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{12}$/', $rows[0]['password']);
        $this->assertNotSame($rows[0]['email'], $rows[0]['password']);

        $user = User::byEmail('single@example.invalid')->firstOrFail();
        $this->assertTrue(password_verify($rows[0]['password'], $user->password));
        $this->assertFalse(password_verify($user->email, $user->password));
        $this->assertSame(4, (int) $user->device_limit);
        $this->assertSame($rows[0]['uuid'], $user->uuid);
        $this->assertStringContainsString($user->token, $rows[0]['subscribe_url']);
        $this->assertSame('长期有效', $rows[0]['expired_at']);
    }

    public function test_batch_generation_gives_each_account_its_own_random_password(): void
    {
        $rows = $this->postJson('/_tests/admin-user/generate', [
            'email_suffix' => 'example.invalid',
            'generate_count' => 3,
        ])->assertOk()->json('data');

        $this->assertCount(3, $rows);
        $passwords = array_column($rows, 'password');
        $this->assertCount(3, array_unique($passwords));
        foreach ($rows as $row) {
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{12}$/', $row['password']);
            $this->assertStringEndsWith('@example.invalid', $row['email']);
            $user = User::byEmail($row['email'])->firstOrFail();
            $this->assertTrue(password_verify($row['password'], $user->password));
        }
        $this->assertSame(3, User::count());
    }

    public function test_explicit_password_and_prefix_batch_keep_previous_behaviour(): void
    {
        $rows = $this->postJson('/_tests/admin-user/generate', [
            'email_prefix' => 'team',
            'email_suffix' => 'example.invalid',
            'generate_count' => 2,
            'password' => 'shared-secret-1',
        ])->assertOk()->json('data');

        $this->assertSame(['team_1@example.invalid', 'team_2@example.invalid'], array_column($rows, 'email'));
        $this->assertSame(['shared-secret-1', 'shared-secret-1'], array_column($rows, 'password'));
        foreach ($rows as $row) {
            $this->assertTrue(password_verify('shared-secret-1', User::byEmail($row['email'])->firstOrFail()->password));
        }

        // 已存在的账号整批拒绝，不创建任何新用户。
        $this->postJson('/_tests/admin-user/generate', [
            'email_prefix' => 'team',
            'email_suffix' => 'example.invalid',
            'generate_count' => 3,
        ])->assertStatus(400)->assertJsonPath('message', '邮箱 team_1@example.invalid 已存在于系统中');
        $this->assertSame(2, User::count());

        $this->postJson('/_tests/admin-user/generate', [
            'email_prefix' => 'team_1',
            'email_suffix' => 'example.invalid',
        ])->assertStatus(400)->assertJsonPath('message', '邮箱已存在于系统中');

        $this->postJson('/_tests/admin-user/generate', ['email_suffix' => 'example.invalid'])
            ->assertStatus(422)->assertJsonPath('message', '请填写账号或生成数量');
        $this->assertSame(2, User::count());
    }

    public function test_csv_export_contains_the_generated_passwords(): void
    {
        $response = $this->post('/_tests/admin-user/generate', [
            'email_suffix' => 'example.invalid',
            'generate_count' => 2,
            'download_csv' => true,
        ]);
        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));

        $lines = array_values(array_filter(explode("\n", trim($response->streamedContent()))));
        $this->assertCount(3, $lines);
        $this->assertSame('账号,密码,过期时间,UUID,创建时间,订阅地址', trim($lines[0]));
        foreach (array_slice($lines, 1) as $line) {
            [$email, $password] = str_getcsv(trim($line));
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{12}$/', $password);
            $this->assertTrue(password_verify($password, User::byEmail($email)->firstOrFail()->password));
        }
    }
}
