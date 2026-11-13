<?php

namespace App\Exceptions;

use App\Helpers\ApiResponse;
use App\Services\Plugin\InterceptResponseException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Support\Arr;
use Illuminate\View\ViewException;
use Throwable;

class Handler extends ExceptionHandler
{
    use ApiResponse;

    /**
     * A list of the exception types that are not reported.
     *
     * @var array<int, class-string<Throwable>>
     */
    protected $dontReport = [
        ApiException::class,
        InterceptResponseException::class
    ];

    /**
     * A list of the inputs that are never flashed for validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'password',
        'password_confirmation',
        'api_key',
    ];

    /**
     * Report or log an exception.
     *
     * @param  \Throwable  $exception
     * @return void
     *
     * @throws \Throwable
     */
    public function report(Throwable $exception)
    {
        // 设备协调已记录带节点归属的锁竞争诊断，不再重复记录成系统故障。
        if ($exception instanceof DeviceCoordinationBusy) return;
        // 参数校验和业务拒绝仍保持安静；显式服务端失败不能被 ApiException/HTTP 排除规则吞掉。
        $code = $exception instanceof ApiException ? (int) substr((string) $exception->getCode(), 0, 3)
            : ($this->isHttpException($exception) ? $exception->getStatusCode() : 0);
        if ($code >= 500 && $code < 600) {
            \App\Services\FailureLog::record('服务端请求处理失败', $exception, ['code' => $code]);
            return;
        }
        parent::report($exception);
    }

    /**
     * Render an exception into an HTTP response.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Throwable  $exception
     * @return \Symfony\Component\HttpFoundation\Response
     *
     * @throws \Throwable
     */
    public function render($request, Throwable $exception)
    {
        if ($exception instanceof ViewException) {
            return $this->fail([500, '主题渲染失败。如更新主题，参数可能发生变化请重新配置主题后再试。']);
        }
        // ApiException主动抛出错误
        if ($exception instanceof ApiException) {
            $code = $exception->getCode();
            $message = $exception->getMessage();
            $errors = $exception->errors();
            return $this->fail([$code, $message],null,$errors);
        }
        return parent::render($request, $exception);
    }

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });

        $this->renderable(function (InterceptResponseException $e) {
            return $e->getResponse();
        });
    }

    protected function convertExceptionToArray(Throwable $e)
    {
        return config('app.debug') ? [
            'message' => $e->getMessage(),
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => collect($e->getTrace())->map(function ($trace) {
                return Arr::except($trace, ['args']);
            })->all(),
        ] : [
            'message' => $this->isHttpException($e) ? $e->getMessage() : __("Uh-oh, we've had some problems, we're working on it."),
        ];
    }
}
