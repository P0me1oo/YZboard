<?php

namespace App\Logging;

use App\Services\LogSettings;
use Monolog\Handler\HandlerWrapper;
use Monolog\LogRecord;

class SystemLogHandler extends HandlerWrapper
{
    private bool $readingPolicy = false;
    private ?bool $lastEnabled = null;
    private float $lastFailureAt = 0;

    public function handle(LogRecord $record): bool
    {
        if ($this->readingPolicy) return false;
        $this->readingPolicy = true;
        try {
            $enabled = LogSettings::enabled('system');
            $this->lastEnabled = $enabled;
        } catch (\Throwable $exception) {
            // 保留停用与未知策略的静默；此前明确开启时只输出链路故障，
            // 不泄露被拦截的业务日志，也不递归调用 Laravel 日志。
            if ($this->lastEnabled === true) $this->diagnostic('policy_read', $exception);
            $enabled = false;
        } finally {
            $this->readingPolicy = false;
        }
        if (!$enabled) return false;
        try {
            return $this->handler->handle($record);
        } catch (\Throwable $exception) {
            $this->diagnostic('write', $exception);
            return false;
        }
    }

    private function diagnostic(string $stage, \Throwable $error): void
    {
        $now = microtime(true);
        if ($now - $this->lastFailureAt < 60) return;
        $this->lastFailureAt = $now;
        try {
            error_log('[logging] system log unavailable stage=' . $stage . ' exception=' . get_class($error));
        } catch (\Throwable) {
            // 最后的错误出口也不可用时，不能递归或覆盖正在处理的业务异常。
        }
    }

    public function handleBatch(array $records): void
    {
        foreach ($records as $record) $this->handle($record);
    }
}
