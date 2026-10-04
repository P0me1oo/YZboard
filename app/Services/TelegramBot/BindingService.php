<?php

namespace App\Services\TelegramBot;

use App\Models\TelegramBotBinding;
use App\Models\User;
use App\Utils\Helper;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BindingService
{
    public function __construct(private SubscriptionLinkResolver $links)
    {
    }

    public function current(int $telegramId): ?TelegramBotBinding
    {
        return TelegramBotBinding::with('user.plan')->where('telegram_id', $telegramId)->first();
    }

    public function bind(int $telegramId, string $url): TelegramBotBinding
    {
        $token = $this->links->token($url);
        try {
            return DB::transaction(function () use ($telegramId, $token) {
                $users = User::where('token', $token)->limit(2)->lockForUpdate()->get();
                if ($users->count() !== 1) {
                    throw new BindingException('invalid_link');
                }
                $user = $users->first();
                $existing = TelegramBotBinding::where('telegram_id', $telegramId)->lockForUpdate()->first();
                if ($existing) {
                    if ($existing->user_id !== $user->id) {
                        throw new BindingException('occupied');
                    }
                    return $existing;
                }
                if (TelegramBotBinding::where('user_id', $user->id)->exists()) {
                    throw new BindingException('occupied');
                }
                return TelegramBotBinding::create(['user_id' => $user->id, 'telegram_id' => $telegramId]);
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw new BindingException('occupied');
        }
    }

    public function prepareReset(int $telegramId): ?TelegramBotBinding
    {
        return DB::transaction(function () use ($telegramId) {
            $binding = TelegramBotBinding::where('telegram_id', $telegramId)->lockForUpdate()->first();
            if ($binding) {
                $binding->reset_token = Str::random(24);
                $binding->reset_expires_at = time() + 300;
                $binding->saveOrFail();
            }
            return $binding;
        });
    }

    public function cancelReset(int $telegramId): void
    {
        TelegramBotBinding::where('telegram_id', $telegramId)->update([
            'reset_token' => null, 'reset_expires_at' => null,
        ]);
    }

    public function confirmReset(int $telegramId, string $confirmation): ?TelegramBotBinding
    {
        return DB::transaction(function () use ($telegramId, $confirmation) {
            $binding = TelegramBotBinding::where('telegram_id', $telegramId)->first();
            if (!$binding) {
                return null;
            }
            // 与首次绑定保持相同加锁顺序，避免用户行和绑定行互相等待。
            $user = User::whereKey($binding->user_id)->lockForUpdate()->first();
            $binding = TelegramBotBinding::whereKey($binding->id)
                ->where('telegram_id', $telegramId)->lockForUpdate()->first();
            if (!$user || !$binding || !$binding->reset_token || ($binding->reset_expires_at ?? 0) <= time()
                || !hash_equals($binding->reset_token, $confirmation)) {
                return null;
            }

            // 沿用用户页面的重置规则，通过模型事件同步新的节点连接凭据。
            $user->uuid = Helper::guid(true);
            $user->token = Helper::guid();
            $user->saveOrFail();
            $binding->reset_token = null;
            $binding->reset_expires_at = null;
            $binding->saveOrFail();
            return $binding;
        }, 3);
    }

    public function prepareUnbind(int $telegramId): ?TelegramBotBinding
    {
        return DB::transaction(function () use ($telegramId) {
            $binding = TelegramBotBinding::where('telegram_id', $telegramId)->lockForUpdate()->first();
            if ($binding) {
                $binding->unbind_token = Str::random(24);
                $binding->unbind_expires_at = time() + 300;
                $binding->save();
            }
            return $binding;
        });
    }

    public function cancelUnbind(int $telegramId): void
    {
        TelegramBotBinding::where('telegram_id', $telegramId)->update([
            'unbind_token' => null, 'unbind_expires_at' => null,
        ]);
    }

    public function confirmUnbind(int $telegramId, string $confirmation): bool
    {
        return DB::transaction(function () use ($telegramId, $confirmation) {
            $binding = TelegramBotBinding::where('telegram_id', $telegramId)->lockForUpdate()->first();
            if (!$binding || !$binding->unbind_token || ($binding->unbind_expires_at ?? 0) <= time()
                || !hash_equals($binding->unbind_token, $confirmation)) {
                return false;
            }
            return (bool) $binding->delete();
        });
    }
}
