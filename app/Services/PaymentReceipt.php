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
        if (array_key_exists('amount_cents', $receipt)) {
            $expected = (int) $order->total_amount + (int) $order->handling_amount;
            if (($receipt['currency'] ?? null) !== 'CNY' || !is_int($receipt['amount_cents'])
                || $expected <= 0 || $receipt['amount_cents'] < $expected) {
                throw new ApiException('Payment amount or currency does not match order', 422);
            }
            if (!empty($receipt['exact_amount']) && $receipt['amount_cents'] !== $expected) {
                throw new ApiException('Payment amount does not match order', 422);
            }
        }
    }
}
