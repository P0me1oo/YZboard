<?php

namespace App\Console\Commands;

use App\Models\TelegramBotUpdate;
use Illuminate\Console\Command;

class PruneTelegramBotUpdates extends Command
{
    protected $signature = 'telegram-bot:prune-updates';
    protected $description = '清理七天前的 Telegram Bot 消息防重记录';

    public function handle(): int
    {
        $count = 0;
        do {
            $ids = TelegramBotUpdate::where('created_at', '<', time() - 7 * 86400)
                ->orderBy('id')->limit(1000)->pluck('id');
            $deleted = TelegramBotUpdate::whereIn('id', $ids)->delete();
            $count += $deleted;
        } while ($deleted > 0);
        $this->info("已清理 {$count} 条消息记录，账号绑定未改变。");
        return self::SUCCESS;
    }
}
