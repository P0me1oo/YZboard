<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Symfony\Component\HttpFoundation\IpUtils;

class SourcePolicyIp implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value) || !filter_var($value, FILTER_VALIDATE_IP)
            || IpUtils::checkIp($value, ['0.0.0.0/32', '::/128', '224.0.0.0/4', 'ff00::/8'])) {
            $fail('来源例外必须填写具体的 IPv4 或 IPv6 地址');
        }
    }

    /** 统一等价地址，避免例外列表重复；无效值原样留给校验拒绝。 */
    public static function normalize(mixed $value): mixed
    {
        if (!is_string($value)) return $value;
        $value = trim($value);
        if (!filter_var($value, FILTER_VALIDATE_IP)) return $value;
        $binary = inet_pton($value);
        if (strlen($binary) === 16 && substr($binary, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
            $binary = substr($binary, 12);
        }
        return inet_ntop($binary);
    }
}
