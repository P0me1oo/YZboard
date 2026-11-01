<?php

namespace Tests\Unit\Services;

use App\Services\FrontendHealthChecker;
use PHPUnit\Framework\TestCase;

class FrontendHealthCheckerTest extends TestCase
{
    public function test_only_connected_numeric_addresses_are_healthy(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $code, $error);
        $this->assertIsResource($server);
        $port = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);
        $checker = new FrontendHealthChecker();
        try {
            $this->assertSame(['127.0.0.1'], $checker->reachable(['127.0.0.1', '127.0.0.1', 'invalid.example'], $port));
        } finally { fclose($server); }
        $this->assertSame([], $checker->reachable(['127.0.0.1'], $port));
    }
}
