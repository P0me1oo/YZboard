<?php

namespace App\Jobs;

use App\Services\TelegramBot\AdminNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class SendTelegramAdminNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;
    public int $timeout = 30;

    public function __construct(public int $notificationId)
    {
        $this->onQueue('send_telegram');
    }

    public function handle(AdminNotificationService $service): void
    {
        $service->send($this->notificationId);
    }
}
