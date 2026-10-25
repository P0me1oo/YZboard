<?php

namespace App\Console\Commands;

use App\Services\DdnsService;
use Illuminate\Console\Command;

class SyncDdns extends Command
{
    protected $signature = 'ddns:sync';
    protected $description = '同步节点连接地址及用户个人域名';

    public function handle(DdnsService $ddns): int
    {
        return $ddns->synchronize() ? self::SUCCESS : self::FAILURE;
    }
}
