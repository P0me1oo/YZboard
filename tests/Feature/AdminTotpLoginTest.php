<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\TotpService;
use App\Utils\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 管理员两步验证登录流程测试。
 *
 * 重点覆盖两件事：启用后不能只凭密码拿到令牌，
 * 以及绕过密码的登录路径同样受到拦截。
 */
class AdminTotpLoginTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(array $attributes = []): User
    {
        return User::create(array_merge([
            'email' => 'totp-admin@example.test',
            'password' => password_hash('test-password-1', PASSWORD_DEFAULT),
            'uuid' => \App\Utils\Helper::guid(true),
            'token' => \App\Utils\Helper::guid(false),
            'is_admin' => 1,
        ], $attributes));
    }

    /**
     * 给账号绑定两步验证，返回密钥。
     */
    private function enableTotp(User $user): string
    {
        $secret = Totp::generateSecret();
        $user->totp_secret = $secret;
        $user->totp_enabled_at = time();
        $user->totp_recovery_codes = [password_hash('AAAAA-BBBBB', PASSWORD_DEFAULT)];
        $user->save();

        return $secret;
    }

    public function test_admin_without_totp_logs_in_directly(): void
    {
        $this->makeUser();

        $this->postJson('/api/v1/passport/auth/login', [
            'email' => 'totp-admin@example.test',
            'password' => 'test-password-1',
        ])
            ->assertOk()
            ->assertJsonPath('data.is_admin', true)
            ->assertJsonMissingPath('data.totp_required');
    }

    public function test_password_alone_does_not_issue_token_when_totp_enabled(): void
    {
        $user = $this->makeUser();
        $this->enableTotp($user);

        $response = $this->postJson('/api/v1/passport/auth/login', [
            'email' => 'totp-admin@example.test',
            'password' => 'test-password-1',
        ])
            ->assertOk()
            ->assertJsonPath('data.totp_required', true);

        // 关键不变量：第一步绝不能返回任何可用于访问接口的凭据
        $this->assertNull($response->json('data.auth_data'));
        $this->assertNull($response->json('data.token'));
        $this->assertNotEmpty($response->json('data.challenge_id'));
    }

    public function test_correct_code_completes_login(): void
    {
        $user = $this->makeUser();
        $secret = $this->enableTotp($user);

        $challengeId = $this->postJson('/api/v1/passport/auth/login', [
            'email' => 'totp-admin@example.test',
            'password' => 'test-password-1',
        ])->json('data.challenge_id');

        $this->postJson('/api/v1/passport/auth/loginWithTotp', [
            'challenge_id' => $challengeId,
            'code' => Totp::code($secret),
        ])
            ->assertOk()
            ->assertJsonPath('data.is_admin', true)
            ->assertJsonStructure(['data' => ['auth_data', 'token']]);
    }

    public function test_wrong_code_is_rejected(): void
    {
        $user = $this->makeUser();
        $secret = $this->enableTotp($user);

        $challengeId = $this->postJson('/api/v1/passport/auth/login', [
            'email' => 'totp-admin@example.test',
            'password' => 'test-password-1',
        ])->json('data.challenge_id');

        // 构造一个与真实验证码不同的 6 位数字
        $wrong = str_pad((string) ((((int) Totp::code($secret)) + 1) % 1000000), 6, '0', STR_PAD_LEFT);

        $response = $this->postJson('/api/v1/passport/auth/loginWithTotp', [
            'challenge_id' => $challengeId,
            'code' => $wrong,
        ])->assertStatus(400);

        $this->assertNull($response->json('data.auth_data'));
    }

    public function test_challenge_is_single_use(): void
    {
        $user = $this->makeUser();
        $secret = $this->enableTotp($user);

        $challengeId = $this->postJson('/api/v1/passport/auth/login', [
            'email' => 'totp-admin@example.test',
            'password' => 'test-password-1',
        ])->json('data.challenge_id');

        $code = Totp::code($secret);

        $this->postJson('/api/v1/passport/auth/loginWithTotp', [
            'challenge_id' => $challengeId,
            'code' => $code,
        ])->assertOk();

        // 同一个挑战标识重放必须失败
        $this->postJson('/api/v1/passport/auth/loginWithTotp', [
            'challenge_id' => $challengeId,
            'code' => $code,
        ])->assertStatus(400);
    }

    public function test_unknown_challenge_is_rejected(): void
    {
        $this->postJson('/api/v1/passport/auth/loginWithTotp', [
            'challenge_id' => str_repeat('a', 40),
            'code' => '123456',
        ])->assertStatus(400);
    }

    public function test_challenge_locks_after_repeated_failures(): void
    {
        $user = $this->makeUser();
        $secret = $this->enableTotp($user);

        $challengeId = $this->postJson('/api/v1/passport/auth/login', [
            'email' => 'totp-admin@example.test',
            'password' => 'test-password-1',
        ])->json('data.challenge_id');

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/passport/auth/loginWithTotp', [
                'challenge_id' => $challengeId,
                'code' => '000000',
            ])->assertStatus(400);
        }

        // 超过尝试上限后，即使给出正确验证码也必须重新登录
        $this->postJson('/api/v1/passport/auth/loginWithTotp', [
            'challenge_id' => $challengeId,
            'code' => Totp::code($secret),
        ])->assertStatus(429);
    }

    public function test_recovery_code_works_once(): void
    {
        $user = $this->makeUser();
        $this->enableTotp($user);

        $challengeId = $this->postJson('/api/v1/passport/auth/login', [
            'email' => 'totp-admin@example.test',
            'password' => 'test-password-1',
        ])->json('data.challenge_id');

        $this->postJson('/api/v1/passport/auth/loginWithTotp', [
            'challenge_id' => $challengeId,
            'code' => 'AAAAA-BBBBB',
        ])->assertOk();

        // 恢复码用过即作废
        $secondChallenge = $this->postJson('/api/v1/passport/auth/login', [
            'email' => 'totp-admin@example.test',
            'password' => 'test-password-1',
        ])->json('data.challenge_id');

        $this->postJson('/api/v1/passport/auth/loginWithTotp', [
            'challenge_id' => $secondChallenge,
            'code' => 'AAAAA-BBBBB',
        ])->assertStatus(400);
    }

    public function test_mail_link_login_also_requires_totp(): void
    {
        $user = $this->makeUser();
        $this->enableTotp($user);

        // 邮件链接登录绕过密码，必须同样拦截
        $code = \App\Utils\Helper::guid();
        \Illuminate\Support\Facades\Cache::put(
            \App\Utils\CacheKey::get('TEMP_TOKEN', $code),
            $user->id,
            300
        );

        $response = $this->getJson('/api/v1/passport/auth/token2Login?verify=' . $code)
            ->assertOk()
            ->assertJsonPath('data.totp_required', true);

        $this->assertNull($response->json('data.auth_data'));
    }

    public function test_normal_user_is_not_affected(): void
    {
        // 普通用户即使残留 TOTP 字段也不应被拦截
        $user = $this->makeUser([
            'email' => 'plain-user@example.test',
            'is_admin' => 0,
        ]);
        $this->enableTotp($user);

        $this->postJson('/api/v1/passport/auth/login', [
            'email' => 'plain-user@example.test',
            'password' => 'test-password-1',
        ])
            ->assertOk()
            ->assertJsonMissingPath('data.totp_required')
            ->assertJsonStructure(['data' => ['auth_data']]);
    }

    public function test_banned_admin_cannot_complete_challenge(): void
    {
        $user = $this->makeUser();
        $secret = $this->enableTotp($user);

        $challengeId = $this->postJson('/api/v1/passport/auth/login', [
            'email' => 'totp-admin@example.test',
            'password' => 'test-password-1',
        ])->json('data.challenge_id');

        // 第一步之后被封禁，第二步不得放行
        $user->banned = true;
        $user->save();

        $this->postJson('/api/v1/passport/auth/loginWithTotp', [
            'challenge_id' => $challengeId,
            'code' => Totp::code($secret),
        ])->assertStatus(400);
    }

    public function test_secret_is_never_exposed_in_serialization(): void
    {
        $user = $this->makeUser();
        $this->enableTotp($user);

        $serialized = $user->fresh()->toArray();

        $this->assertArrayNotHasKey('totp_secret', $serialized);
        $this->assertArrayNotHasKey('totp_recovery_codes', $serialized);
    }

    public function test_setup_requires_confirmation_before_taking_effect(): void
    {
        $user = $this->makeUser();
        $service = app(TotpService::class);

        $setup = $service->beginSetup($user);
        $this->assertNotEmpty($setup['secret']);
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $setup['qr_code']);

        // 未确认前不算启用
        $this->assertFalse($service->isEnabled($user->fresh()));

        [$ok, $codes] = $service->confirmSetup($user, Totp::code($setup['secret']));

        $this->assertTrue($ok);
        $this->assertCount(8, $codes);
        $this->assertTrue($service->isEnabled($user->fresh()));
    }

    public function test_setup_confirmation_rejects_wrong_code(): void
    {
        $user = $this->makeUser();
        $service = app(TotpService::class);

        $service->beginSetup($user);
        [$ok] = $service->confirmSetup($user, '000000');

        $this->assertFalse($ok);
        $this->assertFalse($service->isEnabled($user->fresh()));
    }

    public function test_disable_requires_valid_code(): void
    {
        $user = $this->makeUser();
        $secret = $this->enableTotp($user);
        $service = app(TotpService::class);

        [$failed] = $service->disable($user, '000000');
        $this->assertFalse($failed);
        $this->assertTrue($service->isEnabled($user->fresh()));

        [$ok] = $service->disable($user, Totp::code($secret));
        $this->assertTrue($ok);
        $this->assertFalse($service->isEnabled($user->fresh()));
    }

    public function test_reset_totp_command_clears_binding(): void
    {
        $user = $this->makeUser();
        $this->enableTotp($user);

        $this->artisan('reset:totp', ['email' => 'totp-admin@example.test'])
            ->assertSuccessful();

        $this->assertFalse(app(TotpService::class)->isEnabled($user->fresh()));
    }

    /**
     * 管理端两步验证接口挂在后台安全路径下。
     */
    private function adminPath(): string
    {
        return '/api/v2/' . admin_setting(
            'secure_path',
            admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))
        );
    }

    public function test_totp_admin_routes_require_admin(): void
    {
        $user = $this->makeUser([
            'email' => 'plain-user2@example.test',
            'is_admin' => 0,
        ]);

        // 普通用户不得访问管理端两步验证接口
        $this->actingAs($user, 'sanctum')
            ->getJson($this->adminPath() . '/totp/status')
            ->assertStatus(403);

        // 未登录同样拒绝
        $this->getJson($this->adminPath() . '/totp/status')
            ->assertStatus(403);
    }

    public function test_totp_admin_routes_are_reachable_for_admin(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user, 'sanctum')
            ->getJson($this->adminPath() . '/totp/status')
            ->assertOk()
            ->assertJsonPath('data.enabled', false);

        $setup = $this->actingAs($user, 'sanctum')
            ->postJson($this->adminPath() . '/totp/setup')
            ->assertOk()
            ->json('data');

        $this->assertNotEmpty($setup['secret']);

        // 错误验证码不得启用
        $this->actingAs($user, 'sanctum')
            ->postJson($this->adminPath() . '/totp/confirm', ['code' => '000000'])
            ->assertStatus(400);

        $this->actingAs($user, 'sanctum')
            ->postJson($this->adminPath() . '/totp/confirm', [
                'code' => Totp::code($setup['secret']),
            ])
            ->assertOk()
            ->assertJsonCount(8, 'data.recovery_codes');

        $this->actingAs($user, 'sanctum')
            ->getJson($this->adminPath() . '/totp/status')
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.recovery_codes_remaining', 8);
    }
}
