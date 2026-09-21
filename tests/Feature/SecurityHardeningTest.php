<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Models\User;
use App\Services\Auth\TotpService;
use App\Services\AuthService;
use App\Utils\Helper;
use App\Utils\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 认证与接口加固的回归测试。
 *
 * 这些行为很容易在后续重构里被改回去，所以逐条锁定：
 * 限流是否生效、限流是否按来源隔离、过期令牌是否被拒、
 * 改密码是否吊销会话、两步验证挑战是否会被失败尝试续命。
 */
class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(array $attributes = []): User
    {
        return User::create(array_merge([
            'email' => 'hardening@example.test',
            'password' => password_hash('test-password-1', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(false),
        ], $attributes));
    }

    /**
     * 用指定来源 IP 发起一次登录请求。
     *
     * 测试请求来自 127.0.0.1，属于 TrustProxies 的可信网段，
     * 所以 X-Forwarded-For 会被解析成真实客户端 IP。
     */
    private function loginFrom(string $ip, string $email, string $password = 'wrong-password')
    {
        return $this->withHeader('X-Forwarded-For', $ip)
            ->postJson('/api/v1/passport/auth/login', [
                'email' => $email,
                'password' => $password,
            ]);
    }

    public function test_login_endpoint_rejects_after_five_attempts(): void
    {
        // 关掉服务层的密码错误计数，单独验证限流中间件本身
        admin_setting(['password_limit_enable' => 0]);
        $this->makeUser();

        for ($i = 0; $i < 5; $i++) {
            $this->loginFrom('203.0.113.10', 'hardening@example.test')
                ->assertStatus(400);
        }

        $this->loginFrom('203.0.113.10', 'hardening@example.test')
            ->assertStatus(429);
    }

    public function test_login_rate_limit_is_scoped_per_source_ip(): void
    {
        admin_setting(['password_limit_enable' => 0]);
        $this->makeUser();

        for ($i = 0; $i < 5; $i++) {
            $this->loginFrom('203.0.113.10', 'hardening@example.test');
        }

        $this->loginFrom('203.0.113.10', 'hardening@example.test')->assertStatus(429);

        // 换一个来源 IP 不应该被前一个来源的失败次数牵连
        $this->loginFrom('203.0.113.99', 'hardening@example.test')->assertStatus(400);
    }

    public function test_login_rate_limit_is_scoped_per_email(): void
    {
        admin_setting(['password_limit_enable' => 0]);
        $this->makeUser();
        $this->makeUser(['email' => 'other@example.test', 'uuid' => Helper::guid(true), 'token' => Helper::guid(false)]);

        for ($i = 0; $i < 5; $i++) {
            $this->loginFrom('203.0.113.10', 'hardening@example.test');
        }

        $this->loginFrom('203.0.113.10', 'hardening@example.test')->assertStatus(429);
        $this->loginFrom('203.0.113.10', 'other@example.test')->assertStatus(400);
    }

    public function test_password_error_counter_does_not_lock_account_for_other_sources(): void
    {
        admin_setting(['password_limit_enable' => 1, 'password_limit_count' => 3]);
        $this->makeUser();

        // 攻击者从自己的 IP 把计数刷满
        for ($i = 0; $i < 3; $i++) {
            $this->loginFrom('198.51.100.7', 'hardening@example.test');
        }
        $this->loginFrom('198.51.100.7', 'hardening@example.test')->assertStatus(429);

        // 账号本人从另一个 IP 仍然能正常登录，说明不再存在锁死他人账号的问题
        $this->loginFrom('203.0.113.55', 'hardening@example.test', 'test-password-1')
            ->assertOk()
            ->assertJsonPath('data.is_admin', false);
    }

    public function test_expired_bearer_token_is_rejected(): void
    {
        $user = $this->makeUser();
        $plainToken = explode('|', $user->createToken('test', ['*'], now()->addYear())->plainTextToken)[1];

        $this->assertInstanceOf(
            User::class,
            AuthService::findUserByBearerToken('Bearer ' . $plainToken),
            '有效期内的令牌应当能解析出用户'
        );

        $user->tokens()->update(['expires_at' => now()->subMinute()]);

        $this->assertNull(
            AuthService::findUserByBearerToken('Bearer ' . $plainToken),
            '过期令牌不得再解析出用户'
        );
    }

    public function test_reset_password_revokes_existing_sessions(): void
    {
        $user = $this->makeUser();
        $user->createToken('device-a', ['*'], now()->addYear());
        $user->createToken('device-b', ['*'], now()->addYear());
        $this->assertSame(2, $user->tokens()->count());

        \Illuminate\Support\Facades\Cache::put(
            \App\Utils\CacheKey::get('EMAIL_VERIFY_CODE', 'hardening@example.test'),
            '123456',
            300
        );

        [$success] = app(\App\Services\Auth\LoginService::class)->resetPassword(
            'hardening@example.test',
            '123456',
            'brand-new-password-2'
        );

        $this->assertTrue($success);
        $this->assertSame(0, $user->tokens()->count(), '改密码后旧会话必须全部失效');
    }

    public function test_failed_totp_attempt_does_not_extend_challenge_lifetime(): void
    {
        $user = $this->makeUser(['is_admin' => 1]);
        $secret = Totp::generateSecret();
        $user->totp_secret = $secret;
        $user->totp_enabled_at = time();
        $user->save();

        $service = app(TotpService::class);
        $challengeId = $service->createChallenge($user);

        // 挑战寿命 300 秒，先走掉 240 秒
        $this->travel(240)->seconds();

        [$failed] = $service->resolveChallenge($challengeId, '000000');
        $this->assertFalse($failed);

        // 再走 90 秒，总共 330 秒，已经超过原始有效期
        $this->travel(90)->seconds();

        [$success, $result] = $service->resolveChallenge($challengeId, Totp::code($secret));

        $this->assertFalse($success, '失败尝试不得把挑战续命');
        $this->assertSame(400, $result[0]);
    }

    public function test_security_headers_are_present(): void
    {
        $response = $this->getJson('/api/v1/guest/comm/config');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    public function test_trusted_proxies_env_extends_builtin_list(): void
    {
        $middleware = new \App\Http\Middleware\TrustProxies();
        $method = new \ReflectionMethod($middleware, 'proxies');

        $builtin = $method->invoke($middleware);
        $this->assertContains('127.0.0.0/8', $builtin);
        $this->assertNotContains('203.0.113.0/24', $builtin);

        putenv('TRUSTED_PROXIES=203.0.113.0/24, 2001:db8::/32, *');
        try {
            $extended = $method->invoke(new \App\Http\Middleware\TrustProxies());
        } finally {
            putenv('TRUSTED_PROXIES');
        }

        $this->assertContains('127.0.0.0/8', $extended, '内置列表必须保留');
        $this->assertContains('203.0.113.0/24', $extended);
        $this->assertContains('2001:db8::/32', $extended);
        $this->assertNotContains('*', $extended, '不允许信任任意来源');
    }

    public function test_server_key_length_follows_cipher(): void
    {
        $server128 = new Server([
            'type' => Server::TYPE_SHADOWSOCKS,
            'protocol_settings' => ['cipher' => '2022-blake3-aes-128-gcm'],
        ]);
        $server128->created_at = 1700000000;

        $server256 = new Server([
            'type' => Server::TYPE_SHADOWSOCKS,
            'protocol_settings' => ['cipher' => '2022-blake3-aes-256-gcm'],
        ]);
        $server256->created_at = 1700000000;

        $this->assertSame(16, strlen(base64_decode($server128->server_key)));
        $this->assertSame(32, strlen(base64_decode($server256->server_key)));

        $legacy = new Server([
            'type' => Server::TYPE_SHADOWSOCKS,
            'protocol_settings' => ['cipher' => 'aes-128-gcm'],
        ]);
        $legacy->created_at = 1700000000;

        $this->assertNull($legacy->server_key, '非 2022 系列套件不使用服务端密钥');
    }

    public function test_generated_codes_use_secure_randomness(): void
    {
        $codes = [];
        for ($i = 0; $i < 50; $i++) {
            $codes[] = Helper::randomChar(16);
        }

        $this->assertCount(50, array_unique($codes), '随机串不得重复');
    }
}
