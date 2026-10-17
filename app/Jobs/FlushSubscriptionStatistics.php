<?php

namespace App\Jobs;

use App\Services\SubscriptionStatisticsBuffer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class FlushSubscriptionStatistics implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, Queueable;

    public $tries = 3;
    public $timeout = 25;
    public $uniqueFor = 60;

    public function __construct() { $this->onQueue('stat'); }
    public function backoff(): array { return [5, 15, 30]; }

    public function handle(SubscriptionStatisticsBuffer $buffer): void
    {
        try { $buffer->flush(); } catch (\Throwable $error) {
            $buffer->failure('flush');
            // 队列失败记录只保存安全错误；原批次仍留在 Redis，下一次调度可以恢复。
            throw new \RuntimeException('订阅统计批次写入失败');
        }
    }
}
