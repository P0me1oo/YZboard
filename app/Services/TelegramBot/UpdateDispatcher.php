<?php

namespace App\Services\TelegramBot;

use App\Exceptions\ApiException;
use App\Models\TelegramBotConfig;
use App\Models\TelegramBotUpdate;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

class UpdateDispatcher
{
    public function __construct(
        private BindingService $bindings,
        private MessagePresenter $presenter,
        private BotClient $client,
    ) {
    }

    public function handle(array $update, TelegramBotConfig $config): void
    {
        $context = $this->context($update, $config);
        if (!$context) {
            return;
        }
        if ($context['callback_id'] !== null) {
            try {
                $this->client->answerCallback($config->token, $context['callback_id']);
            } catch (ApiException) {
                // 回调应答过期不应使已经应用的业务重复执行。
            }
        }
        try {
            Cache::lock('telegram-bot:sender:' . $config->bot_id . ':' . $context['sender_id'], 60)
                ->block(2, function () use ($config, $context) {
                    $current = TelegramBotConfig::findOrFail(1);
                    if (!$current->enabled || $current->bot_id !== $config->bot_id
                        || !hash_equals($current->webhook_secret ?? '', $config->webhook_secret ?? '')) {
                        throw new ApiException('机器人暂未启用，请稍后重试。', 503);
                    }
                    $record = DB::transaction(function () use ($context, $config) {
                        $record = TelegramBotUpdate::where('bot_id', $config->bot_id)
                            ->where('update_id', $context['update_id'])->lockForUpdate()->first();
                        if ($record) {
                            return $record;
                        }
                        [$action, $bindingId] = $this->apply($context);
                        return TelegramBotUpdate::create([
                            'bot_id' => $config->bot_id,
                            'update_id' => $context['update_id'],
                            'telegram_id' => $context['sender_id'],
                            'action' => $action,
                            'binding_id' => $bindingId,
                        ]);
                    }, 3);
                    if ($record->sent_at !== null || $record->telegram_id !== $context['sender_id']) {
                        return;
                    }
                    $binding = $this->bindings->current($context['sender_id']);
                    $action = $context['private'] ? $record->action : 'private_only';
                    if ($record->binding_id !== null && $binding?->id !== $record->binding_id) {
                        $action = 'stale';
                    }
                    $this->client->reply($config->token, $context, $this->presenter->render($action, $binding));
                    $record->sent_at = time();
                    $record->save();
                });
        } catch (LockTimeoutException) {
            throw new ApiException('消息正在处理，请稍后重试。', 503);
        }
    }

    private function apply(array $context): array
    {
        if (!$context['private']) {
            return ['private_only', null];
        }
        $sender = $context['sender_id'];
        $binding = $this->bindings->current($sender);
        $action = $context['action'];
        if ($action === 'bind') {
            if ($binding) {
                return ['already_bound', $binding->id];
            }
            $limit = 'telegram-bot:bind:' . $sender;
            if (RateLimiter::tooManyAttempts($limit, 5)) {
                return ['limited', null];
            }
            RateLimiter::hit($limit, 60);
            try {
                $binding = $this->bindings->bind($sender, $context['text']);
                return ['bound', $binding->id];
            } catch (BindingException $e) {
                return [$e->getMessage(), null];
            }
        }
        if ($action === 'unbind') {
            $binding = $this->bindings->prepareUnbind($sender);
            return ['confirm_unbind', $binding?->id];
        }
        if ($action === 'unbind_confirm') {
            return [$this->bindings->confirmUnbind($sender, $context['confirmation']) ? 'unbound' : 'stale', null];
        }
        if ($action === 'unbind_cancel') {
            $this->bindings->cancelUnbind($sender);
            return ['menu', $binding?->id];
        }
        return [$action, $binding?->id];
    }

    private function context(array $update, TelegramBotConfig $config): ?array
    {
        if (!is_int($update['update_id'] ?? null) || $update['update_id'] < 0) {
            return null;
        }
        $callback = $update['callback_query'] ?? null;
        if ($callback !== null && !is_array($callback)) {
            return null;
        }
        $message = $callback['message'] ?? $update['message'] ?? null;
        $from = $callback['from'] ?? $message['from'] ?? null;
        if (!is_array($message) || !is_array($from) || !is_int($from['id'] ?? null) || $from['id'] <= 0
            || ($from['is_bot'] ?? false) || !is_int($message['chat']['id'] ?? null)
            || !is_int($message['message_id'] ?? null)) {
            return null;
        }
        $private = ($message['chat']['type'] ?? '') === 'private';
        if ($private && $message['chat']['id'] !== $from['id']) {
            return null;
        }
        $text = $callback['data'] ?? $message['text'] ?? null;
        if (!is_string($text) || strlen($text) > ($callback !== null ? 64 : 4096)) {
            return null;
        }
        $action = 'unknown';
        $confirmation = '';
        if ($callback !== null) {
            if (!is_string($callback['id'] ?? null) || strlen($callback['id']) > 256
                || ($message['from']['id'] ?? null) !== $config->bot_id) {
                return null;
            }
            if (in_array($text, ['menu', 'subscription', 'link', 'account', 'unbind', 'unbind_cancel'], true)) {
                $action = $text;
            } elseif (preg_match('/\Aunbind:([A-Za-z0-9]{24})\z/', $text, $matches)) {
                $action = 'unbind_confirm';
                $confirmation = $matches[1];
            }
        } else {
            if (!is_int($message['date'] ?? null)) {
                return null;
            }
            $text = trim($text);
            $command = preg_split('/\s+/', $text, 2)[0];
            $suffix = '@' . strtolower((string) $config->bot_username);
            if (str_ends_with(strtolower($command), $suffix)) {
                $command = substr($command, 0, -strlen($suffix));
            }
            $action = match ($command) {
                '/start' => 'menu',
                '/unbind' => 'unbind',
                default => str_starts_with($text, '/') ? 'unknown' : 'bind',
            };
            // 清理七天前的防重记录后，旧文本仍不得重新建立绑定。
            if ($message['date'] < time() - 7 * 86400 || $message['date'] > time() + 300) {
                $action = 'stale';
            }
        }
        return [
            'update_id' => $update['update_id'],
            'sender_id' => $from['id'],
            'chat_id' => $message['chat']['id'],
            'message_id' => $message['message_id'],
            'private' => $private,
            'callback_id' => $callback['id'] ?? null,
            'action' => $action,
            'confirmation' => $confirmation,
            'text' => $text,
        ];
    }
}
