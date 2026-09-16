<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Utils\CacheKey;
use App\Utils\Totp;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * 管理员两步验证（TOTP）服务。
 *
 * 仅管理员账号可以绑定和校验；普通用户的登录流程不受影响。
 */
class TotpService
{
    /** 恢复码数量 */
    private const RECOVERY_CODE_COUNT = 8;

    /** 待验证的登录挑战有效期（秒） */
    private const CHALLENGE_TTL = 300;

    /** 绑定过程中暂存密钥的有效期（秒） */
    private const PENDING_SECRET_TTL = 600;

    /** 同一挑战允许的最大验证码尝试次数 */
    private const MAX_CHALLENGE_ATTEMPTS = 5;

    /**
     * 判断账号是否已启用两步验证。
     *
     * 只有管理员账号会被校验，普通用户即使存在历史数据也直接跳过。
     */
    public function isEnabled(User $user): bool
    {
        return (bool) $user->is_admin
            && $user->totp_enabled_at !== null
            && !empty($user->totp_secret);
    }

    /**
     * 生成待绑定的密钥，返回密钥、otpauth 链接和二维码。
     *
     * 此时尚未写入数据库，必须通过 confirm() 验证一次动态码后才真正启用。
     */
    public function beginSetup(User $user): array
    {
        $secret = Totp::generateSecret();
        Cache::put($this->pendingSecretKey($user), $secret, self::PENDING_SECRET_TTL);

        $uri = Totp::provisioningUri(
            $secret,
            $user->email,
            (string) admin_setting('app_name', 'XBoard')
        );

        return [
            'secret' => $secret,
            'uri' => $uri,
            'qr_code' => $this->renderQrCode($uri),
        ];
    }

    /**
     * 校验待绑定密钥对应的验证码并正式启用，返回一次性恢复码明文。
     *
     * @return array{0: bool, 1: array|string} 成功时返回恢复码数组，失败时返回错误信息
     */
    public function confirmSetup(User $user, string $code): array
    {
        if ($this->isEnabled($user)) {
            return [false, __('Two-factor authentication is already enabled')];
        }

        $secret = Cache::get($this->pendingSecretKey($user));
        if (!$secret) {
            return [false, __('The setup session has expired, please restart the binding')];
        }

        if (!Totp::verify($secret, $code)) {
            return [false, __('Incorrect verification code')];
        }

        $recoveryCodes = $this->generateRecoveryCodes();

        $user->totp_secret = $secret;
        $user->totp_enabled_at = time();
        // 只存哈希，明文仅在本次响应中返回一次
        $user->totp_recovery_codes = array_map(
            static fn(string $recoveryCode): string => password_hash($recoveryCode, PASSWORD_DEFAULT),
            $recoveryCodes
        );

        if (!$user->save()) {
            return [false, __('Save failed')];
        }

        Cache::forget($this->pendingSecretKey($user));

        return [true, $recoveryCodes];
    }

    /**
     * 关闭两步验证，需要提供当前验证码或恢复码。
     *
     * @return array{0: bool, 1: bool|string}
     */
    public function disable(User $user, string $code): array
    {
        if (!$this->isEnabled($user)) {
            return [false, __('Two-factor authentication is not enabled')];
        }

        if (!$this->verifyCode($user, $code)) {
            return [false, __('Incorrect verification code')];
        }

        $user->totp_secret = null;
        $user->totp_enabled_at = null;
        $user->totp_recovery_codes = null;

        if (!$user->save()) {
            return [false, __('Save failed')];
        }

        return [true, true];
    }

    /**
     * 校验动态验证码；动态码不匹配时回退校验恢复码。
     *
     * 恢复码一经使用立即作废。
     */
    public function verifyCode(User $user, string $code): bool
    {
        if (empty($user->totp_secret)) {
            return false;
        }

        if (Totp::verify((string) $user->totp_secret, $code)) {
            return true;
        }

        return $this->consumeRecoveryCode($user, $code);
    }

