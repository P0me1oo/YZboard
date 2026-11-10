<?php

namespace App\Services;

use App\Exceptions\PowerDnsRequestException;
use App\Models\BalancerDnsPreset;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

class PowerDnsClient
{
    private const TIMEOUT_SECONDS = 5;
    private const MAX_RETRIES = 3;

    /** $baseline 是首次写入前已完成归属检查的区域快照，只用于安全重试。 */
    public function request(BalancerDnsPreset $preset, string $method, array $data = [], ?array $baseline = null, ?float $deadline = null): array
    {
        $started = microtime(true);
        $deadline ??= $started + 25;
        $requests = 0;
        $checks = 0;
        $uncertain = false;
        $budgetExhausted = false;
        $last = null;
        $method = strtolower($method);
        if (!in_array($method, ['get', 'patch'], true)) {
            throw new PowerDnsRequestException('invalid_method', '不支持该请求方法');
        }

        for ($attempt = 0; $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                if ($attempt) {
                    $delay = max(200 * (2 ** ($attempt - 1)), ($last?->retryAfterSeconds ?? 0) * 1000);
                    $this->remaining($deadline, $delay / 1000);
                    Sleep::for($delay)->milliseconds();
                }
                if ($uncertain) {
                    $checks++;
                    $zone = $this->send($preset, 'get', deadline: $deadline);
                    if ($this->patchApplied($data, $zone['rrsets'])) {
                        $this->diagnostic($preset, $method, 'recovered', $last, $requests, $checks, $started);
                        return [];
                    }
                    // 记录尚未达到目标时，仅允许在原区域快照未变化的情况下重发。
                    if ($baseline === null || $this->snapshot($baseline) !== $this->snapshot($zone['rrsets'])) {
                        throw new PowerDnsRequestException('zone_changed', '写入核对时区域记录已变化，已停止重发，请重新同步并检查记录归属');
                    }
                    $uncertain = false;
                }
                $requests++;
                $uncertain = $method === 'patch';
                $result = $this->send($preset, $method, $data, $deadline);
                if ($last) $this->diagnostic($preset, $method, 'recovered', $last, $requests, $checks, $started);
                return $result;
            } catch (PowerDnsRequestException $error) {
                if ($error->reason === 'deadline' && $last) {
                    $budgetExhausted = true;
                    break;
                }
                $last = $error;
                if (!$error->retryable) break;
            }
        }

