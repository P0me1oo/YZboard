<?php

namespace Tests\Unit\Listeners;

use App\Listeners\DisconnectWorkerDatabases;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Application;
use Laravel\Octane\Events\WorkerStopping;
use Tests\TestCase;

class DisconnectWorkerDatabasesTest extends TestCase
{
    public function test_stopping_event_disconnects_existing_read_and_write_connections(): void
    {
        $this->assertContains(DisconnectWorkerDatabases::class, config('octane.listeners.' . WorkerStopping::class));
        $connection = new Connection(new \PDO('sqlite::memory:'));
        $connection->setReadPdo(new \PDO('sqlite::memory:'));
        $manager = \Mockery::mock(DatabaseManager::class);
        $manager->shouldReceive('getConnections')->twice()->andReturn(['mysql' => $connection]);
        $app = new Application();
        $app->instance('db', $manager);
        $event = new WorkerStopping($app);
        $listener = new DisconnectWorkerDatabases();
        $listener->handle($event);
        $listener->handle($event);
        $this->assertNull($connection->getRawPdo());
        $this->assertNull($connection->getRawReadPdo());
    }

    public function test_unused_database_is_not_resolved_during_shutdown(): void
    {
        $app = new Application();
        $app->bind('db', function () { throw new \RuntimeException('must not open a database'); });
        (new DisconnectWorkerDatabases())->handle(new WorkerStopping($app));
        $this->assertFalse($app->resolved('db'));
    }

    public function test_failure_in_one_connection_does_not_skip_remaining_connections_or_use_application_logger(): void
    {
        $broken = \Mockery::mock(Connection::class);
        $broken->shouldReceive('disconnect')->once()->andThrow(new \RuntimeException('private connection details'));
        $healthy = \Mockery::mock(Connection::class);
        $healthy->shouldReceive('disconnect')->once();
        $manager = \Mockery::mock(DatabaseManager::class);
        $manager->shouldReceive('getConnections')->once()->andReturn([$broken, $healthy]);
        $app = new Application();
        $app->instance('db', $manager);
        $app->bind('log', function () { throw new \RuntimeException('must not resolve application logger'); });
        $file = tempnam(sys_get_temp_dir(), 'yz-disconnect-');
        $previous = ini_set('error_log', $file);
        try {
            (new DisconnectWorkerDatabases())->handle(new WorkerStopping($app));
            $this->assertStringContainsString('RuntimeException', file_get_contents($file));
            $this->assertStringNotContainsString('private connection details', file_get_contents($file));
        } finally {
            ini_set('error_log', $previous);
            unlink($file);
        }
    }
}
