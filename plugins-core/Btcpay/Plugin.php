<?php

namespace Plugin\Btcpay;

use App\Services\Plugin\AbstractPlugin;
use App\Contracts\PaymentInterface;
use App\Exceptions\ApiException;

class Plugin extends AbstractPlugin implements PaymentInterface
{
    public function boot(): void
    {
        $this->filter('available_payment_methods', function($methods) {
            if ($this->getConfig('enabled', true)) {
                $methods['BTCPay'] = [
                    'name' => $this->getConfig('display_name', 'BTCPay'),
                    'icon' => $this->getConfig('icon', '₿'),
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
            'btcpay_url' => [
                'label' => 'API接口所在网址',
                'type' => 'string',
                'required' => true,
                'description' => '包含最后的斜杠，例如：https://your-btcpay.com/'
            ],
            'btcpay_storeId' => [
                'label' => 'Store ID',
                'type' => 'string',
                'required' => true,
                'description' => 'BTCPay商店标识符'
            ],
            'btcpay_api_key' => [
                'label' => 'API KEY',
                'type' => 'string',
                'required' => true,
                'description' => '个人设置中的API KEY(非商店设置中的)'
            ],
            'btcpay_webhook_key' => [
                'label' => 'WEBHOOK KEY',
                'type' => 'string',
                'required' => true,
                'description' => 'Webhook通知密钥'
            ],
        ];
    }

    public function pay($order): array
    {
        if (!$this->getConfig('btcpay_webhook_key') || !$this->getConfig('btcpay_api_key') || !$this->getConfig('btcpay_storeId')) {
            throw new ApiException('BTCPay 需要配置商店编号、API 密钥和通知密钥', 422);
        }
        $params = [
            'jsonResponse' => true,
            'amount' => sprintf('%.2f', $order['total_amount'] / 100),
            'currency' => 'CNY',
            'metadata' => [
                'orderId' => $order['trade_no']
            ]
        ];

        $params_string = @json_encode($params);
        $ret_raw = $this->curlPost(rtrim($this->getConfig('btcpay_url'), '/') . '/api/v1/stores/' . rawurlencode($this->getConfig('btcpay_storeId')) . '/invoices', $params_string);
        $ret = @json_decode($ret_raw, true);

        if (empty($ret['checkoutLink'])) {
            throw new ApiException("error!");
        }
        
        return [
            'type' => 1,
            'data' => $ret['checkoutLink'],
        ];
    }

    public function notify($params): array|bool
    {
        $payload = request()->getContent();
        $key = (string) $this->getConfig('btcpay_webhook_key', '');
        $signature = request()->header('Btcpay-Sig', '');
        if ($key === '' || !hash_equals('sha256=' . hash_hmac('sha256', $payload, $key), $signature)) {
            throw new ApiException('HMAC signature does not match', 400);
        }
        $event = json_decode($payload, true, 64, JSON_THROW_ON_ERROR);
        if (($event['type'] ?? null) !== 'InvoiceSettled') return ['paid' => false];
        $id = $event['invoiceId'] ?? null;
        if (!is_string($id) || !preg_match('/\A[A-Za-z0-9_-]{1,128}\z/', $id)) throw new ApiException('Invalid invoice', 422);
        $apiKey = (string) $this->getConfig('btcpay_api_key', '');
        $store = (string) $this->getConfig('btcpay_storeId', '');
        if ($apiKey === '' || $store === '') throw new ApiException('Payment gateway is not configured', 422);
        $invoice = \Illuminate\Support\Facades\Http::withToken($apiKey, 'token')->connectTimeout(5)->timeout(15)
            ->withOptions(['allow_redirects' => false])
            ->get(rtrim($this->getConfig('btcpay_url'), '/') . '/api/v1/stores/' . rawurlencode($store) . '/invoices/' . rawurlencode($id))
            ->throw()->json();
        if (($invoice['id'] ?? null) !== $id || ($invoice['status'] ?? null) !== 'Settled') throw new ApiException('Invoice is not settled', 422);
        return [
            'paid' => true, 'trade_no' => $invoice['metadata']['orderId'] ?? null, 'callback_no' => $id,
            'amount_cents' => \App\Services\PaymentReceipt::cents($invoice['amount'] ?? null),
            'currency' => $invoice['currency'] ?? null, 'exact_amount' => true,
        ];
    }

    private function curlPost($url, $params = false)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        curl_setopt(
            $ch,
            CURLOPT_HTTPHEADER,
            array('Authorization:' . 'token ' . $this->getConfig('btcpay_api_key'), 'Content-Type: application/json')
        );
        $result = curl_exec($ch);
        curl_close($ch);
        return $result;
    }

}
