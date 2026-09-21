<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * This namespace is applied to your controller routes.
     *
     * In addition, it is set as the URL generator's root namespace.
     *
     * @var string
     */
    protected $namespace = 'App\Http\Controllers';

    /**
     * Define your route model bindings, pattern filters, etc.
     *
     * @return void
     */
    public function boot()
    {
        // HTTPS scheme is forced per-request via middleware (Octane-safe).
        $this->configureRateLimiting();
        parent::boot();
    }

    /**
     * 定义各类接口的限流策略。
     *
     * 注意不要把限流直接加在 api 中间件组上：节点通信走的是同一组，
     * 整组限流会在节点数量变多或多节点共用出口 IP 时误伤心跳，导致节点掉线。
     *
     * @return void
     */
    protected function configureRateLimiting()
    {
        // 登录：按 IP + 邮箱双键。只按邮箱会让攻击者故意输错密码锁死他人账号，
        // 只按 IP 则同一出口下的正常用户会互相影响。
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinutes(5, 5)
                ->by($this->credentialKey($request))
                ->response($this->tooManyAttempts());
        });

        // 注册：按 IP + 邮箱。注册要提交邮箱验证码，这里挡的是对验证码的穷举；
        // 按 IP 限制注册数量是后台“注册限制”里已有的可配项，不在这里重复硬编码，
        // 否则运营商 NAT 后面的正常用户会互相挤占额度。
        RateLimiter::for('register', function (Request $request) {
            return Limit::perMinutes(60, 10)
                ->by($this->credentialKey($request))
                ->response($this->tooManyAttempts());
        });

        // 找回密码：按 IP + 邮箱。
        RateLimiter::for('forget', function (Request $request) {
            return Limit::perMinutes(60, 3)
                ->by($this->credentialKey($request))
                ->response($this->tooManyAttempts());
        });

        // 发送邮件验证码：按 IP + 邮箱补一层小时上限。
        // 控制器里已有的 60 秒间隔只按邮箱算，换个邮箱就能接着刷，
        // 这里的 IP 维度用来防止刷爆 SMTP 配额。
        RateLimiter::for('email-verify', function (Request $request) {
            return Limit::perMinutes(60, 5)
                ->by($this->credentialKey($request))
                ->response($this->tooManyAttempts());
        });

        // 免密登录入口：按 IP。链接里的凭据是 32 位十六进制随机串，穷举不现实，
        // 这里只是防止被当成压测目标。
        RateLimiter::for('quick-login', function (Request $request) {
            return Limit::perMinute(30)
                ->by($this->clientKey($request))
                ->response($this->tooManyAttempts());
        });

        // 用户端接口：优先按账号计数，未认证时退回 IP。
        RateLimiter::for('user-api', function (Request $request) {
            return Limit::perMinute(60)->by($this->identityKey($request));
        });

        // 管理端接口：管理员批量操作和翻页时请求很密，上限放宽。
        RateLimiter::for('admin-api', function (Request $request) {
            return Limit::perMinute(300)->by($this->identityKey($request));
        });

        // 访客接口：按 IP。这些是前台每次打开页面都会调用的读接口，
        // 同一出口后面往往有很多用户，上限要留足；不包含支付回调和 Telegram Webhook。
        RateLimiter::for('guest-api', function (Request $request) {
            return Limit::perMinute(120)->by($this->clientKey($request));
        });
    }

    /**
     * 限流计数用的客户端标识。
     *
     * TrustProxies 已配置可信代理，反代或 CDN 后面取到的是真实客户端 IP。
     */
    private function clientKey(Request $request): string
    {
        return 'ip:' . ($request->ip() ?: 'unknown');
    }

    /**
     * IP + 邮箱组合键，用于认证类接口。
     */
    private function credentialKey(Request $request): string
    {
        $email = strtolower(trim((string) $request->input('email', '')));

        return $this->clientKey($request) . '|mail:' . sha1($email);
    }

    /**
     * 已登录请求按账号计数，避免同一出口 IP 下的用户互相挤占额度。
     *
     * 这里直接问 sanctum 守卫，不依赖认证中间件的执行顺序；
     * 守卫本身会校验令牌有效期，解析结果在同一请求内有缓存。
     */
    private function identityKey(Request $request): string
    {
        $userId = Auth::guard('sanctum')->user()?->id;

        return $userId ? 'uid:' . $userId : $this->clientKey($request);
    }

    /**
     * 超出限制时返回与其他接口一致的 JSON 结构。
     */
    private function tooManyAttempts(): callable
    {
        return function (Request $request, array $headers) {
            return response()->json([
                'status' => 'fail',
                'message' => __('Too many requests, please try again later'),
                'data' => null,
                'error' => null,
            ], 429, $headers);
        };
    }

    /**
     * Define the routes for the application.
     *
     * @return void
     */
    public function map()
    {
        $this->mapApiRoutes();
        $this->mapWebRoutes();

        //
    }

    /**
     * Define the "web" routes for the application.
     *
     * These routes all receive session state, CSRF protection, etc.
     *
     * @return void
     */
    protected function mapWebRoutes()
    {
        Route::middleware('web')
            ->namespace($this->namespace)
            ->group(base_path('routes/web.php'));
    }

    /**
     * Define the "api" routes for the application.
     *
     * These routes are typically stateless.
     *
     * @return void
     */
    protected function mapApiRoutes()
    {
        Route::group([
            'prefix' => '/api/v1',
            'middleware' => 'api',
            'namespace' => $this->namespace
        ], function ($router) {
            foreach (glob(app_path('Http//Routes//V1') . '/*.php') as $file) {
                $this->app->make('App\\Http\\Routes\\V1\\' . basename($file, '.php'))->map($router);
            }
        });


        Route::group([
            'prefix' => '/api/v2',
            'middleware' => 'api',
            'namespace' => $this->namespace
        ], function ($router) {
            foreach (glob(app_path('Http//Routes//V2') . '/*.php') as $file) {
                $this->app->make('App\\Http\\Routes\\V2\\' . basename($file, '.php'))->map($router);
            }
        });
    }
}
