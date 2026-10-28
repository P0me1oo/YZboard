<?php

namespace App\Logging;

use Illuminate\Log\Logger;

class ApplyLogSettings
{
    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();
        $monolog->setHandlers(array_map(fn ($handler) => new SystemLogHandler($handler), $monolog->getHandlers()));
    }
}
