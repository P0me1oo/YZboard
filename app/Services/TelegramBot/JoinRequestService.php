<?php

namespace App\Services\TelegramBot;

use App\Exceptions\ApiException;
use App\Models\TelegramBotBinding;
use App\Models\TelegramBotConfig;
use App\Models\TelegramBotUpdate;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class JoinRequestService
{
    public function __construct(private BotClient $client)
    {
    }

    public static function ownsGroup(int $chatId): bool
    {
        $config = TelegramBotConfig::find(1);
        return $config?->auto_approve_join && in_array((string) $chatId, $config->join_group_ids ?? [], true);
    }

    public function handle(array $update, TelegramBotConfig $receivedConfig): void
    {
        $request = $update['chat_join_request'] ?? null;
        if (!is_int($update['update_id'] ?? null) || $update['update_id'] < 0 || !is_array($request)
            || !is_array($request['chat'] ?? null) || !is_array($request['from'] ?? null)
            || !is_int($request['chat']['id'] ?? null) || $request['chat']['id'] >= 0
            || !in_array($request['chat']['type'] ?? '', ['group', 'supergroup'], true)
            || !is_int($request['from']['id'] ?? null) || $request['from']['id'] <= 0
            || ($request['from']['is_bot'] ?? false)
            || !is_int($request['date'] ?? null) || $request['date'] <= 0) return;
        $senderId = $request['from']['id'];
        $chatId = $request['chat']['id'];
        try {
            Cache::lock('telegram-bot:sender:' . $receivedConfig->bot_id . ':' . $senderId, 60)
                ->block(2, function () use ($update, $receivedConfig, $senderId, $chatId, $request): void {
                    DB::transaction(function () use ($update, $receivedConfig, $senderId, $chatId, $request): void {
                        $config = TelegramBotConfig::whereKey(1)->sharedLock()->firstOrFail();
                        if (!$config->enabled || $config->bot_id !== $receivedConfig->bot_id
                            || !hash_equals($config->webhook_secret ?? '', $receivedConfig->webhook_secret ?? '')) {
                            throw new ApiException('机器人暂未启用，请稍后重试。', 503);
                        }
                        if (!$config->auto_approve_join || !in_array((string) $chatId, $config->join_group_ids ?? [], true)) return;
                        if (TelegramBotUpdate::where('bot_id', $config->bot_id)->where('update_id', $update['update_id'])->exists()) return;
                        // 仅处理最近一天的事件，避免长时间重投作用于后来发起的新申请。
                        if ($request['date'] < now()->timestamp - 86400 || $request['date'] > now()->timestamp + 60) return;
                        $binding = TelegramBotBinding::where('telegram_id', $senderId)->sharedLock()->first();
                        $user = $binding ? User::whereKey($binding->user_id)->sharedLock()->first() : null;
                        // 沿用旧版资格规则：未封禁、有额度、未到期或长期有效，不额外检查剩余流量。
                        $approve = $user && app(UserService::class)->isAvailable($user);
                        $action = $approve ? 'join_approved' : 'join_declined';
                        try {
                            $this->client->request($config->token, $approve ? 'approveChatJoinRequest' : 'declineChatJoinRequest', [
                                'chat_id' => $chatId, 'user_id' => $senderId,
                            ]);
                        } catch (BotApiException $error) {
                            // 已被管理员处理或撤销的申请可能返回 400；不能记录为批准成功，也不重试永久错误。
                            if ($error->telegramStatus !== 400) throw $error;
                            $action = 'join_unavailable';
                        }
                        TelegramBotUpdate::create([
                            'bot_id' => $config->bot_id, 'update_id' => $update['update_id'],
                            'telegram_id' => $senderId, 'binding_id' => $binding?->id,
                            'action' => $action, 'sent_at' => now()->timestamp,
                        ]);
                    });
                });
        } catch (LockTimeoutException) {
            throw new ApiException('入群申请正在处理，请稍后重试。', 503);
        }
    }
}
