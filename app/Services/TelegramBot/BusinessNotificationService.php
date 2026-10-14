<?php

namespace App\Services\TelegramBot;

use App\Jobs\SendTelegramBusinessNotification;
use App\Models\Order;
use App\Models\TelegramBotBinding;
use App\Models\TelegramBotConfig;
use App\Models\TelegramBusinessNotification;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\TicketService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

class BusinessNotificationService
{
    public function __construct(private BotClient $client)
    {
    }

    /** 开关表示新版接管该功能，停用机器人也不回退到旧插件。 */
    public static function owns(string $feature): bool
    {
        $column = match ($feature) {
            'ticket' => 'notify_ticket', 'ticket_reply' => 'allow_ticket_reply', 'payment' => 'notify_payment',
            'node_offline' => 'notify_node_offline',
            default => null,
        };
        return $column !== null && (bool) TelegramBotConfig::find(1)?->{$column};
    }

    public static function authorized(?User $user): bool
    {
        return $user && !$user->banned && ($user->is_admin || $user->is_staff);
    }

    public function recordTicket(TicketMessage $message): void
    {
        $ticket = $message->ticket;
        if ($ticket && (int) $message->user_id === (int) $ticket->user_id) {
            $this->record('ticket', (int) $message->id);
        }
    }

    public function recordPayment(Order $order): void
    {
        if ($order->paid_at && $order->payment_id && $order->total_amount > 0
            && in_array((int) $order->status, [Order::STATUS_PROCESSING, Order::STATUS_COMPLETED], true)) {
            $this->record('payment', (int) $order->id);
        }
    }

    private function record(string $kind, int $eventId): void
    {
        DB::transaction(function () use ($kind, $eventId): void {
            $config = TelegramBotConfig::whereKey(1)->sharedLock()->first();
            if (!$config?->enabled || !$config->bot_id || !$config->{'notify_' . $kind}) return;
            TelegramBotBinding::whereHas('user', fn ($query) => $query->where('banned', 0)
                ->where(fn ($roles) => $roles->where('is_admin', 1)
                    ->when($kind !== 'node_offline', fn ($roles) => $roles->orWhere('is_staff', 1))))
                ->chunkById(100, function ($bindings) use ($config, $kind, $eventId): void {
                    foreach ($bindings as $binding) {
                        TelegramBusinessNotification::firstOrCreate([
                            'bot_id' => $config->bot_id, 'binding_id' => $binding->id,
                            'kind' => $kind, 'event_id' => $eventId,
                        ], ['telegram_id' => $binding->telegram_id, 'retry_at' => now()->timestamp]);
                    }
                });
        });
    }

    public function schedule(): void
    {
        if (!TelegramBotConfig::find(1)?->enabled) return;
        $slots = [];
        TelegramBusinessNotification::where('status', 'pending')->where('retry_at', '<=', now()->timestamp)
            ->chunkById(200, function ($notifications) use (&$slots): void {
                foreach ($notifications as $notification) {
                    $delay = $slots[$notification->telegram_id] ?? 0;
                    SendTelegramBusinessNotification::dispatch($notification->id)->delay(now()->addSeconds($delay));
                    $slots[$notification->telegram_id] = $delay + 1;
                }
            });
    }

    public function recordNodeOffline(int $incidentId): void
    {
        $this->record('node_offline', $incidentId);
    }

    public function send(int $id): void
    {
        DB::transaction(function () use ($id): void {
            $config = TelegramBotConfig::whereKey(1)->sharedLock()->first();
            $notification = TelegramBusinessNotification::whereKey($id)->lockForUpdate()->first();
            $now = now()->timestamp;
            if (!$notification || $notification->status !== 'pending' || $notification->retry_at > $now) return;
            // 整体停用只暂停投递，恢复后仍核对权限与消息来源。
            if (!$config?->enabled) return;
            $binding = TelegramBotBinding::whereKey($notification->binding_id)->sharedLock()->first();
            $recipient = $binding ? User::whereKey($binding->user_id)->sharedLock()->first() : null;
            if (!$config->getRawOriginal('token') || $config->bot_id !== $notification->bot_id
                || !$config->{'notify_' . $notification->kind} || !$binding
                || $binding->telegram_id !== $notification->telegram_id || !self::authorized($recipient)
                || ($notification->kind === 'node_offline' && !$recipient->is_admin)
                || ($text = $this->message($notification)) === null) {
                $notification->update(['status' => 'cancelled']);
                return;
            }
            if (RateLimiter::tooManyAttempts('telegram-bot:reminders:send', 20)
                || !RateLimiter::attempt('telegram-bot:business:chat:' . $notification->telegram_id, 1, fn () => true, 1)) {
                $notification->update(['retry_at' => $now + 2]);
                return;
            }
            RateLimiter::hit('telegram-bot:reminders:send', 1);
            try {
                $result = $this->client->request($config->token, 'sendMessage', [
                    'chat_id' => $notification->telegram_id, 'text' => $text,
                    'link_preview_options' => ['is_disabled' => true],
                ]);
                if (!is_array($result) || !is_int($result['message_id'] ?? null) || $result['message_id'] <= 0
                    || ($result['chat']['id'] ?? null) !== $notification->telegram_id
                    || ($result['chat']['type'] ?? null) !== 'private') {
                    throw new BotApiException('Telegram 未确认通知发送成功。');
                }
                $notification->update(['status' => 'sent', 'sent_at' => now()->timestamp,
                    'telegram_message_id' => $result['message_id']]);
            } catch (BotApiException $error) {
                $attempts = $notification->attempts + 1;
                $terminal = in_array($error->telegramStatus, [400, 401, 403], true) || $attempts >= 5;
                $notification->update(['status' => $terminal ? 'failed' : 'pending', 'attempts' => $attempts,
                    'retry_at' => $now + max($error->retryAfter, min(3600, 60 * 2 ** ($attempts - 1)))]);
            }
        });
    }

