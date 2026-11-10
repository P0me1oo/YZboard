<?php

namespace App\Listeners;

use Laravel\Octane\Events\WorkerStopping;

/** Swoole 回收工作进程前结束已有连接，避免进程退出被数据库记为异常断开。 */
class DisconnectWorkerDatabases
{
    public function handle(WorkerStopping $event): void
    {
        if (!$event->app->resolved('db')) return;
        foreach ($event->app->make('db')->getConnections() as $connection) {
            try {
                $connection->disconnect();
            } catch (\Throwable $exception) {
                // Laravel 日志策略可能访问数据库；退出清理不能通过日志重新建立连接。
                error_log('[database] worker disconnect failed exception=' . get_class($exception));
            }
        }
    }
}
