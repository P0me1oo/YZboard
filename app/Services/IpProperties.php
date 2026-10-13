<?php

namespace App\Services;

class IpProperties
{
    /** 只接受接口明确返回的判断，缺失、占位或错误类型均保留为未知。 */
    public static function fromResponse(array $body = []): array
    {
        $usage = $body['usage_type'] ?? null;
        $isp = $body['isp'] ?? null;
        $types = ['COM', 'ORG', 'GOV', 'MIL', 'EDU', 'LIB', 'CDN', 'ISP', 'MOB', 'ISP/MOB', 'DCH', 'SES', 'AIC', 'RSV'];
        $result = [
            'isp' => is_string($isp) && !in_array(trim($isp), ['', '-', '未知', '0'], true) ? mb_substr(trim($isp), 0, 200) : null,
            'usage_type' => is_string($usage) && in_array($usage, $types, true) ? $usage : null,
            'is_proxy' => is_bool($body['is_proxy'] ?? null) ? $body['is_proxy'] : null,
            'proxy' => [],
        ];
        $proxy = is_array($body['proxy'] ?? null) ? $body['proxy'] : [];
        foreach (['is_vpn', 'is_tor', 'is_data_center', 'is_public_proxy', 'is_web_proxy',
            'is_web_crawler', 'is_ai_crawler', 'is_residential_proxy', 'is_consumer_privacy_network',
            'is_enterprise_private_network', 'is_spammer', 'is_scanner', 'is_botnet', 'is_bogon'] as $field) {
            $result['proxy'][$field] = is_bool($proxy[$field] ?? null) ? $proxy[$field] : null;
        }
        return $result;
    }

    /** 新来源只覆盖明确给出的字段，保留其他来源已知的真、假判断。 */
    public static function merge(array $base, array $extra): array
    {
        $base = self::fromResponse($base);
        $extra = self::fromResponse($extra);
        foreach (['isp', 'usage_type', 'is_proxy'] as $field) {
            if ($extra[$field] !== null) $base[$field] = $extra[$field];
        }
        foreach ($extra['proxy'] as $field => $value) {
            if ($value !== null) $base['proxy'][$field] = $value;
        }
        return $base;
    }
}
