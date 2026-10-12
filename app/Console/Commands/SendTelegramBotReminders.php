<?php

namespace App\Console\Commands;

use App\Services\TelegramBot\ReminderService;
use App\Services\TelegramBot\AdminNotificationService;
use Illuminate\Console\Command;

class SendTelegramBotReminders extends Command
{
    protected $signature = 'telegram-bot:send-reminders';
    protected $description = '检查用户到期状态并派发用户提醒和管理员告警';

    public function handle(ReminderService $service, AdminNotificationService $notifications): int
    {
        $service->schedule();
        $notifications->schedule();
        return self::SUCCESS;
    }
}
