<?php

namespace Plugin\Mgate;

use App\Services\Plugin\AbstractPlugin;
use App\Contracts\PaymentInterface;
use App\Exceptions\ApiException;

class Plugin extends AbstractPlugin implements PaymentInterface
{
    public function boot(): void
    {
        $this->filter('available_payment_methods', function ($methods) {
            if ($this->getConfig('enabled', true)) {
                $methods['MGate'] = [
                    'name' => $this->getConfig('display_name', 'MGate'),
                    'icon' => $this->getConfig('icon', '🏛️'),
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
            'mgate_url' => [
                'label' => 'API地址',
                'type' => 'string',
                'required' => true,
                'description' => 'MGate支付网关API地址'
            ],
            'mgate_app_id' => [
                'label' => 'APP ID',
                'type' => 'string',
                'required' => true,
                'description' => 'MGate应用标识符'
            ],
            'mgate_app_secret' => [
                'label' => 'App Secret',
                'type' => 'string',
                'required' => true,
                'description' => 'MGate应用密钥'
            ],
            'mgate_source_currency' => [
                'label' => '源货币',
                'type' => 'string',
                'description' => '默认CNY，源货币类型'
            ]
        ];
    }

    public function pay($order): array
    {
        throw new ApiException('MGate 暂停使用，等待确认结算回调协议', 422);
    }

    public function notify($params): array|bool
    {
        $key = (string) $this->getConfig('mgate_app_secret', '');
        $sign = $params['sign'] ?? null;
        if ($key === '' || !is_string($sign)) return false;
        unset($params['sign']);
        ksort($params);
        reset($params);
        $str = http_build_query($params) . $this->getConfig('mgate_app_secret');

        if (!hash_equals(md5($str), $sign)) {
            return false;
        }

        // 网关协议尚未确认可验证的结算状态、金额和币种，拒绝自动入账。
        return false;
    }
}
