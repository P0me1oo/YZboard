<?php

namespace Tests\Feature\Server;

use App\Models\BalancerPool;
use App\Services\BalancerDnsService;
use App\Services\DnsPoolService;
use App\Services\FailureLog;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class DnsSyncFailureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        (new \ReflectionProperty(FailureLog::class, 'last'))->setValue(null, []);
    }

    private function pool(array $attributes): BalancerPool
    {
        return BalancerPool::create($attributes + ['gateway_machine_id' => 0, 'gateway_address' => '',
            'gateway_port' => 0, 'backend_port' => 0, 'protocol' => 'vless', 'template_node_id' => 0, 'template_config' => []]);
    }

    public function test_failures_keep_pool_attribution_and_redact_credentials_and_query_values(): void
    {
        $records = [];
        Log::shouldReceive('log')->andReturnUsing(function ($level, $message, $context) use (&$records) {
            $records[] = [$level, $message, $context];
        });
        $failure = new QueryException('mysql', 'update private_table set token=?', ['private-value'],
            new \RuntimeException('duplicate entry private-value'));
        $this->mock(DnsPoolService::class, fn ($mock) => $mock->shouldReceive('sync')->andThrow($failure));
        $ids = [];
        foreach (['a', 'b'] as $name) {
            $pool = $this->pool(['name' => $name, 'domain' => $name . '.example.invalid']);
            $ids[] = $pool->id;
            $this->assertFalse(app(BalancerDnsService::class)->sync($pool));
            $this->assertNull($pool->fresh()->dns_synced_at);
        }
        $this->assertCount(2, $records, '不同前置池不能互相覆盖诊断');
        $this->assertSame($ids, array_column(array_column($records, 2), 'pool_id'));
        foreach ($records as [$level, $message, $context]) {
            $this->assertSame('warning', $level);
            $this->assertSame('dns.sync', $context['event']);
            $this->assertSame(QueryException::class, $context['exception_class']);
            $this->assertArrayHasKey('sql_state', $context);
            $this->assertNotEmpty($context['file']);
        }
        $text = json_encode($records);
        foreach (['private_table', 'private-value', 'update '] as $secret) $this->assertStringNotContainsString($secret, $text);
    }

    public function test_lock_contention_is_identified_and_only_success_marks_recovery(): void
    {
        $pool = $this->pool(['name' => '锁竞争', 'domain' => 'busy.example.invalid', 'dns_synced_at' => now()->subMinute()]);
        $synced = $pool->fresh()->dns_synced_at;
        $this->mock(DnsPoolService::class, function ($mock) {
            $mock->shouldReceive('sync')->once()->andThrow(new LockTimeoutException());
            $mock->shouldReceive('sync')->once()->andReturnNull();
        });
        $this->assertFalse(app(BalancerDnsService::class)->sync($pool));
        $this->assertSame('DNS 同步锁繁忙，稍后自动重试', $pool->fresh()->dns_error);
        $this->assertEquals($synced, $pool->fresh()->dns_synced_at);
        $this->assertDatabaseCount('v2_scheduling_event', 1);
        $this->assertTrue(app(BalancerDnsService::class)->sync($pool->fresh()));
        $this->assertNull($pool->fresh()->dns_error);
        $this->assertDatabaseHas('v2_scheduling_event', ['owner_id' => $pool->id, 'reason' => 'dns_recovered']);
    }

    public function test_dispatch_exception_is_logged_and_failed_command_keeps_the_last_confirmed_state(): void
    {
        $pool = $this->pool(['name' => '调度锁', 'domain' => 'dispatch.example.invalid', 'dns_error' => '原有故障']);
        $this->mock(BalancerDnsService::class, fn ($mock) => $mock->shouldReceive('sync')->once()->andThrow(new LockTimeoutException()));
        Log::shouldReceive('log')->once()->withArgs(fn ($level, $message, $context) =>
            $level === 'warning' && $context['pool_id'] === $pool->id && $context['event'] === 'dns.dispatch'
            && $context['exception_class'] === LockTimeoutException::class);
        $this->artisan('balancer:sync')->assertExitCode(1);
        $this->assertSame('原有故障', $pool->fresh()->dns_error);
        $this->assertNull($pool->fresh()->dns_synced_at);
    }

    public function test_logging_failure_does_not_change_the_dns_failure_result(): void
    {
        $pool = $this->pool(['name' => '日志故障', 'domain' => 'log.example.invalid']);
        $this->mock(DnsPoolService::class, fn ($mock) => $mock->shouldReceive('sync')->andThrow(new \RuntimeException('token=private-value https://private.example/')));
        Log::shouldReceive('log')->andThrow(new \RuntimeException('log unavailable'));
        $this->assertFalse(app(BalancerDnsService::class)->sync($pool));
        $this->assertStringNotContainsString('private', $pool->fresh()->dns_error);
        $this->assertNull($pool->fresh()->dns_synced_at);
    }
}
