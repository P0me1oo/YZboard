<?php

namespace Tests\Feature\Server;

use App\Http\Controllers\V2\Admin\Server\MachineController;
use App\Models\ServerMachine;
use App\Support\Setting;
use Illuminate\Http\Request;
use Mockery\MockInterface;
use ReflectionMethod;
use Tests\TestCase;

class MachineInstallCommandTest extends TestCase
{
    public function test_machine_install_command_uses_fixed_yzboard_node_release(): void
    {
        $this->mock(Setting::class, function (MockInterface $mock): void {
            $mock->shouldReceive('get')
                ->with('app_url')
                ->andReturn('https://panel.example.com/');
        });

        $machine = new ServerMachine([
            'name' => 'test-machine',
            'token' => 'test-machine-token',
        ]);
        $machine->id = 42;

        $method = new ReflectionMethod(MachineController::class, 'buildInstallCommand');
        $command = $method->invoke(
            new MachineController(),
            Request::create('https://fallback.example.com/api/v2/admin/server/machine/installCommand'),
            $machine
        );

        $expected = sprintf(
            'curl -fsSL https://raw.githubusercontent.com/P0me1oo/YZboard-Node/v1.13-yz.2/install.sh | sudo bash -s -- --mode machine --panel %s --token %s --machine-id 42 --version v1.13-yz.2',
            escapeshellarg('https://panel.example.com'),
            escapeshellarg('test-machine-token')
        );

        $this->assertSame($expected, $command);
    }
}
