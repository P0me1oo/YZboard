<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\LiteConnection;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\IpUtils;

/** 仅提供固定的读取接口，不接受浏览器指定 RPC 方法。 */
class LiteClient
{
    /** 只允许普通公网单播，排除共享地址、文档地址和地址转换/隧道网段。 */
    public static function isPublicAddress(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP) || IpUtils::isPrivateIp($ip)) return false;
        if (str_contains($ip, ':') && !IpUtils::checkIp($ip, '2000::/3')) return false;
        return !IpUtils::checkIp($ip, ['192.0.0.0/24', '192.88.99.0/24', '224.0.0.0/4',
            '2001::/23', '3fff::/20']);
    }

    public function normalizeUrl(string $url): string
    {
        $url = rtrim(trim($url), '/');
        $parts = parse_url($url);
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user'], $parts['pass']) || isset($parts['user']) || isset($parts['query'])
            || isset($parts['fragment']) || preg_match('/[\s\\\\]/', $url)) {
            throw new ApiException('请输入完整的 Lite HTTPS 站点地址，不包含账号、查询参数或片段。', 422);
        }
        return $url;
    }

    /** 校验全部解析结果并固定连接 IP，防止重定向和 DNS 重绑定泄漏站点密钥。 */
    public function resolve(string $host): array
    {
        $host = trim($host, '[]');
        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : array_merge(
            gethostbynamel($host) ?: [],
            array_column(dns_get_record($host, DNS_AAAA) ?: [], 'ipv6')
        );
        if (!$addresses) throw new ApiException('无法解析 Lite 站点地址。', 422);
        foreach ($addresses as $ip) {
            if (!self::isPublicAddress($ip)) {
                throw new ApiException('Lite 地址必须解析到公网 IP。', 422);
            }
        }
        return array_values(array_unique($addresses));
    }

    public function read(LiteConnection $connection, string $method, array $params = []): array
    {
        if (!in_array($method, ['admin:listClients', 'common:getNodes', 'common:getNodesLatestStatus', 'common:getRecords'], true)) {
            throw new \LogicException('Unsupported Lite read');
        }
        try {
            $url = $this->normalizeUrl($connection->url);
            $host = parse_url($url, PHP_URL_HOST);
            $port = parse_url($url, PHP_URL_PORT) ?: 443;
            $ips = $this->resolve($host);
            $ip = str_contains($ips[0], ':') ? '[' . $ips[0] . ']' : $ips[0];
            $response = Http::withToken($connection->api_key)->acceptJson()->connectTimeout(3)->timeout(10)
                ->withOptions(['allow_redirects' => false, 'proxy' => '',
                    'curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"]]])
                ->post($url . '/api/rpc2', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => (object) $params]);
            if (!$response->successful() || strlen($response->body()) > 8 * 1024 * 1024) throw new \RuntimeException();
            $body = $response->json();
            if (!is_array($body) || ($body['jsonrpc'] ?? null) !== '2.0' || ($body['id'] ?? null) !== 1
                || isset($body['error']) || !is_array($body['result'] ?? null)) throw new \RuntimeException();
            return $body['result'];
        } catch (\Throwable) {
            // 不回传上游响应、请求 URL、异常链或认证头。
            throw new ApiException('Lite 读取失败，请检查站点地址、API 密钥、版本和网络。', 502);
        }
    }
}
