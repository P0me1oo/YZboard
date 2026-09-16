<?php

namespace App\Http\Controllers\V1\Passport;

use App\Helpers\ResponseEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Passport\AuthForget;
use App\Http\Requests\Passport\AuthLogin;
use App\Http\Requests\Passport\AuthRegister;
use App\Services\Auth\LoginService;
use App\Services\Auth\MailLinkService;
use App\Services\Auth\RegisterService;
use App\Services\Auth\TotpService;
use App\Services\AuthService;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    protected MailLinkService $mailLinkService;
    protected RegisterService $registerService;
    protected LoginService $loginService;
    protected TotpService $totpService;

    public function __construct(
        MailLinkService $mailLinkService,
        RegisterService $registerService,
        LoginService $loginService,
        TotpService $totpService
    ) {
        $this->mailLinkService = $mailLinkService;
        $this->registerService = $registerService;
        $this->loginService = $loginService;
        $this->totpService = $totpService;
    }

    /**
     * 通过邮件链接登录
     */
    public function loginWithMailLink(Request $request)
    {
        $params = $request->validate([
            'email' => 'required|email:strict',
            'redirect' => 'nullable'
        ]);

        [$success, $result] = $this->mailLinkService->handleMailLink(
            $params['email'],
            $request->input('redirect')
        );

        if (!$success) {
            return $this->fail($result);
        }

        return $this->success($result);
    }

    /**
     * 用户注册
     */
    public function register(AuthRegister $request)
    {
        [$success, $result] = $this->registerService->register($request);

        if (!$success) {
            return $this->fail($result);
        }

        $authService = new AuthService($result);
        return $this->success($authService->generateAuthData());
    }

    /**
     * 用户登录
     *
     * 管理员启用两步验证后，密码校验通过不直接发令牌，
     * 而是返回一次性挑战标识，由 loginWithTotp 完成第二步。
     */
    public function login(AuthLogin $request)
    {
        $email = $request->input('email');
        $password = $request->input('password');

        [$success, $result] = $this->loginService->login($email, $password);

        if (!$success) {
            return $this->fail($result);
        }

        if ($this->totpService->isEnabled($result)) {
            return $this->success([
                'totp_required' => true,
                'challenge_id' => $this->totpService->createChallenge($result),
            ]);
        }

        $authService = new AuthService($result);
        return $this->success($authService->generateAuthData());
    }

    /**
     * 两步验证第二步：校验验证码或恢复码，通过后签发登录令牌
     */
    public function loginWithTotp(Request $request)
    {
        $params = $request->validate([
            'challenge_id' => 'required|string',
            'code' => 'required|string'
        ]);

        [$success, $result] = $this->totpService->resolveChallenge(
            $params['challenge_id'],
            $params['code']
        );

        if (!$success) {
            return $this->fail($result);
        }

        $authService = new AuthService($result);
        return $this->success($authService->generateAuthData());
    }

    /**
     * 通过token登录
     */
    public function token2Login(Request $request)
    {
        // 处理直接通过token重定向
        if ($token = $request->input('token')) {
            $redirect = '/#/login?verify=' . $token . '&redirect=' . ($request->input('redirect', 'dashboard'));

            return redirect()->to(
                admin_setting('app_url')
                ? admin_setting('app_url') . $redirect
                : url($redirect)
            );
        }

        // 处理通过验证码登录
        if ($verify = $request->input('verify')) {
            $userId = $this->mailLinkService->handleTokenLogin($verify);

            if (!$userId) {
                return response()->json([
                    'message' => __('Token error')
                ], 400);
            }

            $user = \App\Models\User::find($userId);

            if (!$user) {
                return response()->json([
                    'message' => __('User not found')
                ], 400);
            }

            // 邮件链接和快速登录同样绕过密码，管理员启用两步验证后必须补第二步
            if ($this->totpService->isEnabled($user)) {
                return response()->json([
                    'data' => [
                        'totp_required' => true,
                        'challenge_id' => $this->totpService->createChallenge($user),
                    ]
                ]);
            }

            $authService = new AuthService($user);

            return response()->json([
                'data' => $authService->generateAuthData()
            ]);
        }

        return response()->json([
            'message' => __('Invalid request')
        ], 400);
    }

    /**
     * 获取快速登录URL
     */
    public function getQuickLoginUrl(Request $request)
    {
        $authorization = $request->input('auth_data') ?? $request->header('authorization');

        if (!$authorization) {
            return response()->json([
                'message' => ResponseEnum::CLIENT_HTTP_UNAUTHORIZED
            ], 401);
        }

        $user = AuthService::findUserByBearerToken($authorization);

        if (!$user) {
            return response()->json([
                'message' => ResponseEnum::CLIENT_HTTP_UNAUTHORIZED_EXPIRED
            ], 401);
        }

        $url = $this->loginService->generateQuickLoginUrl($user, $request->input('redirect'));
        return $this->success($url);
    }

    /**
     * 忘记密码处理
     */
    public function forget(AuthForget $request)
    {
        [$success, $result] = $this->loginService->resetPassword(
            $request->input('email'),
            $request->input('email_code'),
            $request->input('password')
        );

        if (!$success) {
            return $this->fail($result);
        }

        return $this->success(true);
    }
}
