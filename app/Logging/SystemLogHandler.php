<?php

namespace App\Logging;

use App\Services\LogSettings;
use Monolog\Handler\HandlerWrapper;
use Monolog\LogRecord;

class SystemLogHandler extends HandlerWrapper
{
    private bool $readingPolicy = false;

    public function handle(LogRecord $record): bool
    {
        if ($this->readingPolicy) return false;
        $this->readingPolicy = true;
        try {
            $enabled = LogSettings::enabled('system');
        } catch (\Throwable) {
            // 策略读取失败时不恢复日志，也不通过日志递归报告该故障。
            $enabled = false;
        } finally {
            $this->readingPolicy = false;
        }
        return $enabled ? $this->handler->handle($record) : false;
    }

    public function handleBatch(array $records): void
    {
        foreach ($records as $record) $this->handle($record);
    }
}
