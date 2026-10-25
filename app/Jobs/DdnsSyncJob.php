<?php

namespace App\Jobs;

use App\Services\DdnsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DdnsSyncJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;
    public $timeout = 60;
    public $uniqueFor = 30;

    public function __construct()
    {
        $this->onQueue('node_sync');
    }

    public function handle(DdnsService $ddns): void
    {
        $ddns->synchronize(20);
    }
}
