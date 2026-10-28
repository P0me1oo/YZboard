<?php

namespace App\Console\Commands;

use App\Services\ProxyAccessLog;
use Illuminate\Console\Command;

class PruneAccessLogs extends Command
{
    protected $signature = 'access-log:prune';
    protected $description = '按保存天数清理到期的代理访问记录';

    public function handle(ProxyAccessLog $logs): int
    {
        $this->info('已清理 ' . $logs->prune() . ' 条访问记录。');
        return self::SUCCESS;
    }
}
