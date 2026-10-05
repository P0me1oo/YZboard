<?php

namespace App\Services\TelegramBot;

use App\Jobs\SendTelegramBotReminder;
use App\Models\TelegramBotBinding;
use App\Models\TelegramBotConfig;
use App\Models\TelegramBotReminder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

class ReminderService
{
    public function __construct(private BotClient $client)
    {
    }

    public function schedule(): void
    {
        $config = TelegramBotConfig::find(1);
        if (!$config?->enabled) {
            return;
        }
        $now = now()->timestamp;
        if ($config->remind_expiring || $config->remind_expired) {
            TelegramBotBinding::whereHas('user', function ($query) use ($config, $now) {
                $query->where('expired_at', '>', 0)->where(function ($query) use ($config, $now) {
                    if ($config->remind_expiring) {
                        $query->whereBetween('expired_at', [$now + 1, $now + $config->remind_days * 86400]);
                    }
                    if ($config->remind_expired && $config->remind_expired_since !== null) {
                        $query->orWhereBetween('expired_at', [$config->remind_expired_since + 1, $now]);
                    }
                });
            })->with('user')->chunkById(200, function ($bindings) use ($config, $now) {
                foreach ($bindings as $binding) {
                    DB::transaction(function () use ($binding, $config, $now) {
                        // 与解绑、超限归并和发送共用绑定行锁，防止留下孤立记录。
                        $current = TelegramBotBinding::whereKey($binding->id)->lockForUpdate()->first();
                        $user = $current?->user;
                        if (!$user || !$user->expired_at) {
                            return;
                        }
                        $kind = $user->expired_at > $now ? 'expiring' : 'expired';
                        if (!$config->{'remind_' . $kind}
                            || ($kind === 'expired' && ($user->expired_at <= ($config->remind_expired_since ?? $now)
                                || $user->expired_at < $current->created_at))
                            || ($kind === 'expiring' && $user->expired_at > $now + $config->remind_days * 86400)) {
                            return;
                        }
                        $reminder = TelegramBotReminder::firstOrCreate([
                            'binding_id' => $current->id, 'kind' => $kind, 'cycle' => $user->expired_at,
                        ], ['retry_at' => $now]);
                        if ($reminder->status === 'cancelled' && $reminder->sent_at === null) {
                            $reminder->update(['status' => 'pending', 'retry_at' => $now]);
                        }
                    });
                }
            });
        }
        // 数据库待发送记录可在队列丢失或工作进程重启后重新派发。
        TelegramBotReminder::where('status', 'pending')->where('retry_at', '<=', $now)
            ->chunkById(200, function ($reminders) {
                foreach ($reminders as $reminder) {
                    SendTelegramBotReminder::dispatch($reminder->id);
                }
            });
    }

    public function recordLimits(array $events): void
    {
        $config = TelegramBotConfig::find(1);
        if (!$config?->enabled || (!$config->remind_device && !$config->remind_connection)) {
            return;
        }
        $groups = [];
        foreach ($events as $event) {
            if ($event['count'] <= 0 || $event['limit'] <= 0) {
                continue;
            }
            $kind = $event['kind'] === 'device' ? 'device' : 'connection';
            if ($config->{'remind_' . $kind}) {
                $groups[$event['user_id']][$kind][$event['kind']] = [
                    'limit' => $event['limit'], 'count' => $event['count'],
                ];
            }
        }
        foreach ($groups as $userId => $kinds) {
            DB::transaction(function () use ($userId, $kinds, $config) {
                $binding = TelegramBotBinding::where('user_id', $userId)->lockForUpdate()->first();
                if (!$binding) {
                    return;
                }
                $now = now()->timestamp;
                foreach ($kinds as $kind => $details) {
                    $reminder = TelegramBotReminder::firstOrNew([
                        'binding_id' => $binding->id, 'kind' => $kind, 'cycle' => 0,
                    ]);
                    if ($reminder->exists && ($reminder->status === 'pending'
                        || ($reminder->sent_at !== null && $now < $reminder->sent_at + $config->remind_interval_minutes * 60)
                        || ($reminder->status === 'failed' && $now < $reminder->retry_at))) {
                        continue;
                    }
                    $reminder->fill([
                        'status' => 'pending', 'details' => ['events' => $details, 'event_at' => $now],
                        'attempts' => 0, 'retry_at' => $now,
                    ])->save();
                }
            });
        }
    }

