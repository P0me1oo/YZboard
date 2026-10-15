<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\SubscriptionRestriction;
use App\Models\TelegramAdminNotification;
use App\Models\TelegramBotConfig;
use App\Models\User;
use App\Support\Setting;
use App\Support\SubscriptionProtectionIp;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SubscriptionRestrictionService
{
    public const SETTINGS_LOCK = 'subscription-protection:settings-lock';

    public function saveSettings(array $settings): void
    {
        $cache = Cache::store('redis');
        $cache->lock(self::SETTINGS_LOCK, 10)->block(2, function () use ($cache, $settings) {
            try {
                DB::transaction(function () use ($settings) {
                    TelegramBotConfig::whereKey(1)->lockForUpdate()->first();
                    // 持锁后读取数据库，两个设置页面互不覆盖未管理的字段。
                    $current = \App\Models\Setting::where('name', SubscriptionProtection::SETTING)->first()?->value ?? [];
                    $settings = array_replace(SubscriptionProtection::defaults(), $current, $settings);
                    admin_setting([SubscriptionProtection::SETTING => $settings]);
                    if (!$settings['notify_telegram']) {
                        TelegramAdminNotification::whereNotNull('subject')->where('status', 'pending')
                            ->update(['status' => 'cancelled', 'details' => null]);
                    }
                });
            } finally {
                // 提交或回滚后再次失效，避免事务期间的旧值留在共享缓存中。
                $cache->forget(Setting::CACHE_KEY);
                app()->forgetInstance(Setting::class);
            }
        });
    }

    public function prune(): void
    {
        SubscriptionRestriction::where('expires_at', '<=', now()->timestamp)->delete();
        // 冷却最长七天。IP 来源数量不受用户表约束，结束的通知不能无限积累。
        TelegramAdminNotification::whereNotNull('subject')->where('status', '!=', 'pending')
            ->where('updated_at', '<', now()->timestamp - 7 * 86400)->delete();
    }

    public static function key(string $kind, string $target): string
    {
        return 'subscription-protection:' . ($kind === 'user_rate' ? 'user:' : '') . hash('sha256', $target);
    }

    public static function field(string $kind): string
    {
        return $kind === 'ip_ban' ? 'brute' : 'rate';
    }

    public static function rule(string $kind, array $settings): string
    {
        return match ($kind) {
            'ip_ban' => implode(':', [$settings['brute_force_max_failures'], $settings['brute_force_window_minutes'], $settings['brute_force_block_minutes']]),
            'ip_rate' => implode(':', [$settings['rate_limit_max_requests'], $settings['rate_limit_window_minutes']]),
            'user_rate' => implode(':', [$settings['user_rate_limit_max_requests'], $settings['user_rate_limit_window_minutes']]),
        };
    }

    /** 仅新触发时写入索引；缓存中的事件编号用于防止旧页面解除下一次限制。 */
    public function record(string $kind, string $target, array &$state, bool $notify = true): array
    {
        $state['event_id'] = (string) Str::ulid();
        $record = SubscriptionRestriction::updateOrCreate(['kind' => $kind, 'target' => $target], [
            'event_id' => $state['event_id'], 'rule' => $state['rule'], 'count' => $state['count'],
            'triggered_at' => now()->timestamp, 'expires_at' => $state['blocked_until'] ?? $state['expires'],
        ]);
        if ($notify) {
            try {
                if (SubscriptionProtection::settings()['notify_telegram']) {
                    app(\App\Services\TelegramBot\AdminNotificationService::class)->recordSubscription($record);
                }
            } catch (\Throwable) {
                // 通知登记失败也不能改变订阅响应；不记录含请求信息的异常。
            }
        }
        return $record->toArray();
    }

    public function listing(int $after = 0): array
    {
        $settings = SubscriptionProtection::settings();
        $rows = SubscriptionRestriction::where('id', '>', $after)->where('expires_at', '>', now()->timestamp)
            ->orderBy('id')->limit(101)->get();
        $more = $rows->count() > 100;
        $page = $rows->take(100);
        $active = $page->filter(fn ($row) => $this->active($row, $settings))->values();
        $users = User::whereIn('id', $active->where('kind', 'user_rate')->pluck('target'))->pluck('email', 'id');
        return ['items' => $active->map(fn ($row) => [
            'id' => $row->id, 'event_id' => $row->event_id, 'kind' => $row->kind, 'target' => $row->target,
            'email' => $row->kind === 'user_rate' ? ($users[(int) $row->target] ?? null) : null,
            'count' => $row->count, 'triggered_at' => $row->triggered_at, 'expires_at' => $row->expires_at,
        ])->all(), 'next_cursor' => $more ? $page->last()->id : null];
    }

    private function active(SubscriptionRestriction $row, array $settings): bool
    {
        $enabled = match ($row->kind) {
            'ip_ban' => $settings['brute_force_enabled'], 'ip_rate' => $settings['rate_limit_enabled'],
            'user_rate' => $settings['user_rate_limit_enabled'],
        };
        if (!$enabled || $row->expires_at <= now()->timestamp || $row->rule !== self::rule($row->kind, $settings)) return false;
        if ($row->kind === 'user_rate'
            ? in_array((int) $row->target, $settings['whitelist_user_ids'], true)
            : SubscriptionProtectionIp::matches($row->target, $settings['whitelist_ips'])) return false;
        $state = Cache::store('redis')->get(self::key($row->kind, $row->target), []);
        return ($state[self::field($row->kind)]['event_id'] ?? null) === $row->event_id;
    }

    public function release(int $id, string $eventId, bool $whitelist): array
    {
        $cache = Cache::store('redis');
        return $cache->lock(self::SETTINGS_LOCK, 10)->block(2, function () use ($cache, $id, $eventId, $whitelist) {
            $row = SubscriptionRestriction::find($id);
            if (!$row || $row->event_id !== $eventId) throw new ApiException('限制记录已变化，请刷新列表。', 409);
            $key = self::key($row->kind, $row->target);
            return $cache->lock($key . ':lock', 10)->block(2, function () use ($cache, $row, $key, $whitelist) {
                app()->forgetInstance(Setting::class);
                $settings = SubscriptionProtection::settings();
                if (!$this->active($row, $settings)) throw new ApiException('这次限制已失效，请刷新列表。', 409);
                if ($whitelist) {
                    if ($row->kind === 'user_rate') {
                        if (!User::whereKey((int) $row->target)->exists()) throw new ApiException('用户已不存在。', 409);
                        if (count($settings['whitelist_user_ids']) >= 1000) throw new ApiException('用户白名单已满。', 422);
                        $settings['whitelist_user_ids'][] = (int) $row->target;
                    } else {
                        if (SubscriptionProtectionIp::normalize($row->target) === null) throw new ApiException('来源地址无效，无法加白。', 422);
                        if (count($settings['whitelist_ips']) >= 256) throw new ApiException('IP 白名单已满。', 422);
                        $settings['whitelist_ips'][] = $row->target;
                    }
                    admin_setting([SubscriptionProtection::SETTING => $settings]);
                    // IP 加白覆盖此 IP 的两项防护；用户加白只重置此账号计数。
                    $cache->forget($key);
                    SubscriptionRestriction::where('target', $row->target)
                        ->whereIn('kind', $row->kind === 'user_rate' ? ['user_rate'] : ['ip_ban', 'ip_rate'])->delete();
                } else {
                    $state = $cache->get($key, []);
                    unset($state[self::field($row->kind)]);
                    $expires = max($state['brute']['expires'] ?? 0, $state['brute']['blocked_until'] ?? 0, $state['rate']['expires'] ?? 0);
                    $cache->put($key, $state, max(1, $expires - now()->timestamp));
                    SubscriptionRestriction::whereKey($row->id)->where('event_id', $row->event_id)->delete();
                }
                return $settings;
            });
        });
    }
}
