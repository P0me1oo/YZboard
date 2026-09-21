<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

class TrustProxies extends Middleware
{
    /**
     * 可信代理列表
     * @var array<int, string>|string|null
     */
    protected $proxies = [
        "173.245.48.0/20",
        "103.21.244.0/22",
        "103.22.200.0/22",
        "103.31.4.0/22",
        "141.101.64.0/18",
        "108.162.192.0/18",
        "190.93.240.0/20",
        "188.114.96.0/20",
        "197.234.240.0/22",
        "198.41.128.0/17",
        "162.158.0.0/15",
        "104.16.0.0/13",
        "104.24.0.0/14",
        "172.64.0.0/13",
        "131.0.72.0/22",
        "10.0.0.0/8",
        "172.16.0.0/12",
        "192.168.0.0/16",
        "169.254.0.0/16",
        "127.0.0.0/8",
    ];

    /**
     * 代理头映射
     * @var int
     */
    protected $headers =
    Request::HEADER_X_FORWARDED_FOR |
    Request::HEADER_X_FORWARDED_HOST |
    Request::HEADER_X_FORWARDED_PORT |
    Request::HEADER_X_FORWARDED_PROTO |
    Request::HEADER_X_FORWARDED_AWS_ELB;

    /**
     * 在内置列表基础上追加环境变量 TRUSTED_PROXIES 指定的代理地址。
     *
     * 登录限流和密码错误计数都按真实客户端 IP 区分来源。面板前面如果还有一层
     * 既不是 Cloudflare 也不在内网网段的反向代理，它转发的请求会被当成同一个来源，
     * 所有用户共用一份限流额度。这种部署需要把该代理的地址加进来。
     *
     * 只接受具体 IP 或 CIDR，不接受 "*"：信任任意来源意味着任何人都能伪造
     * X-Forwarded-For 绕过按 IP 的限制。
     */
    protected function proxies()
    {
        $extra = array_values(array_filter(array_map(
            static fn(string $item): string => trim($item),
            explode(',', (string) env('TRUSTED_PROXIES', ''))
        ), static fn(string $item): bool => $item !== '' && $item !== '*'));

        if (empty($extra)) {
            return parent::proxies();
        }

        return array_values(array_unique(array_merge((array) parent::proxies(), $extra)));
    }
}
