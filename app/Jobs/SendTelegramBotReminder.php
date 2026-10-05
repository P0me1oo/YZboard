<?php

namespace App\Jobs;

use App\Services\TelegramBot\ReminderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class SendTelegramBotReminder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;
    public int $timeout = 30;

    public function __construct(public int $reminderId)
    {
        $this->onQueue('send_telegram');
    }

    public function handle(ReminderService $service): void
    {
        $service->send($this->reminderId);
    }
}
