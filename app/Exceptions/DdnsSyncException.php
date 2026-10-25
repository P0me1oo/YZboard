<?php

namespace App\Exceptions;

/** 只携带可公开的错误，不保存外部响应、请求或凭据。 */
class DdnsSyncException extends \RuntimeException
{
}
