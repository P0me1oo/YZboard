<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class RealityDestination implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // 只校验地址格式；本机和内网回源是合法配置，不套用公网扫描限制。
        if (!is_string($value)
            || !preg_match('/^(?:\[([0-9a-fA-F:.]+)\]|([^:\s\/?#@]+)):(\d{1,5})$/D', $value, $parts)
            || (int) $parts[3] < 1 || (int) $parts[3] > 65535
            || ($parts[1] !== ''
                ? !filter_var($parts[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
                : !filter_var($parts[2], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME))) {
            $fail('回源地址请填写域名或 IP 加端口，例如 127.0.0.1:8443');
        }
    }
}
