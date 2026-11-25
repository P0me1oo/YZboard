<?php

namespace App\Console\Commands;

use App\Services\RelayFirewallService;
use Illuminate\Console\Command;

class SyncRelayFirewall extends Command
{
    protected $signature = 'relay:sync-firewall';
    protected $description = '重新核对中转落地的前置出口来源';

    public function handle(): int
    {
        RelayFirewallService::refresh();
        return self::SUCCESS;
    }
}
