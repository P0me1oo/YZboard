<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class AuthService
{
    private User $user;

    public function __construct(User $user)
    {
        $this->user = $user;
    }

    public function generateAuthData(): array
    {
        // Create a new Sanctum token with device info
        $token = $this->user->createToken(
            Str::random(20), // token name (device identifier)
            ['*'], // abilities
            now()->addYear() // expiration
        );

        // Format token: remove ID prefix and add Bearer
        $tokenParts = explode('|', $token->plainTextToken);
        $formattedToken = 'Bearer ' . ($tokenParts[1] ?? $tokenParts[0]);

        return [
            'token' => $this->user->token,
            'auth_data' => $formattedToken,
            'is_admin' => $this->user->is_admin,
        ];
    }

    public function getSessions(): array
    {
        return $this->user->tokens()->get()->toArray();
    }

    public function removeSession(string $sessionId): bool
    {
        $this->user->tokens()->where('id', $sessionId)->delete();
        return true;
    }

    public function removeAllSessions(): bool
    {
        $this->user->tokens()->delete();
        return true;
    }

    public static function findUserByBearerToken(string $bearerToken): ?User
    {
        $token = str_replace('Bearer ', '', $bearerToken);

        $accessToken = PersonalAccessToken::findToken($token);

        if (!$accessToken || !self::isValidAccessToken($accessToken)) {
            return null;
        }

        $tokenable = $accessToken->tokenable;

        return $tokenable instanceof User ? $tokenable : null;
    }

    /**
     * 校验令牌是否仍在有效期内。
     *
     * PersonalAccessToken::findToken() 只按哈希查记录，不判断过期；
     * 过期判断平时由 sanctum 守卫完成，这条路径绕过了守卫，必须自己补上，
     * 否则已过期的令牌仍能换取快速登录链接和后台接口访问权限。
     */
    private static function isValidAccessToken(PersonalAccessToken $accessToken): bool
    {
        if ($accessToken->expires_at !== null && $accessToken->expires_at->isPast()) {
            return false;
        }

        // sanctum 的全局过期配置（分钟），未设置时为 null
        $expiration = config('sanctum.expiration');
        if ($expiration && $accessToken->created_at?->lte(now()->subMinutes($expiration))) {
            return false;
        }

        return true;
    }

    /**
     * 解密认证数据
     *
     * @param string $authorization
     * @return array|null 用户数据或null
     */
    public static function decryptAuthData(string $authorization): ?array
    {
        $user = self::findUserByBearerToken($authorization);
        
        if (!$user) {
            return null;
        }
        
        return [
            'id' => $user->id,
            'email' => $user->email,
            'is_admin' => (bool)$user->is_admin,
            'is_staff' => (bool)$user->is_staff
        ];
    }
}
