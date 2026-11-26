<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Exceptions\LiteReadException;
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
        $started = hrtime(true);
        $details = ['rpc_method' => $method, 'failure_kind' => 'configuration'];
        try {
            $url = $this->normalizeUrl($connection->url);
            $host = parse_url($url, PHP_URL_HOST);
            $port = parse_url($url, PHP_URL_PORT) ?: 443;
            $ips = $this->resolve($host);
            $ip = str_contains($ips[0], ':') ? '[' . $ips[0] . ']' : $ips[0];
            $details['failure_kind'] = 'transport';
            $response = Http::withToken($connection->api_key)->acceptJson()->connectTimeout(3)->timeout(10)
                ->withOptions(['allow_redirects' => false, 'proxy' => '',
                    'on_stats' => static function (\GuzzleHttp\TransferStats $stats) use (&$details): void {
                        $data = $stats->getHandlerStats();
                        foreach (['namelookup_time' => 'dns_ms', 'connect_time' => 'connect_at_ms',
                            'appconnect_time' => 'tls_at_ms', 'starttransfer_time' => 'first_byte_at_ms'] as $from => $to) {
                            if (isset($data[$from]) && is_numeric($data[$from])) $details[$to] = round(max(0, (float) $data[$from]) * 1000, 3);
                        }
                        if (is_int($stats->getHandlerErrorData())) $details['curl_errno'] = $stats->getHandlerErrorData();
                    },
                    'curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"]]])
                ->post($url . '/api/rpc2', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => (object) $params]);
            $details['http_status'] = $response->status();
            $details['failure_kind'] = 'http';
            if (!$response->successful()) throw new \RuntimeException();
            $details['failure_kind'] = 'response_size';
            if (strlen($response->body()) > 8 * 1024 * 1024) throw new \RuntimeException();
            $details['failure_kind'] = 'protocol';
            $body = $response->json();
            if (!is_array($body) || ($body['jsonrpc'] ?? null) !== '2.0' || ($body['id'] ?? null) !== 1
                || (isset($body['error']) && !is_array($body['error']))) throw new \RuntimeException();
            if (isset($body['error'])) {
                $details['failure_kind'] = 'rpc';
                if (is_int($body['error']['code'] ?? null)) $details['rpc_code'] = $body['error']['code'];
                if (is_string($body['error']['message'] ?? null)) {
                    $details['rpc_reason'] = $this->safeReason($body['error']['message'], $connection);
                }
                throw new \RuntimeException();
            }
            if (!is_array($body['result'] ?? null)) throw new \RuntimeException();
            return $body['result'];
        } catch (\Throwable $error) {
            $details['elapsed_ms'] = round((hrtime(true) - $started) / 1e6, 3);
            $details['cause_class'] = get_class($error);
            if ($details['failure_kind'] === 'transport') {
                $cause = $error;
                for ($depth = 0; $cause !== null && $depth < 8; $depth++, $cause = $cause->getPrevious()) {
                    if ($cause instanceof \GuzzleHttp\Exception\ConnectException || $cause instanceof \GuzzleHttp\Exception\RequestException) {
                        $handler = $cause->getHandlerContext();
                        if (is_int($handler['errno'] ?? null)) $details['curl_errno'] = $handler['errno'];
                    }
                }
                $details['transport_reason'] = match ($details['curl_errno'] ?? null) {
                    5, 6 => 'dns_failed', 7 => 'connect_failed', 28 => 'timeout',
                    35, 51, 58, 60, 77, 83 => 'tls_failed', default => 'request_failed',
                };
            } elseif ($details['failure_kind'] === 'configuration') {
                $details['configuration_reason'] = $this->safeReason($error->getMessage(), $connection);
            }
            $failure = new LiteReadException($details);
            FailureLog::record('Lite 上游读取失败', $failure);
            throw $failure;
        }
    }

    private function safeReason(string $message, LiteConnection $connection): string
    {
        foreach ([$connection->api_key, $connection->url] as $secret) {
            if (is_string($secret) && $secret !== '') $message = str_replace($secret, '[已隐藏]', $message);
        }
        $message = preg_replace('~https?://[^\s<>"\x27]+~i', '[地址已隐藏]', $message);
        $message = preg_replace('/\b(?:Bearer|Basic)\s+[^\s,;]+/i', '[认证已隐藏]', $message);
        $message = preg_replace('/\b(?:password|token|secret|authorization|cookie|api[_-]?key)\b["\x27\s]*[:=]["\x27\s]*[^\s,;"\x27}]+/i', '[凭据已隐藏]', $message);
        return mb_substr(LogSanitizer::text($message), 0, 512);
    }
}