    /** 必须回复本机器人实际向当前绑定发送过的工单提醒，正文不能选工单。 */
    public function reply(array $context, ?TelegramBotBinding $binding): string
    {
        $config = TelegramBotConfig::whereKey(1)->sharedLock()->firstOrFail();
        if (!$config->allow_ticket_reply) return 'ticket_reply_disabled';
        $binding = $binding ? TelegramBotBinding::whereKey($binding->id)->sharedLock()->first() : null;
        $actor = $binding ? User::whereKey($binding->user_id)->sharedLock()->first() : null;
        if (!$binding || $binding->telegram_id !== $context['sender_id'] || !self::authorized($actor)) {
            return 'ticket_reply_forbidden';
        }
        $notification = TelegramBusinessNotification::where('bot_id', $config->bot_id)
            ->where('binding_id', $binding->id)->where('telegram_id', $context['sender_id'])
            ->where('kind', 'ticket')->where('status', 'sent')
            ->where('telegram_message_id', $context['reply_message_id'])->first();
        $source = $notification ? TicketMessage::find($notification->event_id) : null;
        $ticket = $source ? Ticket::whereKey($source->ticket_id)->lockForUpdate()->first() : null;
        if (!$ticket || !$ticket->user || (int) $source->user_id !== (int) $ticket->user_id
            || (int) $ticket->user_id === (int) $actor->id) return 'ticket_reply_invalid';
        if ((int) $ticket->status !== Ticket::STATUS_OPENING) return 'ticket_closed';
        $service = app(TicketService::class);
        $message = $service->reply($ticket, $context['text'], $actor->id);
        if (!$message) throw new \App\Exceptions\ApiException('工单回复暂未保存，请稍后重试。', 503);
        // 当前消息防重记录与工单回复共同提交；通知在提交后执行。
        DB::afterCommit(fn () => $service->notifyAdminReply($ticket, $message));
        return 'ticket_replied';
    }

    private function message(TelegramBusinessNotification $notification): ?string
    {
        if ($notification->kind === 'node_offline') {
            return app(NodeOfflineService::class)->message($notification->event_id);
        }
        $site = '面板：' . $this->text(admin_setting('app_name', 'YZboard'), 80);
        if ($notification->kind === 'ticket') {
            $message = TicketMessage::find($notification->event_id);
            $ticket = $message?->ticket;
            $user = $ticket?->user;
            if (!$ticket || !$user || (int) $message->user_id !== (int) $ticket->user_id
                || (int) $ticket->status !== Ticket::STATUS_OPENING) return null;
            $text = implode("\n", [
                '工单提醒 #' . $ticket->id, $site,
                '用户：' . $this->text($user->email, 128) . '（编号 ' . $user->id . '）',
                '套餐：' . $this->text($user->plan?->name ?? '未订购套餐', 100),
                '剩余 / 总流量：' . number_format(max(0, $user->transfer_enable - $user->u - $user->d) / 1024 ** 3, 2)
                    . ' / ' . number_format($user->transfer_enable / 1024 ** 3, 2) . ' GB',
                '余额：' . number_format($user->balance / 100, 2, '.', '') . ' 元',
                '主题：' . $this->text($ticket->subject, 200), '', $this->text($message->message, 2400),
            ]);
        } else {
            $order = Order::find($notification->event_id);
            if (!$order || !$order->user || !$order->paid_at
                || !in_array((int) $order->status, [Order::STATUS_PROCESSING, Order::STATUS_COMPLETED, Order::STATUS_DISCOUNTED], true)) return null;
            $text = implode("\n", [
                '支付通知', $site, '成功收款：' . number_format($order->total_amount / 100, 2, '.', '') . ' 元',
                '支付接口：' . $this->text($order->payment?->payment ?? '已删除', 100),
                '支付渠道：' . $this->text($order->payment?->name ?? '已删除', 100),
                '本站订单：' . $this->text($order->trade_no, 100),
            ]);
        }
        // Telegram 按 UTF-16 计长度，保留多字节字符并给消息上限留余量。
        return mb_convert_encoding(mb_strcut(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8'), 0, 7800, 'UTF-16LE'), 'UTF-8', 'UTF-16LE');
    }

    private function text(mixed $value, int $max): string
    {
        return mb_substr(trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $value) ?? ''), 0, $max);
    }
}
