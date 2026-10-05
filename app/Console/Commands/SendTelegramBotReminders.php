<?php

namespace App\Console\Commands;

use App\Services\TelegramBot\ReminderService;
use Illuminate\Console\Command;

class SendTelegramBotReminders extends Command
{
    protected $signature = 'telegram-bot:send-reminders';
    protected $description = '检查已绑定用户的到期状态并派发待发送提醒';

    public function handle(ReminderService $service): int
    {
        $service->schedule();
        return self::SUCCESS;
    }
}
