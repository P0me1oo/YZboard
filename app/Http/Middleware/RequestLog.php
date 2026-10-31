<?php

namespace App\Http\Middleware;

use App\Models\AdminAuditLog;
use App\Services\LogSanitizer;
use Closure;

class RequestLog
{
    public function handle($request, Closure $next)
    {
        if ($request->method() !== 'POST') {
            return $next($request);
        }

        $response = $next($request);

        try {
            if (!\App\Services\LogSettings::enabled('audit')) return $response;
            $admin = $request->user();
            if (!$admin || !$admin->is_admin) {
                return $response;
            }

            $action = $this->resolveAction($request->path());
            $isTelegramBot = $request->route()?->getControllerClass()
                === \App\Http\Controllers\V2\Admin\TelegramBotController::class;
            // 独立机器人只审计操作与配置变更标志，不能记录密钥或任意嵌套输入。
            $data = $isTelegramBot
                ? ['token_updated' => $request->filled('token')]
                : LogSanitizer::audit($request->all());

            AdminAuditLog::insert([
                'admin_id' => $admin->id,
                'action' => $action,
                'method' => $request->method(),
                'uri' => $request->getPathInfo(),
                'request_data' => json_encode($data, JSON_UNESCAPED_UNICODE),
                'ip' => $request->getClientIp(),
                'created_at' => time(),
                'updated_at' => time(),
            ]);
        } catch (\Throwable $e) {
            \Log::warning('管理员操作审计保存失败');
        }

        return $response;
    }

    private function resolveAction(string $path): string
    {
        // api/v2/{secure_path}/user/update → user.update
        $path = preg_replace('#^api/v[12]/[^/]+/#', '', $path);
        // gift-card/create-template → gift_card.create_template
        $path = str_replace('-', '_', $path);
        // user/update → user.update, server/manage/sort → server_manage.sort
        $segments = explode('/', $path);
        $method = array_pop($segments);
        $resource = implode('_', $segments);

        return $resource . '.' . $method;
    }
}

