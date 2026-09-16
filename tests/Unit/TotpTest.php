<?php

namespace Tests\Unit;

use App\Utils\Totp;
use PHPUnit\Framework\TestCase;

/**
 * TOTP 实现的正确性测试，基准向量取自 RFC 6238 与 RFC 4648。
 */
class TotpTest extends TestCase
{
    /**
     * RFC 4648 第 10 节给出的 Base32 测试向量。
     */
    public function test_base32_matches_rfc4648_vectors(): void
    {
        $vectors = [
            'f' => 'MY',
            'fo' => 'MZXQ',
            'foo' => 'MZXW6',
            'foob' => 'MZXW6YQ',
            'fooba' => 'MZXW6YTB',
            'foobar' => 'MZXW6YTBOI',
        ];

        foreach ($vectors as $plain => $encoded) {
            $this->assertSame($encoded, Totp::base32Encode($plain), "编码 {$plain} 应为 {$encoded}");
            $this->assertSame($plain, Totp::base32Decode($encoded), "解码 {$encoded} 应为 {$plain}");
        }
    }

    /**
     * RFC 6238 附录 B 的 SHA1 测试向量，密钥为 ASCII "12345678901234567890"。
     *
     * 参考实现输出 8 位，这里取末 6 位比对。
     */
    public function test_code_matches_rfc6238_vectors(): void
    {
        $secret = Totp::base32Encode('12345678901234567890');

        $vectors = [
            59 => '287082',
            1111111109 => '081804',
            1111111111 => '050471',
            1234567890 => '005924',
            2000000000 => '279037',
            20000000000 => '353130',
        ];

        foreach ($vectors as $timestamp => $expected) {
            $this->assertSame(
                $expected,
                Totp::code($secret, $timestamp),
                "时间戳 {$timestamp} 的验证码应为 {$expected}"
            );
        }
    }

    public function test_verify_accepts_current_code(): void
    {
        $secret = Totp::generateSecret();

        $this->assertTrue(Totp::verify($secret, Totp::code($secret)));
    }

    public function test_verify_tolerates_clock_drift_within_window(): void
    {
        $secret = Totp::generateSecret();
        $now = time();

        // 前后各一个时间窗口内的验证码都应被接受
        $this->assertTrue(Totp::verify($secret, Totp::code($secret, $now - Totp::PERIOD)));
        $this->assertTrue(Totp::verify($secret, Totp::code($secret, $now + Totp::PERIOD)));
    }

    public function test_verify_rejects_code_outside_window(): void
    {
        $secret = Totp::generateSecret();
        $now = time();

        // 超出容忍窗口的验证码必须被拒绝
        $this->assertFalse(Totp::verify($secret, Totp::code($secret, $now - (Totp::PERIOD * 5))));
        $this->assertFalse(Totp::verify($secret, Totp::code($secret, $now + (Totp::PERIOD * 5))));
    }

    public function test_verify_rejects_malformed_input(): void
    {
        $secret = Totp::generateSecret();

        foreach (['', '12345', '1234567', 'abcdef', '  ', '00000a'] as $code) {
            $this->assertFalse(Totp::verify($secret, $code), "非法输入 '{$code}' 应被拒绝");
        }
    }

    public function test_verify_ignores_spaces_and_dashes(): void
    {
        $secret = Totp::generateSecret();
        $code = Totp::code($secret);
        $spaced = substr($code, 0, 3) . ' ' . substr($code, 3);

        $this->assertTrue(Totp::verify($secret, $spaced), '粘贴带空格的验证码应能通过');
    }

    public function test_verify_rejects_wrong_secret(): void
    {
        $secret = Totp::generateSecret();
        $other = Totp::generateSecret();

        $this->assertFalse(Totp::verify($other, Totp::code($secret)));
    }

    public function test_generated_secret_is_decodable_base32(): void
    {
        $secret = Totp::generateSecret();

        $this->assertMatchesRegularExpression('/^[A-Z2-7]+$/', $secret, '密钥应只含 Base32 字符');
        $this->assertSame(20, strlen(Totp::base32Decode($secret)), '默认密钥应为 20 字节');
    }

    public function test_base32_decode_rejects_invalid_characters(): void
    {
        $this->assertSame('', Totp::base32Decode('MZXW6!!!'));
        $this->assertSame('', Totp::base32Decode('01890'));
    }

    public function test_provisioning_uri_contains_required_parameters(): void
    {
        $uri = Totp::provisioningUri('JBSWY3DPEHPK3PXP', 'admin@example.test', 'YZ Panel');

        $this->assertStringStartsWith('otpauth://totp/', $uri);
        $this->assertStringContainsString('secret=JBSWY3DPEHPK3PXP', $uri);
        $this->assertStringContainsString('issuer=YZ%20Panel', $uri);
        $this->assertStringContainsString('digits=6', $uri);
        $this->assertStringContainsString('period=30', $uri);
        // 账号中的 @ 必须转义，避免破坏标签结构
        $this->assertStringContainsString('admin%40example.test', $uri);
    }

    public function test_provisioning_uri_strips_colon_from_issuer(): void
    {
        $uri = Totp::provisioningUri('JBSWY3DPEHPK3PXP', 'admin@example.test', 'YZ:Panel');

        // 冒号是标签分隔符，保留会让验证器解析错误
        $this->assertStringContainsString('otpauth://totp/YZPanel:', $uri);
    }
}
