<?php

namespace App\Http\Middleware;

use App\Exceptions\DeviceCoordinationBusy;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Services\AgentLogPolicy;
use App\Services\FailureLog;
use Closure;
use Illuminate\Http\Request;

/** Agent 失败日志与源站慢请求通过随机编号关联，不收集请求内容。 */
final class MeasureNodeRequest
{
    public function handle(Request $request, Closure $next)
    {
        $id = $request->header('X-YZ-Request-ID', '');
        if (!is_string($id) || !preg_match('/\A[a-f0-9]{16}\z/', $id) || !$request->is('api/v2/server/*')) {
            return $next($request);
        }
        $started = hrtime(true);
        $status = 500;
        $lockBusy = false;
        try {
            $response = $next($request);
            $status = $response->getStatusCode();
            // Laravel 管道可能已将异常转换成响应，仍需识别原始锁竞争。
            $lockBusy = ($response->exception ?? null) instanceof DeviceCoordinationBusy;
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $error) {
            $status = $error->getStatusCode();
            $lockBusy = $error instanceof DeviceCoordinationBusy;
            throw $error;
        } catch (\App\Exceptions\ApiException $error) {
            $status = (int) substr((string) $error->getCode(), 0, 3);
            throw $error;
        } finally {
            $elapsed = round((hrtime(true) - $started) / 1e6, 3);
            $this->record($request, $id, $elapsed, $status, $lockBusy);
        }
        $response->headers->set('X-YZ-Process-Ms', (string) $elapsed);
        return $response;
    }

    private function record(Request $request, string $id, float $elapsed, int $status, bool $lockBusy): void
    {
        $machine = $request->attributes->get('machine_info');
        $node = $request->attributes->get('node_info');
        $failed = $status >= 500 && !$lockBusy;
        if ((!($machine instanceof ServerMachine) && !($node instanceof Server)) || ($elapsed < 1000 && !$failed)) return;
        try {
            if (AgentLogPolicy::disabled($machine instanceof ServerMachine ? $machine : null, $node instanceof Server ? $node : null)) return;
            $action = $request->route()?->getActionName() ?? '';
            if (!preg_match('/\AApp\\\\Http\\\\Controllers\\\\[A-Za-z0-9_\\\\]+@[A-Za-z0-9_]+\z/', $action)) $action = 'unknown';
            FailureLog::record($failed ? '节点 HTTP 请求处理失败' : '节点 HTTP 请求耗时异常', new \RuntimeException(
                "request_id={$id} handler={$action} elapsed_ms={$elapsed} status={$status}"
            ), array_filter(['node_id' => $node instanceof Server ? (int) $node->id : null,
                'machine_id' => $machine instanceof ServerMachine ? (int) $machine->id : null]), 'warning');
        } catch (\Throwable) {
            // 隐私策略或诊断失败不改变业务响应。
        }
    }
}
