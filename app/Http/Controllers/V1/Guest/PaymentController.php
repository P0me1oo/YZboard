<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\OrderService;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Services\Plugin\HookManager;

class PaymentController extends Controller
{
    public function notify($method, $uuid, Request $request)
    {
        HookManager::call('payment.notify.before', [$method, $uuid, $request]);
        try {
            $paymentService = new PaymentService($method, null, $uuid);
            $verify = $paymentService->notify($request->input());
            if (!$verify) {
                HookManager::call('payment.notify.failed', [$method, $uuid, $request]);
                return $this->fail([422, 'verify error']);
            }
            HookManager::call('payment.notify.verified', $verify);
            // 兼容网关的待付款确认文本；合法非付款通知不进入开通流程。
            if (is_string($verify)) return $verify;
            if (!is_array($verify)) return $this->fail([422, 'verify error']);
            if (($verify['paid'] ?? true) === false) return $verify['custom_result'] ?? 'success';
            if (!is_string($verify['trade_no'] ?? null) || $verify['trade_no'] === ''
                || !is_string($verify['callback_no'] ?? null) || $verify['callback_no'] === '') {
                return $this->fail([422, 'invalid payment receipt']);
            }
            if (!$this->handle($verify)) {
                return $this->fail([400, 'handle error']);
            }
            return (isset($verify['custom_result']) ? $verify['custom_result'] : 'success');
        } catch (\Exception $e) {
            Log::warning('支付回调处理失败', ['reason' => \App\Services\LogSanitizer::text($e->getMessage())]);
            if ($e instanceof \App\Exceptions\ApiException) return $this->fail([$e->getCode(), 'invalid payment receipt']);
            return $this->fail([500, 'fail']);
        }
    }

    private function handle(array $receipt): bool
    {
        $order = Order::where('trade_no', $receipt['trade_no'])->first();
        if (!$order) {
            return false;
        }
        $orderService = new OrderService($order);
        if (!$orderService->paid($receipt['callback_no'], true, $receipt)) {
            return false;
        }

        HookManager::call('payment.notify.success', $order);
        return true;
    }
}