    /**
     * 创建登录挑战：密码已验证通过，等待第二步验证码。
     *
     * 返回的标识只是一个随机串，不包含用户信息。
     */
    public function createChallenge(User $user): string
    {
        $challengeId = Str::random(40);
        Cache::put(
            $this->challengeKey($challengeId),
            ['user_id' => $user->id, 'attempts' => 0],
            self::CHALLENGE_TTL
        );

        return $challengeId;
    }

    /**
     * 校验登录挑战，成功后作废该挑战并返回用户。
     *
     * @return array{0: bool, 1: User|array} 失败时返回 [状态码, 错误信息]
     */
    public function resolveChallenge(string $challengeId, string $code): array
    {
        $key = $this->challengeKey($challengeId);
        $payload = Cache::get($key);

        if (!is_array($payload) || empty($payload['user_id'])) {
            return [false, [400, __('The verification session has expired, please sign in again')]];
        }

        $attempts = (int) ($payload['attempts'] ?? 0);
        if ($attempts >= self::MAX_CHALLENGE_ATTEMPTS) {
            Cache::forget($key);
            return [false, [429, __('Too many incorrect codes, please sign in again')]];
        }

        $user = User::find($payload['user_id']);
        if (!$user || $user->banned || !$this->isEnabled($user)) {
            Cache::forget($key);
            return [false, [400, __('The verification session has expired, please sign in again')]];
        }

        if (!$this->verifyCode($user, $code)) {
            // 失败计数写回时保留剩余有效期，避免重置挑战寿命
            $payload['attempts'] = $attempts + 1;
            Cache::put($key, $payload, self::CHALLENGE_TTL);
            return [false, [400, __('Incorrect verification code')]];
        }

        Cache::forget($key);

        return [true, $user];
    }

    /**
     * 重新生成恢复码，返回明文；需要先通过一次验证码校验。
     *
     * @return array{0: bool, 1: array|string}
     */
    public function regenerateRecoveryCodes(User $user, string $code): array
    {
        if (!$this->isEnabled($user)) {
            return [false, __('Two-factor authentication is not enabled')];
        }

        if (!Totp::verify((string) $user->totp_secret, $code)) {
            return [false, __('Incorrect verification code')];
        }

        $recoveryCodes = $this->generateRecoveryCodes();
        $user->totp_recovery_codes = array_map(
            static fn(string $recoveryCode): string => password_hash($recoveryCode, PASSWORD_DEFAULT),
            $recoveryCodes
        );

        if (!$user->save()) {
            return [false, __('Save failed')];
        }

        return [true, $recoveryCodes];
    }

    /**
     * 剩余未使用的恢复码数量。
     */
    public function remainingRecoveryCodeCount(User $user): int
    {
        return count($user->totp_recovery_codes ?? []);
    }

    /**
     * 比对并消费恢复码。
     */
    private function consumeRecoveryCode(User $user, string $code): bool
    {
        $stored = $user->totp_recovery_codes ?? [];
        if (empty($stored)) {
            return false;
        }

        $normalized = strtoupper(trim($code));
        foreach ($stored as $index => $hash) {
            if (password_verify($normalized, $hash)) {
                unset($stored[$index]);
                $user->totp_recovery_codes = array_values($stored);
                $user->save();
                return true;
            }
        }

        return false;
    }

    /**
     * 生成一组恢复码，格式为 XXXXX-XXXXX。
     */
    private function generateRecoveryCodes(): array
    {
        $codes = [];
        for ($i = 0; $i < self::RECOVERY_CODE_COUNT; $i++) {
            $codes[] = strtoupper(Str::random(5) . '-' . Str::random(5));
        }

        return $codes;
    }

    /**
     * 把 otpauth 链接渲染成内嵌的 SVG 二维码。
     */
    private function renderQrCode(string $uri): string
    {
        $writer = new Writer(new ImageRenderer(
            new RendererStyle(240, 1),
            new SvgImageBackEnd()
        ));

        return 'data:image/svg+xml;base64,' . base64_encode($writer->writeString($uri));
    }

    private function pendingSecretKey(User $user): string
    {
        return CacheKey::get('TOTP_PENDING_SECRET', $user->id);
    }

    private function challengeKey(string $challengeId): string
    {
        return CacheKey::get('TOTP_LOGIN_CHALLENGE', $challengeId);
    }
}
