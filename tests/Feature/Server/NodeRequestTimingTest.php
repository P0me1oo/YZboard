<?php

namespace Tests\Feature\Server;

use App\Exceptions\DeviceCoordinationBusy;
use App\Http\Middleware\MeasureNodeRequest;
use App\Models\ServerMachine;
use App\Services\FailureLog;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Tests\TestCase;

class NodeRequestTimingTest extends TestCase
{
    use RefreshDatabase;

    public function test_fast_response_has_timing_without_logging_payload(): void
    {
        Log::spy();
        $request = Request::create('/api/v2/server/realtime/state', 'POST', ['token' => str_repeat('q', 24)]);
        $request->headers->set('X-YZ-Request-ID', '0123456789abcdef');
        $response = (new MeasureNodeRequest())->handle($request, fn () => response()->json(['ok' => true]));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertGreaterThanOrEqual(0, (float) $response->headers->get('X-YZ-Process-Ms'));
        Log::shouldNotHaveReceived('log');
    }

    public static function privacyModes(): array
    {
        return ['recording' => [false], 'private' => [true]];
    }

    #[DataProvider('privacyModes')]
    public function test_authenticated_failure_has_correlation_and_privacy_is_respected(bool $disabled): void
    {
            (new \ReflectionProperty(FailureLog::class, 'last'))->setValue(null, []);
            Log::spy();
            $machine = ServerMachine::create(['name' => 'timing', 'token' => bin2hex(random_bytes(16)), 'agent_log_disabled' => $disabled]);
            $request = Request::create('/api/v2/server/realtime/state', 'POST', ['token' => str_repeat('q', 24)]);
            $request->headers->set('X-YZ-Request-ID', '0123456789abcdef');
            $request->attributes->set('machine_info', $machine);
            try { (new MeasureNodeRequest())->handle($request, fn () => throw new \RuntimeException('business failure')); $this->fail(); }
            catch (\RuntimeException $error) { $this->assertSame('business failure', $error->getMessage()); }
            if ($disabled) Log::shouldNotHaveReceived('log');
            else Log::shouldHaveReceived('log')->once()->withArgs(fn ($level, $operation, $context) =>
                $level === 'warning' && $operation === '节点 HTTP 请求处理失败' && $context['machine_id'] === $machine->id
                && str_contains($context['error'], 'request_id=0123456789abcdef')
                && !str_contains(json_encode($context), str_repeat('q', 24)));
    }

    public function test_invalid_correlation_header_is_not_reflected(): void
    {
        $request = Request::create('/api/v2/server/realtime/state');
        $request->headers->set('X-YZ-Request-ID', 'arbitrary-input');
        $response = (new MeasureNodeRequest())->handle($request, fn () => response()->json([]));
        $this->assertFalse($response->headers->has('X-YZ-Process-Ms'));
    }

    public static function exceptionDelivery(): array
    {
        return ['rendered response' => [true], 'thrown exception' => [false]];
    }

    #[DataProvider('exceptionDelivery')]
    public function test_fast_device_lock_retry_does_not_log_a_request_warning(bool $rendered): void
    {
        $request = $this->authenticatedRequest();
        $error = new DeviceCoordinationBusy(new LockTimeoutException());

        try {
            $response = (new MeasureNodeRequest())->handle($request, fn () => $rendered
                ? response()->json(['message' => $error->getMessage()], 503, $error->getHeaders())->withException($error)
                : throw $error);
            $this->assertTrue($rendered);
            $this->assertSame(503, $response->getStatusCode());
            $this->assertSame('1', $response->headers->get('Retry-After'));
            $this->assertTrue($response->headers->has('X-YZ-Process-Ms'));
        } catch (DeviceCoordinationBusy $caught) {
            $this->assertFalse($rendered);
            $this->assertSame($error, $caught);
        }

        Log::shouldNotHaveReceived('log');
    }

    #[DataProvider('exceptionDelivery')]
    public function test_other_503_with_retry_header_is_logged_as_a_failure(bool $rendered): void
    {
        $request = $this->authenticatedRequest();
        $error = new ServiceUnavailableHttpException(1, 'upstream unavailable');

        try {
            $response = (new MeasureNodeRequest())->handle($request, fn () => $rendered
                ? response()->json(['message' => $error->getMessage()], 503, $error->getHeaders())->withException($error)
                : throw $error);
            $this->assertTrue($rendered);
            $this->assertSame(503, $response->getStatusCode());
            $this->assertSame('1', $response->headers->get('Retry-After'));
        } catch (ServiceUnavailableHttpException $caught) {
            $this->assertFalse($rendered);
            $this->assertSame($error, $caught);
        }

        Log::shouldHaveReceived('log')->withArgs(fn ($level, $operation, $context) =>
            $level === 'warning' && $operation === '节点 HTTP 请求处理失败'
            && str_contains($context['error'], 'status=503')
            && str_contains($context['error'], 'request_id=0123456789abcdef'))->once();
    }

    public static function serverFailureResponses(): array
    {
        return ['500' => [500, false], '503' => [503, false], 'private 500' => [500, true], 'private 503' => [503, true]];
    }

    #[DataProvider('serverFailureResponses')]
    public function test_plain_failure_response_respects_privacy(int $status, bool $disabled): void
    {
        $request = $this->authenticatedRequest($disabled);
        $response = (new MeasureNodeRequest())->handle($request, fn () => response()->json([], $status));

        $this->assertSame($status, $response->getStatusCode());
        if ($disabled) Log::shouldNotHaveReceived('log');
        else Log::shouldHaveReceived('log')->withArgs(fn ($level, $operation, $context) =>
            $level === 'warning' && $operation === '节点 HTTP 请求处理失败'
            && str_contains($context['error'], 'status=' . $status))->once();
    }

    public static function slowResponses(): array
    {
        return ['successful response' => [false], 'device lock retry' => [true]];
    }

    #[DataProvider('slowResponses')]
    public function test_slow_response_keeps_the_timing_warning(bool $busy): void
    {
        $request = $this->authenticatedRequest();
        $response = (new MeasureNodeRequest())->handle($request, function () use ($busy) {
            usleep(1_050_000);
            if (!$busy) return response()->json(['ok' => true]);
            $error = new DeviceCoordinationBusy(new LockTimeoutException());
            return response()->json(['message' => $error->getMessage()], 503, $error->getHeaders())->withException($error);
        });

        $this->assertSame($busy ? 503 : 200, $response->getStatusCode());
        $this->assertGreaterThanOrEqual(1000, (float) $response->headers->get('X-YZ-Process-Ms'));
        Log::shouldHaveReceived('log')->withArgs(fn ($level, $operation, $context) =>
            $level === 'warning' && $operation === '节点 HTTP 请求耗时异常'
            && str_contains($context['error'], 'status=' . ($busy ? 503 : 200)))->once();
    }

    private function authenticatedRequest(bool $disabled = false): Request
    {
        (new \ReflectionProperty(FailureLog::class, 'last'))->setValue(null, []);
        $machine = ServerMachine::create(['name' => 'timing', 'token' => bin2hex(random_bytes(16)), 'agent_log_disabled' => $disabled]);
        $request = Request::create('/api/v2/server/device-handover/sync', 'POST');
        $request->headers->set('X-YZ-Request-ID', '0123456789abcdef');
        $request->attributes->set('machine_info', $machine);
        Log::spy();
        return $request;
    }
}