    public function send(int $id): void
    {
        $record = TelegramBotReminder::find($id);
        if (!$record) {
            return;
        }
        DB::transaction(function () use ($record) {
            $binding = TelegramBotBinding::whereKey($record->binding_id)->lockForUpdate()->first();
            $reminder = TelegramBotReminder::whereKey($record->id)->lockForUpdate()->first();
            $now = now()->timestamp;
            if (!$binding || !$reminder || $reminder->status !== 'pending' || $reminder->retry_at > $now) {
                return;
            }
            $config = TelegramBotConfig::find(1);
            $user = $binding->user;
            if (!$config?->enabled || !$config->getRawOriginal('token') || !$config->{'remind_' . $reminder->kind}
                || !$user || !$this->valid($reminder, $config, $binding, $user->expired_at, $now)) {
                $reminder->update(['status' => 'cancelled']);
                return;
            }
            // 给私聊交互保留余量；限流不会消耗消息重试次数。
            if (RateLimiter::tooManyAttempts('telegram-bot:reminders:send', 20)) {
                $reminder->update(['retry_at' => $now + 2]);
                return;
            }
            RateLimiter::hit('telegram-bot:reminders:send', 1);
            try {
                $result = $this->client->request($config->token, 'sendMessage', [
                    'chat_id' => $binding->telegram_id,
                    'text' => $this->message($reminder, $now),
                    'link_preview_options' => ['is_disabled' => true],
                ]);
                if (!is_array($result) || !is_int($result['message_id'] ?? null) || $result['message_id'] <= 0
                    || ($result['chat']['id'] ?? null) !== $binding->telegram_id
                    || ($result['chat']['type'] ?? null) !== 'private') {
                    throw new BotApiException('Telegram 未确认提醒发送成功。');
                }
                $reminder->update(['status' => 'sent', 'sent_at' => now()->timestamp, 'details' => null]);
            } catch (BotApiException $error) {
                $attempts = $reminder->attempts + 1;
                $terminal = in_array($error->telegramStatus, [400, 401, 403], true) || $attempts >= 5;
                $reminder->update([
                    'status' => $terminal ? 'failed' : 'pending', 'attempts' => $attempts,
                    'retry_at' => $now + ($terminal ? $config->remind_interval_minutes * 60 : min(3600, 60 * 2 ** ($attempts - 1))),
                ]);
            }
        });
    }

    private function valid(TelegramBotReminder $reminder, TelegramBotConfig $config,
        TelegramBotBinding $binding, ?int $expiredAt, int $now): bool
    {
        if ($reminder->cycle !== 0) {
            if ($expiredAt !== $reminder->cycle) {
                return false;
            }
            return $reminder->kind === 'expiring'
                ? $expiredAt > $now && $expiredAt <= $now + $config->remind_days * 86400
                : $expiredAt <= $now && $expiredAt > ($config->remind_expired_since ?? $now)
                    && $expiredAt >= $binding->created_at;
        }
        return ($expiredAt === null || $expiredAt > $now)
            // 至少保留五分钟，避免一分钟间隔的事件在下一轮定时任务前就失效。
            && ($reminder->details['event_at'] ?? 0) + max(300, $config->remind_interval_minutes * 60) > $now
            && ($reminder->sent_at === null || $now >= $reminder->sent_at + $config->remind_interval_minutes * 60);
    }

    private function message(TelegramBotReminder $reminder, int $now): string
    {
        if ($reminder->kind === 'expiring') {
            $days = (int) ceil(($reminder->cycle - $now) / 86400);
            return "订阅到期提醒\n您的订阅将在 {$days} 天内到期。\n到期时间："
                . date('Y-m-d H:i', $reminder->cycle) . "\n请及时续费，以免影响使用。";
        }
        if ($reminder->kind === 'expired') {
            return "订阅过期提醒\n您的订阅已过期。\n到期时间："
                . date('Y-m-d H:i', $reminder->cycle) . "\n如需继续使用，请前往面板续费。";
        }
        $lines = [$reminder->kind === 'device' ? '设备数超限提醒' : '连接数超限提醒'];
        foreach ($reminder->details['events'] as $kind => $event) {
            $label = match ($kind) {
                'device' => '设备数上限', 'conn' => '同时连接数上限', 'rate' => '每秒新建连接数上限',
            };
            $lines[] = "{$label}：{$event['limit']}";
            $lines[] = "本次节点上报中被拒绝的连接次数：{$event['count']}";
        }
        $lines[] = $reminder->kind === 'device'
            ? '设备数按来源 IP 计算，请减少同时使用的网络或设备。'
            : '请减少同时连接数或降低新建连接频率。';
        return implode("\n", $lines);
    }
}
