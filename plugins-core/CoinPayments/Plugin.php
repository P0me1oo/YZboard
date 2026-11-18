<?php

namespace Plugin\CoinPayments;

use App\Services\Plugin\AbstractPlugin;
use App\Contracts\PaymentInterface;
use App\Exceptions\ApiException;

class Plugin extends AbstractPlugin implements PaymentInterface
{
    public function boot(): void
    {
        $this->filter('available_payment_methods', function($methods) {
            if ($this->getConfig('enabled', true)) {
                $methods['CoinPayments'] = [
                    'name' => $this->getConfig('display_name', 'CoinPayments'),
                    'icon' => $this->getConfig('icon', '💰'),
                    'plugin_code' => $this->getPluginCode(),
                    'type' => 'plugin'
                ];
            }
            return $methods;
        });
    }

    public function form(): array
    {
        return [
            'coinpayments_merchant_id' => [
                'label' => 'Merchant ID',
                'type' => 'string',
                'required' => true,
                'description' => '商户 ID，填写您在 Account Settings 中得到的 ID'
            ],
            'coinpayments_ipn_secret' => [
                'label' => 'IPN Secret',
                'type' => 'string',
                'required' => true,
                'description' => '通知密钥，填写您在 Merchant Settings 中自行设置的值'
            ],
            'coinpayments_currency' => [
                'label' => '货币代码',
                'type' => 'string',
                'required' => true,
                'description' => '填写您的货币代码（大写），建议与 Merchant Settings 中的值相同'
            ]
        ];
    }

    public function pay($order): array
    {
        if ($this->getConfig('coinpayments_currency') !== 'CNY' || !$this->getConfig('coinpayments_ipn_secret') || !$this->getConfig('coinpayments_merchant_id')) {
            throw new ApiException('CoinPayments 需要配置人民币、商户编号和通知密钥', 422);
        }
        $parseUrl = parse_url($order['return_url']);
        $port = isset($parseUrl['port']) ? ":{$parseUrl['port']}" : '';
        $successUrl = "{$parseUrl['scheme']}://{$parseUrl['host']}{$port}";

        $params = [
            'cmd' => '_pay_simple',
            'reset' => 1,
            'merchant' => $this->getConfig('coinpayments_merchant_id'),
            'item_name' => $order['trade_no'],
            'item_number' => $order['trade_no'],
            'want_shipping' => 0,
            'currency' => $this->getConfig('coinpayments_currency'),
            'amountf' => sprintf('%.2f', $order['total_amount'] / 100),
            'success_url' => $successUrl,
            'cancel_url' => $order['return_url'],
            'ipn_url' => $order['notify_url']
        ];

        $params_string = http_build_query($params);

        return [
            'type' => 1,
            'data' => 'https://www.coinpayments.net/index.php?' . $params_string
        ];
    }

    public function notify($params): array|string
    {
        $key = (string) $this->getConfig('coinpayments_ipn_secret', '');
        $merchant = (string) $this->getConfig('coinpayments_merchant_id', '');
        $raw = request()->getContent();
        if ($key === '' || $merchant === '' || !hash_equals(hash_hmac('sha512', $raw, $key), request()->header('Hmac', ''))) {
            throw new ApiException('HMAC signature does not match', 400);
        }
        parse_str($raw, $params);
        if (($params['merchant'] ?? null) !== $merchant || ($params['ipn_mode'] ?? null) !== 'hmac') throw new ApiException('Invalid payment merchant', 422);
        $status = filter_var($params['status'] ?? null, FILTER_VALIDATE_INT);
        if ($status === false) throw new ApiException('Invalid payment status', 422);
        if ($status < 100 && $status !== 2) return 'IPN OK: pending';
        return [
            'paid' => true, 'trade_no' => $params['item_number'] ?? null, 'callback_no' => $params['txn_id'] ?? null,
            'amount_cents' => \App\Services\PaymentReceipt::cents($params['amount1'] ?? null),
            'currency' => $params['currency1'] ?? null, 'custom_result' => 'IPN OK',
        ];
    }
}
