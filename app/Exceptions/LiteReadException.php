<?php

namespace App\Exceptions;

/** 浏览器保持通用提示；诊断由接入层构造，不保留请求、响应或异常链。 */
final class LiteReadException extends ApiException
{
    public function __construct(private readonly array $diagnostics)
    {
        parent::__construct('Lite 读取失败，请检查站点地址、API 密钥、版本和网络。', 502);
    }

    public function diagnostics(): array
    {
        return $this->diagnostics;
    }
}
