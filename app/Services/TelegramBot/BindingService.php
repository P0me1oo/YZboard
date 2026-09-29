<?php

namespace App\Services\TelegramBot;

use App\Models\TelegramBotBinding;
use App\Models\User;
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
