<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Order;

class PaymentReceipt
{
    /** 金额按十进制字符串转换为分，不用浮点数比较支付金额。 */
    public static function cents(mixed $amount): int
    {
        if (!is_string($amount) && !is_int($amount)) {
            throw new ApiException('Invalid payment amount', 422);
        }
        if (!preg_match('/\A(0|[1-9][0-9]{0,12})(?:\.([0-9]{1,2}))?\z/', (string) $amount, $parts)) {
            throw new ApiException('Invalid payment amount', 422);
        }
        return (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '', 2, '0');
    }

    public static function validate(Order $order, array $receipt): void
    {
        if ((int) $order->payment_id !== (int) ($receipt['payment_id'] ?? 0) || !$order->payment_id) {
            throw new ApiException('Payment method does not match order', 422);
        }
        $expected = (int) $order->total_amount + (int) $order->handling_amount;
        $amount = $receipt['amount_cents'] ?? null;
        if (($receipt['paid'] ?? null) !== true || ($receipt['currency'] ?? null) !== 'CNY'
            || !is_int($amount) || $expected <= 0 || $amount < $expected
            || (!empty($receipt['exact_amount']) && $amount !== $expected)) {
            throw new ApiException('Payment settlement, amount or currency does not match order', 422);
        }
    }
}
