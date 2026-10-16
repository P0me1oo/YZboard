<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

/**
 * 限制统计页面同时执行的重查询数量，避免多个管理员页面同时压满数据库。
 * 锁只保护查询执行时间，不缓存任何结果，也不改变查询权限和筛选条件。
 */
class StatisticsQueryLimiter
{
    private const SLOTS = 2;
    private const LOCK_SECONDS = 30;
    private const WAIT_SECONDS = 0.5;

    public function run(callable $callback): mixed
    {
        $lock = $this->acquire();
        $timeout = null;
        try {
            $timeout = $this->applyTimeout();
            return $callback();
        } finally {
            $this->restoreTimeout($timeout);
            $lock?->release();
        }
    }

    private function acquire()
    {
        $deadline = microtime(true) + self::WAIT_SECONDS;
        do {
            for ($slot = 0; $slot < self::SLOTS; $slot++) {
                $lock = Cache::lock('yz:statistics:heavy-read:' . $slot, self::LOCK_SECONDS);
                if ($lock->get()) {
                    return $lock;
                }
            }
            usleep(10_000);
        } while (microtime(true) < $deadline);

        throw new TooManyRequestsHttpException(null, '统计查询繁忙，请稍后重试。');
    }

    /** MariaDB/MySQL 在数据库层终止失控重查询；SQLite 由结果和时间范围上限保护。 */
    private function applyTimeout(): ?array
    {
        if (DB::connection()->getDriverName() !== 'mysql') return null;
        foreach ([['max_statement_time', 5], ['max_execution_time', 5000]] as [$variable, $value]) {
            try {
                $old = DB::selectOne('SELECT @@SESSION.' . $variable . ' AS value');
                DB::statement('SET SESSION ' . $variable . ' = ' . $value);
                return [$variable, $old->value];
            } catch (Throwable) {
                // MariaDB 和 MySQL 使用不同的会话变量；继续尝试另一种语法。
            }
        }
        return null;
    }

    private function restoreTimeout(?array $timeout): void
    {
        if ($timeout === null) return;
        try {
            [$variable, $value] = $timeout;
            DB::statement('SET SESSION ' . $variable . ' = ' . (is_numeric($value) ? $value : 0));
        } catch (Throwable) {
            // 恢复失败不能覆盖原始查询结果；连接池会在下一次请求重新设置上限。
        }
    }
}
