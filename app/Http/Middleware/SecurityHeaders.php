<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * 下发浏览器侧安全响应头。
 *
 * 管理端和用户前台的策略强度不同：
 * - 管理端页面由本仓库自己的 Blade 渲染，内联脚本可以挂 nonce，所以使用完整 CSP；
 * - 用户前台会加载管理员上传的主题，主题里可能有任意内联脚本，
 *   对它只下发不限制脚本来源的基础策略，避免升级后主题直接白屏。
 */
class SecurityHeaders
{
    /** CSP 中受限制的资源指令，只对管理端页面下发 */
    private const STRICT_DIRECTIVES = [
        "default-src 'self'",
        "style-src 'self' 'unsafe-inline'",
        "img-src 'self' data: blob: https:",
        "font-src 'self' data:",
        "connect-src 'self' ws: wss:",
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $enabled = (bool) config('security.headers.enable', true);

        // nonce 必须在视图渲染前生成并共享，否则 Blade 里取不到
        $nonce = null;
        if ($enabled) {
            $nonce = base64_encode(random_bytes(16));
            $request->attributes->set('csp_nonce', $nonce);
            View::share('cspNonce', $nonce);
        }

        $response = $next($request);

        if (!$enabled) {
            return $response;
        }

        $this->applyBaseHeaders($request, $response);
        $this->applyContentSecurityPolicy($request, $response, $nonce);

        return $response;
    }

    /**
     * 与内容类型无关的基础安全头，全部请求都下发。
     */
    private function applyBaseHeaders(Request $request, Response $response): void
    {
        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');

        // 现代浏览器已移除 XSS 审计器，显式关掉避免旧实现引入的副作用
        $headers->set('X-XSS-Protection', '0');

        if ($referrerPolicy = (string) config('security.headers.referrer_policy', '')) {
            $headers->set('Referrer-Policy', $referrerPolicy);
        }

        if ($frameOption = $this->frameOptionValue()) {
            $headers->set('X-Frame-Options', $frameOption);
        }

        // HSTS 只在请求本身走 HTTPS 时下发，避免反代未配置 TLS 时把站点锁死
        $maxAge = (int) config('security.headers.hsts_max_age', 0);
        if ($maxAge > 0 && $request->secure()) {
            $value = "max-age={$maxAge}";
            if ((bool) config('security.headers.hsts_include_subdomains', false)) {
                $value .= '; includeSubDomains';
            }
            $headers->set('Strict-Transport-Security', $value);
        }
    }

    /**
     * 下发 CSP。API 返回的是 JSON，CSP 在那里不起作用，直接跳过。
     */
    private function applyContentSecurityPolicy(Request $request, Response $response, ?string $nonce): void
    {
        if (!(bool) config('security.headers.csp_enable', true)) {
            return;
        }

        $contentType = (string) $response->headers->get('Content-Type', '');
        if (!str_contains($contentType, 'text/html')) {
            return;
        }

        $frameAncestors = (string) config('security.headers.frame_ancestors', "'none'");

        $directives = [
            "object-src 'none'",
            "base-uri 'self'",
            "frame-ancestors {$frameAncestors}",
        ];

        if ($nonce !== null && $this->isAdminPage($request)) {
            $directives = array_merge(
                self::STRICT_DIRECTIVES,
                ["script-src 'self' 'nonce-{$nonce}'", "form-action 'self'"],
                $directives
            );
        }

        $header = (bool) config('security.headers.csp_report_only', false)
            ? 'Content-Security-Policy-Report-Only'
            : 'Content-Security-Policy';

        $response->headers->set($header, implode('; ', $directives));
    }

    /**
     * 判断当前请求是否是管理端入口页面。
     */
    private function isAdminPage(Request $request): bool
    {
        $securePath = (string) admin_setting(
            'secure_path',
            admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))
        );

        if ($securePath === '') {
            return false;
        }

        return trim($request->path(), '/') === trim($securePath, '/');
    }

    /**
     * 把 frame-ancestors 配置换算成等价的 X-Frame-Options，兼容旧浏览器。
     *
     * 只有 none 和 self 能准确换算，其余取值（如具体的来源白名单）
     * 没有等价的 X-Frame-Options 写法，此时不下发该头，由 CSP 单独生效。
     */
    private function frameOptionValue(): ?string
    {
        $frameAncestors = trim((string) config('security.headers.frame_ancestors', "'none'"));

        return match ($frameAncestors) {
            "'none'", 'none' => 'DENY',
            "'self'", 'self' => 'SAMEORIGIN',
            default => null,
        };
    }
}
