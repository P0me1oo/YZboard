<?php

namespace App\Services\TelegramBot;

use App\Exceptions\ApiException;
use App\Models\TelegramBotConfig;

class UserEntryService
{
    public function settings(): array
    {
        $config = TelegramBotConfig::find(1);
        return [
            'is_telegram' => (int) $this->available($config),
            'telegram_discuss_link' => $config?->group_link,
        ];
    }

    public function botInfo(): array
    {
        $config = TelegramBotConfig::find(1);
        if (!$this->available($config)) {
            throw new ApiException('Telegram 机器人尚未启用。');
        }
        return ['username' => $config->bot_username];
    }

    private function available(?TelegramBotConfig $config): bool
    {
        return $config?->enabled && filled($config->bot_username);
    }
}
