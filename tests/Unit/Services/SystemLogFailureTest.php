<?php

namespace Tests\Unit\Services;

use App\Logging\SystemLogHandler;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Tests\TestCase;

class SystemLogFailureTest extends TestCase
{
    private string $directory;
    private string $previousErrorLog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/yz-system-log-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $this->previousErrorLog = ini_get('error_log');
        ini_set('error_log', $this->directory . '/diagnostic.log');
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousErrorLog);
        if (is_file($this->directory . '/diagnostic.log')) unlink($this->directory . '/diagnostic.log');
        rmdir($this->directory);
        parent::tearDown();
    }

    private function record(): LogRecord
    {
        return new LogRecord(new \DateTimeImmutable(), 'test', Level::Error, 'secret-record');
    }

    public function test_policy_failure_is_bounded_and_never_exports_original_record(): void
    {
        $cache = \Mockery::mock(Repository::class);
        Cache::shouldReceive('store')->with('redis')->andReturn($cache);
        $cache->shouldReceive('rememberForever')->once()->ordered()->andReturn(['log_settings' => ['system' => ['enabled' => true]]]);
        $cache->shouldReceive('rememberForever')->twice()->ordered()->andThrow(new \RuntimeException('secret-policy'));
        $inner = new TestHandler();
        $handler = new SystemLogHandler($inner);
        $handler->handle($this->record());
        $handler->handle($this->record());
        $handler->handle($this->record());
        $this->assertCount(1, $inner->getRecords());
        $text = file_get_contents($this->directory . '/diagnostic.log');
        $this->assertSame(1, substr_count($text, 'stage=policy_read'));
        $this->assertStringNotContainsString('secret-', $text);
    }

    public function test_unknown_or_disabled_policy_stays_silent(): void
    {
        $cache = \Mockery::mock(Repository::class);
        Cache::shouldReceive('store')->with('redis')->andReturn($cache);
        $cache->shouldReceive('rememberForever')->once()->ordered()->andThrow(new \RuntimeException('secret-policy'));
        $cache->shouldReceive('rememberForever')->once()->ordered()->andReturn(['log_settings' => ['system' => ['enabled' => false]]]);
        $cache->shouldReceive('rememberForever')->once()->ordered()->andThrow(new \RuntimeException('secret-policy'));
        $inner = new TestHandler();
        $handler = new SystemLogHandler($inner);
        for ($i = 0; $i < 3; $i++) $handler->handle($this->record());
        $this->assertCount(0, $inner->getRecords());
        $this->assertFileDoesNotExist($this->directory . '/diagnostic.log');
    }

    public function test_log_write_failure_does_not_throw_or_recurse(): void
    {
        $cache = \Mockery::mock(Repository::class);
        Cache::shouldReceive('store')->with('redis')->andReturn($cache);
        $cache->shouldReceive('rememberForever')->andReturn(['log_settings' => ['system' => ['enabled' => true]]]);
        $inner = new class extends TestHandler {
            protected function write(LogRecord $record): void { throw new \RuntimeException('secret-path'); }
        };
        $handler = new SystemLogHandler($inner);
        $this->assertFalse($handler->handle($this->record()));
        $this->assertFalse($handler->handle($this->record()));
        $text = file_get_contents($this->directory . '/diagnostic.log');
        $this->assertSame(1, substr_count($text, 'stage=write'));
        $this->assertStringNotContainsString('secret-', $text);
    }
}
