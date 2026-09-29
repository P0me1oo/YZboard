<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\PersonalAccessToken;

class RealtimeTicketService
{
    public function issue(User $user): string
    {
        $accessToken = $user->currentAccessToken();
        $ticket = bin2hex(random_bytes(32));
        Cache::put($this->key($ticket), [
            'user_id' => (int) $user->id,
            'token_id' => $accessToken instanceof PersonalAccessToken ? (int) $accessToken->id : null,
        ], 30);
        return $ticket;
    }

    public function consume(string $ticket): ?array
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/', $ticket)) return null;
        $key = $this->key($ticket);
        return Cache::lock($key . ':lock', 5)->block(1, function () use ($key): ?array {
            $identity = Cache::pull($key);
            return is_array($identity) && $this->isValid($identity) ? $identity : null;
        });
    }

    public function isValid(array $identity): bool
    {
        $user = User::query()->find($identity['user_id'] ?? 0, ['id', 'is_admin', 'banned']);
        if (!$user || !$user->is_admin || $user->banned) return false;
        if (!empty($identity['token_id'])) {
            $token = PersonalAccessToken::query()->find($identity['token_id']);
            if (!$token || (int) $token->tokenable_id !== (int) $user->id || $token->tokenable_type !== $user->getMorphClass()) return false;
            if ($token->expires_at && $token->expires_at->isPast()) return false;
        }
        return true;
    }

    private function key(string $ticket): string
    {
        return 'realtime:ticket:' . hash('sha256', $ticket);
    }
}
