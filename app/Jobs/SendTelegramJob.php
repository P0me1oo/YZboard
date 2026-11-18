<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendTelegramJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    protected $telegramId;
    protected $text;
    protected ?string $notificationKind = null;

    public $tries = 3;
    public $timeout = 10;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(int $telegramId, string $text, ?string $notificationKind = null)
    {
        $this->onQueue('send_telegram');
        $this->telegramId = $telegramId;
        $this->text = $text;
        $this->notificationKind = $notificationKind;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        // 仅接收升级前已经排队的旧载荷；旧机器人功能已移除，不再发送或重试。
    }
}
