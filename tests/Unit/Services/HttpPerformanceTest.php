<?php

namespace Tests\Unit\Services;

use App\Http\Middleware\MeasureHttpPerformance;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HttpPerformanceTest extends TestCase
{
    private string $directory;
    private string $originalStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalStorage = storage_path();
        $this->directory = storage_path('framework/testing/http-performance-' . bin2hex(random_bytes(6)));
        mkdir($this->directory . '/logs', 0700, true);
        $this->app->useStoragePath($this->directory);
    }

    protected function tearDown(): void
    {
        @unlink($this->directory . '/logs/http-performance.json');
        rmdir($this->directory . '/logs');
        rmdir($this->directory);
        $this->app->useStoragePath($this->originalStorage);
        parent::tearDown();
    }

    private function request(): Request
    {
        $request = Request::create('/private/person/123?token=must-never-appear', 'POST', ['body' => 'private-content']);
        $request->headers->set('Authorization', 'Bearer secret-header');
        $action = 'App\\Http\\Controllers\\V2\\Server\\RealtimeController@state';
        $route = new Route('POST', 'private/person/{id}', ['uses' => $action, 'controller' => $action]);
        $request->setRouteResolver(fn () => $route);
        $this->app->instance('request', $request);
        return $request;
    }

    public function test_disabled_expired_and_zero_sample_rate_do_not_write_or_enable_redis_events(): void
    {
        $middleware = new MeasureHttpPerformance();
        foreach ([[false, time() + 60, 1], [true, time() - 1, 1], [true, time() + 60, 0]] as [$enabled, $until, $rate]) {
            config(['http_performance.enabled' => $enabled, 'http_performance.until' => $until, 'http_performance.sample_rate' => $rate]);
            $response = $middleware->handle($this->request(), fn () => response('ok'));
            $this->assertSame('ok', $response->getContent());
        }
        $this->assertFileDoesNotExist(storage_path('logs/http-performance.json'));
        $this->assertFalse((new \ReflectionProperty(app('redis'), 'events'))->getValue(app('redis')));
    }

    public function test_repeated_requests_aggregate_once_and_never_store_request_query_or_cache_contents(): void
    {
        config(['http_performance.enabled' => true, 'http_performance.until' => time() + 60, 'http_performance.sample_rate' => 1]);
        $middleware = new MeasureHttpPerformance();
        for ($i = 0; $i < 3; $i++) {
            $middleware->handle($this->request(), function () {
                DB::select('SELECT 1');
                Cache::get('sensitive-cache-key');
                Cache::put('sensitive-cache-key', 'sensitive-value', 10);
                $connection = \Mockery::mock(\Illuminate\Redis\Connections\Connection::class);
                $connection->shouldReceive('getName')->andReturn('test');
                event(new \Illuminate\Redis\Events\CommandExecuted('get', ['sensitive-cache-key'], 1.5, $connection));
                return response('ok');
            });
            // 模拟后台任务与非采样请求的事件，不能计入上一请求。
            event(new QueryExecuted('private sql', ['private binding'], 10, DB::connection()));
        }
        $json = file_get_contents(storage_path('logs/http-performance.json'));
        $row = array_values(json_decode($json, true)['routes'])[0];
        $this->assertSame('node', $row['group']);
        $this->assertSame(3, $row['count']);
        $this->assertSame(3, $row['db_count']);
        $this->assertSame(3, $row['cache_reads']);
        $this->assertSame(3, $row['cache_writes']);
        $this->assertSame(3, $row['redis_count']);
        $this->assertEquals(4.5, $row['redis_ms']);
        $this->assertGreaterThan(0, $row['total_ms']);
        foreach (['private', 'sensitive', 'token', 'secret-header', 'person', 'body', 'Authorization'] as $text) {
            $this->assertStringNotContainsString($text, $json);
        }
        $this->assertFalse((new \ReflectionProperty(app('redis'), 'events'))->getValue(app('redis')));
    }

    public function test_exception_is_preserved_and_failed_output_does_not_fail_requests(): void
    {
        config(['http_performance.enabled' => true, 'http_performance.until' => time() + 60, 'http_performance.sample_rate' => 1]);
        $middleware = new MeasureHttpPerformance();
        $request = $this->request();
        try {
            $middleware->handle($request, fn () => throw new \RuntimeException('test failure'));
            $this->fail('Must propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('test failure', $e->getMessage());
        }
        $row = array_values(json_decode(file_get_contents(storage_path('logs/http-performance.json')), true)['routes'])[0];
        $this->assertSame(1, $row['errors']);
        $this->assertFalse($request->attributes->has('_http_performance_sample'));
        unlink(storage_path('logs/http-performance.json'));
        rmdir($this->directory . '/logs');
        $this->assertSame('ok', $middleware->handle($this->request(), fn () => response('ok'))->getContent());
        mkdir($this->directory . '/logs');
    }
}
