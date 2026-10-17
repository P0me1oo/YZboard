<?php

namespace App\Console\Commands;

use App\Services\SubscriptionStatisticsWriter;
use Illuminate\Console\Command;

class MaintainSubscriptionStatistics extends Command
{
    protected $signature = 'subscription-statistics:maintain';
    protected $description = '合并订阅时间块并清理到期统计';

    public function handle(SubscriptionStatisticsWriter $writer): int
    {
        try {
            $writer->prune();
            $merged = $writer->compact();
            $this->info('订阅统计清理完成，合并时间块：' . $merged);
            return self::SUCCESS;
        } catch (\Throwable) {
            app(\App\Services\SubscriptionStatisticsBuffer::class)->failure('maintenance');
            $this->error('订阅统计维护失败，请检查故障记录');
            return self::FAILURE;
        }
    }
}
