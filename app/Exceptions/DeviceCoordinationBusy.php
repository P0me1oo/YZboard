<?php

namespace App\Exceptions;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

class DeviceCoordinationBusy extends ServiceUnavailableHttpException
{
    public function __construct(LockTimeoutException $previous)
    {
        parent::__construct(1, '设备协调繁忙，请稍后重试', $previous);
    }
}
