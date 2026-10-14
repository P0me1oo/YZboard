<?php

namespace App\Jobs;

use App\Services\TelegramService;
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
        $kind = $this->notificationKind;
        // 升级前排队的内置插件消息没有分类字段，按旧版固定前缀识别。
        if ($kind === null) {
            if (str_starts_with($this->text, '节点掉线通知')) $kind = 'node_offline';
            if (preg_match('/\A\x{1F4EE} \*工单提醒\*/u', $this->text)) $kind = 'ticket';
            if (preg_match('/\A\x{1F4B0}成功收款/u', $this->text)) $kind = 'payment';
        }
        if ($kind !== null && \App\Services\TelegramBot\BusinessNotificationService::owns($kind)) return;
        $telegramService = new TelegramService();
        $telegramService->sendMessage($this->telegramId, $this->text, 'markdown');
    }
}
