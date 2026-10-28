<?php

namespace Tests\Unit\Services;

use App\Services\DeviceHandoverService;
use App\Services\DeviceStateService;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class DeviceHandoverConcurrencyTest extends TestCase
{
    public function test_parallel_combined_admissions_preserve_limits_and_deduplicate_sources(): void
    {
        foreach (['different', 'same', 'users', 'cached-different', 'cached-same', 'cached-users'] as $mode) {
            $cached = str_starts_with($mode, 'cached-');
            $kind = $cached ? substr($mode, 7) : $mode;
            $directory = (getenv('YZ_TEST_TEMP_DIR') ?: storage_path('framework/testing')) . '/device-concurrency-' . bin2hex(random_bytes(8));
            mkdir($directory, 0700, true);
            $processes = [];
            $connection = new \PDO('sqlite:' . $directory . '/database.sqlite');
            $connection->exec('CREATE TABLE v2_server (id INTEGER PRIMARY KEY)');
            $connection->exec('INSERT INTO v2_server VALUES (1)');
            $connection = null;
            config(['cache.stores.device_concurrency' => ['driver' => 'file', 'path' => $directory . '/cache', 'lock_path' => $directory . '/locks'],
                'cache.default' => 'device_concurrency']);
            app('cache')->forgetDriver('device_concurrency');
            $devices = new class extends DeviceStateService { public function getDeviceSources(int $userId): array { return []; } };
            (new DeviceHandoverService($devices))->begin(1, str_repeat('a', 32), $cached);
            try {
                foreach (range(0, 7) as $index) {
                    $process = new Process([PHP_BINARY, base_path('tests/Fixtures/device-handover-worker.php'), $directory, (string) $index, $mode],
                        base_path(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $directory . '/database.sqlite', 'CACHE_DRIVER' => 'file'], null, 40);
                    $process->start();
                    $processes[] = $process;
                }
                $deadline = microtime(true) + 25;
                while (count(glob($directory . '/*.ready')) !== 8) {
                    foreach ($processes as $process) {
                        if (!$process->isRunning() && $process->getExitCode() !== 0) $this->fail($process->getErrorOutput() . $process->getOutput());
                    }
                    if (microtime(true) >= $deadline) $this->fail('等待设备并发进程超时');
                    usleep(10000);
                    clearstatcache();
                }
                touch($directory . '/start');
                $grants = [];
                foreach ($processes as $process) {
                    $process->wait();
                    $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());
                    $reply = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
                    $this->assertArrayHasKey('sync', $reply);
                    if ($reply['status'] === 'allowed') $grants[] = $reply['lease'];
                    else $this->assertContains($reply['status'], ['waiting', 'denied']);
                }
                $this->assertCount($kind === 'different' ? 1 : 8, $grants);
                if ($kind === 'same') $this->assertCount(1, array_unique($grants));
                $state = Cache::get('device_handover:node:1');
                $this->assertSame(28, $state['sequence']);
                $this->assertCount(8, $state['pending']);
                if ($kind !== 'users') $this->assertCount(1, Cache::get('device_handover:user:10')['sources']);
                if ($cached) {
                    foreach ($kind === 'users' ? range(1, 8) : [10] as $id) {
                        foreach (Cache::get('device_handover:user:' . $id)['sources'] as $source) {
                            $this->assertCount(1, $source['holders'], '并发申请不能遗漏或重复缓存占位');
                        }
                    }
                }
            } finally {
                foreach ($processes as $process) if ($process->isRunning()) $process->stop();
                config(['cache.default' => 'array']);
                app('cache')->forgetDriver('device_concurrency');
                $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
                foreach ($files as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
                rmdir($directory);
            }
        }
    }
}
