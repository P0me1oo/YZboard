<?php

namespace App\Console\Commands;

use App\Services\TelegramBot\ReminderService;
use App\Services\TelegramBot\AdminNotificationService;
use App\Services\TelegramBot\BusinessNotificationService;
use App\Services\TelegramBot\NodeOfflineService;
use Illuminate\Console\Command;

class SendTelegramBotReminders extends Command
{
    protected $signature = 'telegram-bot:send-reminders';
    protected $description = '检查用户到期、节点掉线并派发 Telegram 提醒和业务通知';

    public function handle(ReminderService $service, AdminNotificationService $notifications, BusinessNotificationService $business, NodeOfflineService $nodes): int
    {
        $service->schedule();
        $notifications->schedule();
        $nodes->schedule();
        $business->schedule();
        return self::SUCCESS;
    }
}
