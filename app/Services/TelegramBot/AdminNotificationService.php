<?php

namespace App\Services\TelegramBot;

use App\Jobs\SendTelegramAdminNotification;
use App\Models\Server;
use App\Models\TelegramAdminNotification;
use App\Models\TelegramBotConfig;
use App\Models\User;
use App\Services\LimitEventSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

class AdminNotificationService
{
    public function __construct(private BotClient $client)
    {
    }

    /** 只消费节点实际拒绝的事件，不要求受影响用户绑定 Telegram。 */
    public function recordLimits(Server $node, array $events): void
    {
        $config = TelegramBotConfig::find(1);
        if (!$config?->enabled || !$config->notify_admin_recipients
            || (!$config->notify_admin_device && !$config->notify_admin_connection)) {
            return;
        }
        $now = now()->timestamp;
        $groups = [];
        foreach ($events as $event) {
            if ($event['count'] <= 0 || $event['limit'] <= 0) continue;
            $kind = $event['kind'] === 'device' ? 'device' : 'connection';
            $snapshot = LimitEventSnapshot::normalize($event['snapshot'] ?? null);
            if (($snapshot['occurred_at'] ?? $now) + max(300, $config->notify_admin_interval_minutes * 60) <= $now) continue;
            $groups[$event['user_id']][$kind][$event['kind']] = [
                'limit' => $event['limit'], 'count' => $event['count'], 'snapshot' => $snapshot,
                'ips' => $event['kind'] === 'device' ? ($event['ips'] ?? []) : [],
            ];
        }
        foreach ($groups as $userId => $kinds) {
            DB::transaction(function () use ($node, $userId, $kinds, $now): void {
                // 保存设置与事件登记按同一次序取锁，停用后不能再登记旧配置的事件。
                $config = TelegramBotConfig::whereKey(1)->sharedLock()->first();
                if (!$config?->enabled || !$config->notify_admin_recipients) return;
                if (!User::whereKey($userId)->lockForUpdate()->first()) return;
                foreach ($kinds as $kind => $events) {
                    if (!$config->{'notify_admin_' . $kind}) continue;
                    foreach ($config->notify_admin_recipients as $recipient) {
                        $notification = TelegramAdminNotification::firstOrNew([
                            'user_id' => $userId, 'telegram_id' => (int) $recipient, 'kind' => $kind,
                        ]);
                        if ($notification->exists && ($notification->status === 'pending'
                            || ($notification->sent_at !== null && $now < $notification->sent_at + $config->notify_admin_interval_minutes * 60)
                            || ($notification->status === 'failed' && $now < $notification->retry_at))) continue;
                        $notification->fill([
                            'status' => 'pending', 'attempts' => 0, 'retry_at' => $now,
                            'details' => [
                                'node_id' => (int) $node->id, 'node_name' => $this->text($node->name, 100),
                                'events' => $events, 'event_at' => min($now,
                                    ...array_values(array_map(fn ($event) => $event['snapshot']['occurred_at'] ?? $now, $events))),
                            ],
                        ])->save();
                    }
                }
            });
        }
    }

    public function schedule(): void
    {
        $config = TelegramBotConfig::find(1);
        $now = now()->timestamp;
        // 无论机器人是否启用，都清除过期快照；发送记录仅保留冷却状态。
        TelegramAdminNotification::where('status', 'pending')
            ->where('details->event_at', '<=', $now - max(300, ($config?->notify_admin_interval_minutes ?? 60) * 60))
            ->update(['status' => 'cancelled', 'details' => null]);
        if (!$config?->enabled) return;
        $slots = [];
        TelegramAdminNotification::where('status', 'pending')->where('retry_at', '<=', $now)
            ->chunkById(200, function ($notifications) use (&$slots): void {
                foreach ($notifications as $notification) {
                    // 同一管理员收到不同用户的告警时也分散投递，避免触发私聊限流。
                    $delay = $slots[$notification->telegram_id] ?? 0;
                    SendTelegramAdminNotification::dispatch($notification->id)->delay(now()->addSeconds($delay));
                    $slots[$notification->telegram_id] = $delay + 1;
                }
            });
    }

    public function send(int $id): void
    {
        DB::transaction(function () use ($id): void {
            $notification = TelegramAdminNotification::whereKey($id)->lockForUpdate()->first();
            $now = now()->timestamp;
            if (!$notification || $notification->status !== 'pending' || $notification->retry_at > $now) return;
            $config = TelegramBotConfig::find(1);
            $user = $notification->user;
            if (!$config?->enabled || !$config->getRawOriginal('token') || !$user
                || !$config->{'notify_admin_' . $notification->kind}
                || !in_array((string) $notification->telegram_id, $config->notify_admin_recipients ?? [], true)
                || ($notification->details['event_at'] ?? 0) + max(300, $config->notify_admin_interval_minutes * 60) <= $now
                || ($notification->sent_at !== null && $now < $notification->sent_at + $config->notify_admin_interval_minutes * 60)) {
                $notification->update(['status' => 'cancelled', 'details' => null]);
                return;
            }
            if (RateLimiter::tooManyAttempts('telegram-bot:reminders:send', 20)
                || !RateLimiter::attempt('telegram-bot:admin-notifications:chat:' . $notification->telegram_id, 1, fn () => true, 1)) {
                $notification->update(['retry_at' => $now + 2]);
                return;
            }
            RateLimiter::hit('telegram-bot:reminders:send', 1);
            try {
                $text = implode("\n", [
                    '面板：' . $this->text(admin_setting('app_name', 'YZboard'), 80),
                    '用户：' . $this->text($user->email, 128) . '（编号 ' . $user->id . '）',
                    '节点：' . $notification->details['node_name'] . '（编号 ' . $notification->details['node_id'] . '）',
                    '', app(LimitReminderPresenter::class)->render($notification->kind, $notification->details, true),
                ]);
                $result = $this->client->request($config->token, 'sendMessage', [
                    'chat_id' => $notification->telegram_id, 'text' => $text,
                    'link_preview_options' => ['is_disabled' => true],
                ]);
                if (!is_array($result) || !is_int($result['message_id'] ?? null) || $result['message_id'] <= 0
                    || ($result['chat']['id'] ?? null) !== $notification->telegram_id
                    || ($result['chat']['type'] ?? null) !== 'private') {
                    throw new BotApiException('Telegram 未确认管理员告警发送成功。');
                }
                $notification->update(['status' => 'sent', 'sent_at' => now()->timestamp, 'details' => null]);
            } catch (BotApiException $error) {
                $attempts = $notification->attempts + 1;
                $terminal = in_array($error->telegramStatus, [400, 401, 403], true) || $attempts >= 5;
                $notification->update([
                    'status' => $terminal ? 'failed' : 'pending', 'attempts' => $attempts,
                    'details' => $terminal ? null : $notification->details,
                    'retry_at' => $now + ($terminal ? $config->notify_admin_interval_minutes * 60 : min(3600, 60 * 2 ** ($attempts - 1))),
                ]);
            }
        });
    }

    private function text(mixed $value, int $max): string
    {
        return mb_substr(trim(preg_replace('/[\p{C}\s]+/u', ' ', (string) $value) ?? ''), 0, $max);
    }
}