        // 最后一次 PATCH 也可能已成功；只核对一次，不再增加写入尝试。
        if ($uncertain && $last->retryable && !$budgetExhausted) {
            try {
                $checks++;
                $zone = $this->send($preset, 'get', deadline: $deadline);
                if ($this->patchApplied($data, $zone['rrsets'])) {
                    $this->diagnostic($preset, $method, 'recovered', $last, $requests, $checks, $started);
                    return [];
                }
                $uncertain = false;
            } catch (PowerDnsRequestException $error) {
                if ($error->reason === 'deadline') $budgetExhausted = true;
                else $last = $error;
            }
        }
        $this->diagnostic($preset, $method, 'failed', $last, $requests, $checks, $started, $budgetExhausted);
        $detail = $last->getMessage() . '（请求 ' . $requests . ' 次' . ($checks ? '，写入核对 ' . $checks . ' 次' : '') . '）';
        if ($budgetExhausted) $detail .= '；本轮重试受处理时限限制，请稍后重试';
        if ($uncertain && ($last->retryable || $last->reason === 'deadline')) $detail .= '；写入结果待确认，后续同步将先核对实际记录';
        // 对外仍只暴露安全异常，不串联原始 Guzzle 异常。
        throw new \App\Exceptions\DdnsSyncException($detail);
    }

    private function remaining(?float $deadline, float $reserve = 0): float
    {
        $remaining = $deadline === null ? self::TIMEOUT_SECONDS : $deadline - microtime(true) - $reserve;
        if ($remaining <= 0.05) throw new PowerDnsRequestException('deadline', '本轮 DNS 同步已达到处理时限，请稍后重试');
        return min(self::TIMEOUT_SECONDS, $remaining);
    }

    private function send(BalancerDnsPreset $preset, string $method, array $data = [], ?float $deadline = null): array
    {
        try {
            $timeout = $this->remaining($deadline);
            $url = rtrim($preset->api_url, '/') . '/api/v1/servers/' . rawurlencode($preset->server_id)
                . '/zones/' . rawurlencode(rtrim($preset->domain, '.') . '.');
            $response = Http::withHeaders(['X-API-Key' => $preset->api_token])->acceptJson()
                ->withOptions(['allow_redirects' => false])->connectTimeout($timeout)
                ->timeout($timeout)->{$method}($url, $data);
            if (!$response->successful()) throw $this->httpFailure($response->status(), $response->header('Retry-After'));
            if ($method === 'patch') return [];
            $zone = $response->json();
            if (!is_array($zone) || !is_string($zone['name'] ?? null) || !is_array($zone['rrsets'] ?? null)) {
                throw new PowerDnsRequestException('invalid_response', '返回内容不是有效的区域数据，请检查 API 地址和反向代理');
            }
            if (strtolower(rtrim($zone['name'], '.')) !== $preset->domain) {
                throw new PowerDnsRequestException('zone_mismatch', '返回区域与配置不一致，请检查服务器 ID 和区域');
            }
            $this->snapshot($zone['rrsets']);
            return $zone;
        } catch (PowerDnsRequestException $error) {
            throw $error;
        } catch (DecryptException) {
            throw new PowerDnsRequestException('credential_decrypt', '保存的密钥无法解密，请检查应用密钥或重新保存 API Key');
        } catch (\Throwable $error) {
            $errno = null;
            for ($cause = $error; $cause; $cause = $cause->getPrevious()) {
                if ($cause instanceof \GuzzleHttp\Exception\ConnectException || $cause instanceof \GuzzleHttp\Exception\RequestException) {
                    $errno = $cause->getHandlerContext()['errno'] ?? null;
                    if (is_int($errno)) break;
                }
            }
            $errno = is_int($errno) ? $errno : null;
            [$reason, $message, $retryable] = match ($errno) {
                6 => ['dns_resolution', 'API 地址解析失败，请检查地址和服务器 DNS', true],
                7 => ['connection', '无法连接 API 服务，请检查地址、端口、防火墙和网络', true],
                28 => ['timeout', '连接或响应超时（单次上限 5 秒），请检查网络和 API 服务', true],
                35 => ['tls_handshake', 'HTTPS 握手失败，请检查证书和 TLS 配置', false],
                51, 60, 77 => ['tls_certificate', 'HTTPS 证书校验失败，请检查证书、域名和系统时间', false],
                52, 55, 56 => ['connection', 'API 连接中断，请检查网络和反向代理', true],
                default => $error instanceof ConnectionException
                    ? ['connection', 'API 连接失败，请检查网络和服务状态', true]
                    : ['client_error', '客户端请求异常，请检查 API 配置和运行环境', false],
            };
            throw new PowerDnsRequestException($reason, $message, $retryable, curlErrno: $errno);
        }
    }

    private function httpFailure(int $status, string $retryAfter): PowerDnsRequestException
    {
        [$reason, $message] = match (true) {
            $status === 401 => ['unauthorized', '密钥认证失败，请检查 API Key'],
            $status === 403 => ['forbidden', '访问被拒绝，请检查来源 IP 白名单和 API 权限'],
            $status === 404 => ['not_found', 'API 路径或区域不存在，请检查基础地址、服务器 ID 和区域'],
            $status === 408 => ['timeout', 'API 服务处理请求超时，请稍后重试'],
            $status === 429 => ['rate_limited', 'API 请求被限流，请稍后重试'],
            in_array($status, [400, 409, 422], true) => ['invalid_records', 'DNS 记录请求被拒绝，请检查记录格式、类型和冲突'],
            $status >= 500 => ['server_error', 'API 服务或上游代理异常，请检查服务状态'],
            $status >= 300 && $status < 400 => ['redirect', 'API 返回重定向，请填写最终基础地址'],
            default => ['http_error', 'API 返回非成功状态，请检查服务配置'],
        };
        $delay = ctype_digit($retryAfter) ? (int) min(86400, (float) $retryAfter)
            : max(0, (strtotime($retryAfter) ?: 0) - time());
        return new PowerDnsRequestException($reason, $message . '（HTTP ' . $status . '）',
            in_array($status, [408, 429, 500, 502, 503, 504], true), $status, retryAfterSeconds: $delay);
    }

    /** 比较记录内容、TTL、停用状态和归属注释，忽略服务端生成的时间与 SOA 序列号。 */
    private function snapshot(array $rrsets, bool $includeSoa = false): array
    {
        $snapshot = [];
        foreach ($rrsets as $rrset) {
            if (!is_array($rrset) || !is_string($rrset['name'] ?? null) || !is_string($rrset['type'] ?? null)
                || !is_numeric($rrset['ttl'] ?? null) || !is_array($rrset['records'] ?? null)
                || !is_array($rrset['comments'] ?? [])) {
                throw new PowerDnsRequestException('invalid_response', '区域记录结构无效，请检查 API 服务版本和反向代理');
            }
            $type = strtoupper($rrset['type']);
            if (!$includeSoa && $type === 'SOA') continue;
            $records = [];
            foreach ($rrset['records'] as $record) {
                if (!is_array($record) || !is_string($record['content'] ?? null)) {
                    throw new PowerDnsRequestException('invalid_response', '区域记录内容无效，请检查 API 服务');
                }
                $content = $record['content'];
                if (in_array($type, ['A', 'AAAA'], true) && filter_var($content, FILTER_VALIDATE_IP)) $content = inet_ntop(inet_pton($content));
                if (in_array($type, ['CNAME', 'NS', 'PTR'], true)) $content = strtolower(rtrim($content, '.')) . '.';
                $records[] = [$content, (bool) ($record['disabled'] ?? false)];
            }
            $comments = [];
            foreach ($rrset['comments'] ?? [] as $comment) {
                if (!is_array($comment) || !is_string($comment['content'] ?? null)) {
                    throw new PowerDnsRequestException('invalid_response', '区域记录注释无效，请检查 API 服务');
                }
                $comments[] = [$comment['content'], (string) ($comment['account'] ?? '')];
            }
            sort($records); sort($comments);
            $snapshot[strtolower(rtrim($rrset['name'], '.')) . '|' . $type] = [(int) $rrset['ttl'], $records, $comments];
        }
        ksort($snapshot);
        return $snapshot;
    }

    private function patchApplied(array $data, array $rrsets): bool
    {
        $actual = $this->snapshot($rrsets, true);
        if (empty($data['rrsets'])) return false;
        foreach ($data['rrsets'] as $action) {
            $key = strtolower(rtrim($action['name'], '.')) . '|' . strtoupper($action['type']);
            if ($action['changetype'] === 'DELETE') {
                if (isset($actual[$key])) return false;
            } elseif ($action['changetype'] === 'REPLACE') {
                if (($actual[$key] ?? null) !== ($this->snapshot([$action], true)[$key] ?? null)) return false;
            } else return false;
        }
        return true;
    }

    private function diagnostic(BalancerDnsPreset $preset, string $method, string $outcome, PowerDnsRequestException $error,
        int $requests, int $checks, float $started, bool $budgetExhausted = false): void
    {
        // 白名单字段不包含 URL、响应正文、记录内容、注释、请求头或原始异常。
        try {
            Log::log($outcome === 'failed' ? 'warning' : 'info',
                ($outcome === 'failed' ? 'PowerDNS 请求失败' : 'PowerDNS 请求重试或核对后恢复')
                    . '：配置 #' . (int) $preset->id . '，' . strtoupper($method) . '，'
                    . substr($error->getMessage(), strlen('PowerDNS API 请求失败：')), [
                    'event' => 'powerdns.' . $outcome, 'preset_id' => (int) $preset->id, 'method' => strtoupper($method),
                    'reason' => $error->reason, 'http_status' => $error->httpStatus, 'curl_errno' => $error->curlErrno,
                    'requests' => $requests, 'verification_requests' => $checks, 'max_retries' => self::MAX_RETRIES,
                    'budget_exhausted' => $budgetExhausted,
                    'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
                ]);
        } catch (\Throwable) {
            // 沿用系统日志开关；日志出口故障不能改变 DNS 写入结果。
        }
    }
}
