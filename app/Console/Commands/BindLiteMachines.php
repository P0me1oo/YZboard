<?php

namespace App\Console\Commands;

use App\Services\LiteMonitor;
use Illuminate\Console\Command;

class BindLiteMachines extends Command
{
    protected $signature = 'lite:bind-machines';
    protected $description = '按公网 IP 自动绑定 Lite，重试尚未匹配的服务器';

    public function handle(LiteMonitor $monitor): int
    {
        try {
            $count = $monitor->autoBind();
            $this->info("已绑定 {$count} 台服务器。");
            return self::SUCCESS;
        } catch (\Throwable) {
            // 不将带有配置或数据库绑定值的异常写入后台命令输出。
            $this->error('Lite 自动绑定未完成，将在下一分钟重试。');
            return self::FAILURE;
        }
    }
}
