<?php

namespace Tests\Feature\Server;

use App\Http\Middleware\MeasureNodeRequest;
use App\Models\ServerMachine;
use App\Services\FailureLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
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
                $level === 'warning' && $context['machine_id'] === $machine->id
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
}
