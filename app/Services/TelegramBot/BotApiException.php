<?php

namespace App\Services\TelegramBot;

use App\Exceptions\ApiException;

class BotApiException extends ApiException
{
    public function __construct(string $message, public readonly int $telegramStatus = 0)
    {
        parent::__construct($message, 502);
    }
}
