<?php

namespace App\Exceptions;

/** 本轮时间已用完，已完成的批次保留，下轮重新核对后继续。 */
class DdnsSyncDeferred extends DdnsSyncException
{
    public function __construct()
    {
        parent::__construct('DDNS 本轮同步尚未完成，稍后继续');
    }
}
