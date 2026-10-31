<?php

namespace App\Console\Commands;

use App\Services\AccessLogPruner;
use Illuminate\Console\Command;

class PruneAccessLogs extends Command
{
    protected $signature = 'access-log:prune
        {--resume : 仅继续尚未完成的清理}
        {--batch=500 : 每批最多删除的记录数}
        {--batches=100 : 单轮最多批次数}
        {--max-seconds=10 : 达到该时长后不再开始下一批}';
    protected $description = '按保存天数清理到期的代理访问记录';

    public function handle(AccessLogPruner $pruner): int
    {
        $result = $pruner->run((bool) $this->option('resume'), (int) $this->option('batch'),
            (int) $this->option('batches'), (int) $this->option('max-seconds'));
        $this->info($result['busy'] ? '已有访问日志清理正在执行。' : '已清理 ' . $result['deleted'] . ' 条访问记录。'
            . ($result['pending'] ? '剩余待办将在下一分钟继续处理。' : ''));
        return self::SUCCESS;
    }
}
