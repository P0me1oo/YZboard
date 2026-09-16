<?php

namespace App\Utils;

/**
 * TOTP（基于时间的一次性密码）实现，遵循 RFC 4648 Base32 与 RFC 6238。
 *
 * 不引入额外依赖，仅使用 PHP 内置的 hash_hmac 与 random_bytes。
 * 默认参数与主流验证器 App（Google Authenticator、1Password 等）一致：
 * SHA1 摘要、6 位数字、30 秒步长。
 */
class Totp
{
    /** Base32 字母表（RFC 4648） */
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** 验证码位数 */
    public const DIGITS = 6;

    /** 时间步长（秒） */
    public const PERIOD = 30;

    /**
     * 生成随机密钥，返回 Base32 字符串。
     *
     * @param int $bytes 原始随机字节数，默认 20 字节（160 位，与 SHA1 输出等长）
     */
    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    /**
     * 按指定时间戳计算验证码。
     *
     * @param string $secret Base32 密钥
     * @param int|null $timestamp 为 null 时使用当前时间
     */
    public static function code(string $secret, ?int $timestamp = null): string
    {
        $key = self::base32Decode($secret);
        if ($key === '') {
            return '';
        }

        $counter = intdiv($timestamp ?? time(), self::PERIOD);
        // 计数器按 8 字节大端序打包
        $binaryCounter = pack('J', $counter);
        $hash = hash_hmac('sha1', $binaryCounter, $key, true);

        // RFC 4226 动态截断：取最后一个字节的低 4 位作为偏移量
        $offset = ord($hash[19]) & 0x0f;
        $value = ((ord($hash[$offset]) & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            | (ord($hash[$offset + 3]) & 0xff);

        return str_pad((string) ($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * 校验验证码，允许前后若干个时间窗口的偏移以容忍设备时钟误差。
     *
     * @param string $secret Base32 密钥
     * @param string $code 用户提交的验证码
     * @param int $window 允许偏移的窗口数，1 表示前后各 30 秒
     */
    public static function verify(string $secret, string $code, int $window = 1): bool
    {
        // 去掉用户可能粘贴进来的空格和连字符
        $code = preg_replace('/[\s-]/', '', $code) ?? '';
        if (!preg_match('/^\d{' . self::DIGITS . '}$/', $code)) {
            return false;
        }

        $now = time();
        for ($i = -$window; $i <= $window; $i++) {
            $expected = self::code($secret, $now + ($i * self::PERIOD));
            // 使用恒定时间比较，避免通过响应时间推断验证码
            if ($expected !== '' && hash_equals($expected, $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 生成验证器 App 可扫描的 otpauth 链接。
     *
     * @param string $secret Base32 密钥
     * @param string $account 账号标识，通常是邮箱
     * @param string $issuer 站点名称
     */
    public static function provisioningUri(string $secret, string $account, string $issuer): string
    {
        // 标签中的冒号有特殊含义，需要先剔除再拼接
        $label = rawurlencode(str_replace(':', '', $issuer)) . ':' . rawurlencode($account);

        return 'otpauth://totp/' . $label . '?' . http_build_query([
            'secret' => $secret,
            'issuer' => str_replace(':', '', $issuer),
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Base32 编码（不补 = 号，验证器 App 均可识别）。
     */
    public static function base32Encode(string $binary): string
    {
        if ($binary === '') {
            return '';
        }

        $bits = '';
        foreach (str_split($binary) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $output = '';
        foreach (str_split($bits, 5) as $chunk) {
            // 末尾不足 5 位时右侧补零
            $output .= self::ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }

        return $output;
    }

    /**
     * Base32 解码，遇到非法字符返回空字符串。
     */
    public static function base32Decode(string $secret): string
    {
        $secret = strtoupper(rtrim(preg_replace('/[\s-]/', '', $secret) ?? '', '='));
        if ($secret === '') {
            return '';
        }

        $bits = '';
        foreach (str_split($secret) as $char) {
            $index = strpos(self::ALPHABET, $char);
            if ($index === false) {
                return '';
            }
            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $output = '';
        foreach (str_split($bits, 8) as $chunk) {
            // 只取完整的 8 位，末尾不足的填充位丢弃
            if (strlen($chunk) === 8) {
                $output .= chr(bindec($chunk));
            }
        }

        return $output;
    }
}
