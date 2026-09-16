<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Services\Auth\TotpService;
use Illuminate\Http\Request;

/**
 * 管理员两步验证（TOTP）自助管理接口。
 *
 * 所有操作只作用于当前登录的管理员账号，不能代其他账号绑定或关闭。
 */
class TotpController extends Controller
{
    protected TotpService $totpService;

    public function __construct(TotpService $totpService)
    {
        $this->totpService = $totpService;
    }

    /**
     * 查询当前账号的两步验证状态
     */
    public function status(Request $request)
    {
        $user = $request->user();

        return $this->success([
            'enabled' => $this->totpService->isEnabled($user),
            'enabled_at' => $user->totp_enabled_at,
            'recovery_codes_remaining' => $this->totpService->remainingRecoveryCodeCount($user),
        ]);
    }

    /**
     * 开始绑定：生成密钥与二维码，此时尚未启用
     */
    public function setup(Request $request)
    {
        $user = $request->user();

        if ($this->totpService->isEnabled($user)) {
            return $this->fail([400, __('Two-factor authentication is already enabled')]);
        }

        return $this->success($this->totpService->beginSetup($user));
    }

    /**
     * 确认绑定：验证一次动态码后启用，并返回一次性恢复码
     */
    public function confirm(Request $request)
    {
        $params = $request->validate([
            'code' => 'required|string'
        ]);

        [$success, $result] = $this->totpService->confirmSetup($request->user(), $params['code']);

        if (!$success) {
            return $this->fail([400, $result]);
        }

        return $this->success(['recovery_codes' => $result]);
    }

    /**
     * 关闭两步验证，需要提供当前验证码或恢复码
     */
    public function disable(Request $request)
    {
        $params = $request->validate([
            'code' => 'required|string'
        ]);

        [$success, $result] = $this->totpService->disable($request->user(), $params['code']);

        if (!$success) {
            return $this->fail([400, $result]);
        }

        return $this->success(true);
    }

    /**
     * 重新生成恢复码，旧恢复码立即作废
     */
    public function regenerateRecoveryCodes(Request $request)
    {
        $params = $request->validate([
            'code' => 'required|string'
        ]);

        [$success, $result] = $this->totpService->regenerateRecoveryCodes(
            $request->user(),
            $params['code']
        );

        if (!$success) {
            return $this->fail([400, $result]);
        }

        return $this->success(['recovery_codes' => $result]);
    }
}
