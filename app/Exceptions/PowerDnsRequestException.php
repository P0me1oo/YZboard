<?php

namespace App\Exceptions;

/** 仅携带固定诊断字段，禁止附加含请求头、响应正文的原始异常。 */
class PowerDnsRequestException extends DdnsSyncException
{
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly bool $retryable = false,
        public readonly ?int $httpStatus = null,
        public readonly ?int $curlErrno = null,
        public readonly int $retryAfterSeconds = 0,
    ) {
        parent::__construct('PowerDNS API 请求失败：' . $message);
    }
}
