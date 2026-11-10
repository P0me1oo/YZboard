<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Database\QueryException;
use App\Exceptions\ApiException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** 已处理异常的诊断出口；不保存请求、SQL 参数、堆栈参数或异常对象。 */
class FailureLog
{
    private static array $last = [];

    public static function record(string $operation, Throwable $error, array $context = [], string $level = 'error', bool $includeExpected = false): void
    {
        if (!$includeExpected && ($error instanceof ValidationException
            || $error instanceof ApiException && (int) substr((string) $error->getCode(), 0, 3) < 500
            || $error instanceof HttpExceptionInterface && $error->getStatusCode() < 500)) return;
        // 内存限频不依赖 Redis/数据库，依赖故障时仍可用；常驻进程最多保留 256 项。
        $key = $operation . '|' . $level . '|' . get_class($error) . '|' . ($context['node_id'] ?? $context['machine_id'] ?? '');
        $now = microtime(true);
        if ($now - (self::$last[$key] ?? 0) < 60) return;
        if (count(self::$last) >= 256) array_shift(self::$last);
        self::$last[$key] = $now;
        try {
            $safe = [];
            foreach (['id', 'user_id', 'node_id', 'machine_id', 'ticket_id', 'notice_id', 'code'] as $field) {
                if (isset($context[$field]) && is_int($context[$field])) $safe[$field] = $context[$field];
            }
            if (isset($context['event']) && is_string($context['event']) && preg_match('/\A[a-z][a-z._]{0,63}\z/', $context['event'])) {
                $safe['event'] = $context['event'];
            }
            $context = $safe;
            $context['exception_class'] = get_class($error);
            // 数据库原始错误正文也可能包含冲突字段值；只保留状态码与驱动码。
            $context['error'] = $error instanceof QueryException ? 'Database query failed' : LogSanitizer::text($error->getMessage());
            if ($error instanceof QueryException) {
                $context['sql_state'] = $error->errorInfo[0] ?? (string) $error->getCode();
                $context['driver_code'] = $error->errorInfo[1] ?? null;
            }
            $context['file'] = basename($error->getFile());
            $context['line'] = $error->getLine();
            $context['trace'] = array_map(fn ($frame) => [
                'file' => basename($frame['file'] ?? ''), 'line' => $frame['line'] ?? null,
                'function' => ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? ''),
            ], array_slice($error->getTrace(), 0, 8));
            Log::log($level, $operation, $context);
        } catch (Throwable) {
            // 日志不可写不能覆盖业务错误；SystemLogHandler 单独报告出口故障。
        }
    }
}
