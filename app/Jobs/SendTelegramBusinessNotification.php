<?php

namespace App\Jobs;

use App\Services\TelegramBot\BusinessNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class SendTelegramBusinessNotification implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;
    public int $timeout = 30;
    public int $uniqueFor = 300;

    public function __construct(public int $notificationId)
    {
        $this->onQueue('send_telegram');
    }

    public function uniqueId(): string
    {
        return (string) $this->notificationId;
    }

    public function handle(BusinessNotificationService $service): void
    {
        $service->send($this->notificationId);
    }
}
