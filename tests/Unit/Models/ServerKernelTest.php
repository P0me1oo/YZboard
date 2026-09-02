<?php

namespace Tests\Unit\Models;

use App\Models\Server;
use PHPUnit\Framework\TestCase;

class ServerKernelTest extends TestCase
{
    public function test_empty_kernel_uses_xray(): void
    {
        $this->assertSame(Server::KERNEL_XRAY, Server::effectiveKernelType(null));
        $this->assertSame(Server::KERNEL_XRAY, Server::effectiveKernelType(''));
        $this->assertSame(Server::KERNEL_XRAY, Server::effectiveKernelType('unknown'));
    }

    public function test_supported_kernel_names_are_preserved(): void
    {
        $this->assertSame(Server::KERNEL_XRAY, Server::effectiveKernelType('XRAY'));
        $this->assertSame(Server::KERNEL_SINGBOX, Server::effectiveKernelType('sing-box'));
        $this->assertSame(Server::KERNEL_SINGBOX, Server::effectiveKernelType('singbox'));
    }
}
