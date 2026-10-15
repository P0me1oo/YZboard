<?php

namespace App\Services;

use App\Models\User;
use App\Support\SubscriptionProtectionIp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class SubscriptionProtection
{
    public const SETTING = 'subscribe_protection';

    public static function defaults(): array
    {
        return [
            'block_browser' => false,
            'brute_force_enabled' => false,
            'brute_force_max_failures' => 10,
            'brute_force_window_minutes' => 10,
            'brute_force_block_minutes' => 30,
            'rate_limit_enabled' => false,
            'rate_limit_max_requests' => 60,
            'rate_limit_window_minutes' => 1,
            'whitelist_ips' => [],
            'whitelist_user_ids' => [],
        ];
    }

    public static function settings(): array
    {
        return array_replace(self::defaults(), app(\App\Support\Setting::class)->getOrFail(self::SETTING, []));
    }

    public static function normalize(array $settings): array
    {
        foreach (self::defaults() as $key => $default) {
            if (is_bool($default)) {
                $settings[$key] = (bool) $settings[$key];
            } elseif (is_int($default)) {
                $settings[$key] = (int) $settings[$key];
            }
        }
        $settings['whitelist_ips'] = array_values(array_unique(array_map(SubscriptionProtectionIp::normalize(...), $settings['whitelist_ips'])));
        $settings['whitelist_user_ids'] = array_values(array_unique(array_map('intval', $settings['whitelist_user_ids'])));
        return $settings;
    }

    /** 订阅和客户端配置下载共用防护；返回用户后仍由控制器检查账号资格。 */
    public function authenticate(Request $request): User|Response
    {
        try {
            $settings = self::settings();
        } catch (\Throwable) {
            return $this->unavailable();
        }
        $rawToken = $request->input('token', $request->route('token'));
        $token = is_string($rawToken) && strlen($rawToken) <= 255 && $rawToken !== '' ? $rawToken : null;
        $lookup = fn () => $token === null ? null : User::where('token', $token)->first();
        $ip = SubscriptionProtectionIp::normalize($request->ip() ?? '') ?? 'unknown';

        if (SubscriptionProtectionIp::matches($ip, $settings['whitelist_ips'])) {
            return $lookup() ?? $this->invalid();
        }

        // 用户白名单只能由有效订阅凭据命中，即使同一 IP 已被限制也可使用。
        $resolved = false;
        $user = null;
        if ($settings['whitelist_user_ids'] !== []) {
            $user = $lookup();
            $resolved = true;
            if ($user && in_array($user->id, $settings['whitelist_user_ids'], true)) {
                return $user;
            }
        }

        $browser = $settings['block_browser'] && $this->isBrowser($request);
        if (!$settings['brute_force_enabled'] && !$settings['rate_limit_enabled']) {
            $user = $resolved ? $user : $lookup();
            return $user ? ($browser ? $this->browserDenied() : $user) : $this->invalid();
        }

        // 共享 Redis 保存有过期时间的计数；锁覆盖读取、判定和写入，防止并发穿透。
        $key = 'subscription-protection:' . hash('sha256', $ip);
        try {
            $cache = Cache::store('redis');
            return $cache->lock($key . ':lock', 10)->block(2, function () use ($cache, $key, $settings, $resolved, $user, $lookup, $browser) {
                $now = now()->getTimestamp();
                $state = $cache->get($key, []);
                $bruteRule = implode(':', [$settings['brute_force_max_failures'], $settings['brute_force_window_minutes'], $settings['brute_force_block_minutes']]);
                $rateRule = implode(':', [$settings['rate_limit_max_requests'], $settings['rate_limit_window_minutes']]);
                $brute = ($state['brute']['rule'] ?? null) === $bruteRule ? $state['brute'] : [];
                $rate = ($state['rate']['rule'] ?? null) === $rateRule ? $state['rate'] : [];

                if ($settings['brute_force_enabled'] && ($brute['blocked_until'] ?? 0) > $now) {
                    return $this->limited($brute['blocked_until'] - $now, true);
                }
                if (($brute['blocked_until'] ?? 0) > 0
                    ? $brute['blocked_until'] <= $now
                    : ($brute['expires'] ?? 0) <= $now) {
                    $brute = ['rule' => $bruteRule, 'count' => 0, 'expires' => 0];
                }
                if (($rate['expires'] ?? 0) <= $now) {
                    $rate = ['rule' => $rateRule, 'count' => 0, 'expires' => $now + $settings['rate_limit_window_minutes'] * 60];
                }
                if ($settings['rate_limit_enabled']) {
                    if ($rate['count'] >= $settings['rate_limit_max_requests']) {
                        return $this->limited($rate['expires'] - $now);
                    }
                    $rate['count']++;
                }

                $user = $resolved ? $user : $lookup();
                if (!$user && $settings['brute_force_enabled']) {
                    if ($brute['count'] === 0) {
                        $brute['expires'] = $now + $settings['brute_force_window_minutes'] * 60;
                    }
                    $brute['count']++;
                    if ($brute['count'] >= $settings['brute_force_max_failures']) {
                        $brute['blocked_until'] = $now + $settings['brute_force_block_minutes'] * 60;
                    }
                }

                // 成功下载不清除失败次数，防止交替使用有效链接与随机链接避开封禁。
                $state = ['brute' => $brute, 'rate' => $rate];
                $expires = max($brute['expires'] ?? 0, $brute['blocked_until'] ?? 0, $rate['expires']);
                $cache->put($key, $state, max(1, $expires - $now));
                if (($brute['blocked_until'] ?? 0) > $now && $settings['brute_force_enabled']) {
                    return $this->limited($brute['blocked_until'] - $now, true);
                }
                return $user ? ($browser ? $this->browserDenied() : $user) : $this->invalid();
            });
        } catch (\Exception) {
            // 缓存不可用或同来源请求争用锁时拒绝本次下载，不静默关闭防护。
            return $this->unavailable();
        }
    }

    private function isBrowser(Request $request): bool
    {
        return strtolower($request->header('Sec-Fetch-Mode', '')) === 'navigate'
            || in_array(strtolower($request->header('Sec-Fetch-Dest', '')), ['document', 'iframe', 'frame'], true)
            || preg_match('/mozilla\/|chrome\/|chromium\/|safari\/|firefox\/|msie |trident\/|edg\/|opera\/|opr\//i', $request->userAgent() ?? '') === 1;
    }

    private function invalid(): Response
    {
        return $this->deny('订阅链接无效', 403);
    }

    private function unavailable(): Response
    {
        return $this->deny('订阅服务暂时繁忙，请稍后重试', 503, 2);
    }

    private function browserDenied(): Response
    {
        return $this->deny('请使用代理客户端获取订阅', 403);
    }

    private function limited(int $seconds, bool $brute = false): Response
    {
        return $this->deny($brute ? '订阅访问暂时受限，请稍后重试' : '订阅请求过于频繁，请稍后重试', 429, max(1, $seconds));
    }

    private function deny(string $message, int $status, ?int $retry = null): Response
    {
        $headers = ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'private, no-store'];
        if ($retry !== null) {
            $headers['Retry-After'] = (string) $retry;
        }
        return response($message, $status, $headers);
    }
}
