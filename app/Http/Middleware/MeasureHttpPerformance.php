<?php

namespace App\Http\Middleware;

use App\Services\HttpPerformanceSample;
use Closure;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Redis\Events\CommandExecuted;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** 临时诊断：默认关闭，按请求抽样，只写有界的接口聚合数字。 */
class MeasureHttpPerformance
{
    private const ATTRIBUTE = '_http_performance_sample';
    private static ?\WeakMap $dispatchers = null;

    public function handle(Request $request, Closure $next)
    {
        if (!config('http_performance.enabled', false)) return $next($request);
        $until = (int) config('http_performance.until', 0);
        $rate = max(0, min(1, (float) config('http_performance.sample_rate', 0.1)));
        if ($until <= time()
            || $rate <= 0 || ($rate < 1 && mt_rand() / mt_getrandmax() >= $rate)) {
            return $next($request);
        }

        $sample = new HttpPerformanceSample();
        $request->attributes->set(self::ATTRIBUTE, $sample);
        try {
            $restore = $this->listen();
        } catch (\Throwable) {
            $request->attributes->remove(self::ATTRIBUTE);
            return $next($request);
        }
        $started = hrtime(true);
        $status = 500;
        try {
            $response = $next($request);
            $status = $response->getStatusCode();
            return $response;
        } catch (\Throwable $exception) {
            if ($exception instanceof HttpExceptionInterface) $status = $exception->getStatusCode();
            throw $exception;
        } finally {
            $request->attributes->remove(self::ATTRIBUTE);
            try {
                $restore();
                $this->aggregate($request, $sample, (hrtime(true) - $started) / 1e6, $status, $until, $rate);
            } catch (\Throwable) {
                // 观测与写入失败均不能改变业务响应。
            }
        }
    }

    private function listen(): Closure
    {
        $events = app('events');
        self::$dispatchers ??= new \WeakMap();
        if (!isset(self::$dispatchers[$events])) {
            // 监听器不捕获请求或应用实例；Octane 的下一请求解析当前上下文。
            $current = static fn () => app('request')->attributes->get(self::ATTRIBUTE);
            $events->listen(QueryExecuted::class, static function ($event) use ($current): void {
                if ($sample = $current()) {
                    $sample->dbCount++;
                    $sample->dbMs += $event->time;
                }
            });
            $events->listen(CommandExecuted::class, static function ($event) use ($current): void {
                if ($sample = $current()) {
                    $sample->redisCount++;
                    $sample->redisMs += $event->time;
                }
            });
            $events->listen([CacheHit::class, CacheMissed::class], static function () use ($current): void {
                if ($sample = $current()) $sample->cacheReads++;
            });
            $events->listen([KeyWritten::class, KeyForgotten::class], static function () use ($current): void {
                if ($sample = $current()) $sample->cacheWrites++;
            });
            self::$dispatchers[$events] = true;
        }
        $redis = app('redis');
        $enabled = (new \ReflectionProperty($redis, 'events'))->getValue($redis);
        $previous = [];
        foreach ($redis->connections() ?? [] as $connection) $previous[spl_object_id($connection)] = $connection->getEventDispatcher();
        $redis->enableEvents();
        foreach ($redis->connections() ?? [] as $connection) $connection->setEventDispatcher($events);
        return static function () use ($redis, $enabled, $previous, $events): void {
            if (!$enabled) $redis->disableEvents();
            foreach ($redis->connections() ?? [] as $connection) {
                $id = spl_object_id($connection);
                $dispatcher = array_key_exists($id, $previous) ? $previous[$id] : ($enabled ? $events : null);
                if ($dispatcher === null) $connection->unsetEventDispatcher();
                else $connection->setEventDispatcher($dispatcher);
            }
        };
    }

    protected function aggregate(Request $request, HttpPerformanceSample $sample, float $ms, int $status, int $until, float $rate): void
    {
        // 只允许代码中的控制器名称，绝不使用 URI、路由参数、查询参数或认证数据。
        $action = $request->route()?->getActionName() ?? '';
        if (!preg_match('/\AApp\\\\Http\\\\Controllers\\\\[A-Za-z0-9_\\\\]+@[A-Za-z0-9_]+\z/', $action)) {
            $action = 'other';
        }
        $group = str_contains($action, '\\Server\\') || str_contains($action, '\\Machine\\') ? 'node'
            : (str_contains($action, '\\Admin\\') ? 'admin' : 'business');
        $file = null;
        try {
            $file = @fopen(storage_path('logs/http-performance.json'), 'c+');
            if ($file === false || !flock($file, LOCK_EX | LOCK_NB)) return;
            @chmod(storage_path('logs/http-performance.json'), 0600);
            $contents = stream_get_contents($file, 1048577);
            $data = strlen($contents) <= 1048576 ? json_decode($contents, true) : null;
            if (!is_array($data) || ($data['until'] ?? null) !== $until || ($data['sample_rate'] ?? null) != $rate) {
                $data = ['started_at' => time(), 'until' => $until, 'sample_rate' => $rate, 'routes' => []];
            }
            if (!isset($data['routes'][$action]) && count($data['routes']) >= 128) $action = 'other';
            $row = $data['routes'][$action] ?? ['group' => $group, 'count' => 0, 'total_ms' => 0, 'max_ms' => 0,
                'db_count' => 0, 'db_ms' => 0, 'redis_count' => 0, 'redis_ms' => 0,
                'cache_reads' => 0, 'cache_writes' => 0, 'errors' => 0];
            $row['count']++;
            $row['total_ms'] += round($ms, 3);
            $row['max_ms'] = max($row['max_ms'], round($ms, 3));
            $row['db_count'] += $sample->dbCount;
            $row['db_ms'] += $sample->dbMs;
            $row['redis_count'] += $sample->redisCount;
            $row['redis_ms'] += $sample->redisMs;
            $row['cache_reads'] += $sample->cacheReads;
            $row['cache_writes'] += $sample->cacheWrites;
            $row['errors'] += $status >= 400 ? 1 : 0;
            $data['routes'][$action] = $row;
            $data['updated_at'] = time();
            $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
            rewind($file);
            fwrite($file, $json);
            ftruncate($file, strlen($json));
        } catch (\Throwable) {
            // 诊断失败不能改变请求响应，也不能将异常中的请求内容写入日志。
        } finally {
            if (is_resource($file)) fclose($file);
        }
    }
}
