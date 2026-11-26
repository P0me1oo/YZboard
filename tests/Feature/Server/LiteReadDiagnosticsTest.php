<?php

namespace Tests\Feature\Server;

use App\Exceptions\LiteReadException;
use App\Models\LiteConnection;
use App\Services\FailureLog;
use App\Services\LiteClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LiteReadDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    public static function failures(): array
    {
        return ['upstream unavailable' => [503, [], 'http'],
            'rpc error with HTTP 200' => [200, ['jsonrpc' => '2.0', 'id' => 1, 'error' => ['code' => -32603, 'message' => 'database unavailable']], 'rpc'],
            'invalid JSON' => [200, 'not json', 'protocol'],
            'wrong request id' => [200, ['jsonrpc' => '2.0', 'id' => 2, 'result' => []], 'protocol']];
    }

    #[DataProvider('failures')]
    public function test_failure_keeps_safe_diagnostics_with_generic_api_response(int $status, mixed $body, string $kind): void
    {
        (new \ReflectionProperty(FailureLog::class, 'last'))->setValue(null, []);
        Log::spy();
        Http::preventStrayRequests();
        Http::fake(fn () => Http::response($body, $status));
        $client = new class extends LiteClient { public function resolve(string $host): array { return ['8.8.8.8']; } };
        $connection = new LiteConnection(['url' => 'https://lite.example.test', 'api_key' => str_repeat('x', 24)]);
        try { $client->read($connection, 'admin:listClients'); $this->fail('failure not thrown'); }
        catch (LiteReadException $error) {
            $d = $error->diagnostics();
            $this->assertSame($kind, $d['failure_kind']);
            $this->assertSame($status, $d['http_status']);
            $this->assertSame('admin:listClients', $d['rpc_method']);
            $this->assertArrayHasKey('elapsed_ms', $d);
            $this->assertNull($error->getPrevious());
            $this->assertSame(502, $error->getCode());
            if ($kind === 'rpc') $this->assertSame(-32603, $d['rpc_code']);
            Log::shouldHaveReceived('log')->once()->withArgs(fn ($level, $operation, $context) =>
                $operation === 'Lite 上游读取失败' && $context['failure_kind'] === $kind && $context['rpc_method'] === 'admin:listClients');
        }
    }

    public function test_rpc_reason_does_not_copy_credentials_or_error_data(): void
    {
        Log::spy();
        $credential = str_repeat('q', 24);
        Http::fake(fn () => Http::response(['jsonrpc' => '2.0', 'id' => 1,
            'error' => ['code' => -32603, 'message' => 'failed token='.$credential.' https://private.example/path', 'data' => ['secret' => $credential]]], 200));
        $client = new class extends LiteClient { public function resolve(string $host): array { return ['8.8.8.8']; } };
        try { $client->read(new LiteConnection(['url' => 'https://lite.example.test', 'api_key' => $credential]), 'common:getNodes'); $this->fail(); }
        catch (LiteReadException $error) {
            $text = json_encode($error->diagnostics());
            $this->assertStringNotContainsString($credential, $text);
            $this->assertStringNotContainsString('private.example', $text);
            $this->assertArrayNotHasKey('data', $error->diagnostics());
        }
    }

    public function test_transport_exception_preserves_errno_without_request_url(): void
    {
        Log::spy();
        Http::fake(function () {
            $previous = new \GuzzleHttp\Exception\ConnectException('sensitive upstream text',
                new \GuzzleHttp\Psr7\Request('POST', 'https://private.example'), null, ['errno' => 28]);
            throw new \Illuminate\Http\Client\ConnectionException('connection failed', 0, $previous);
        });
        $client = new class extends LiteClient { public function resolve(string $host): array { return ['8.8.8.8']; } };
        try { $client->read(new LiteConnection(['url' => 'https://lite.example.test', 'api_key' => str_repeat('x', 24)]), 'common:getRecords'); $this->fail(); }
        catch (LiteReadException $error) {
            $this->assertSame('timeout', $error->diagnostics()['transport_reason']);
            $this->assertSame(28, $error->diagnostics()['curl_errno']);
            $this->assertStringNotContainsString('private.example', json_encode($error->diagnostics()));
            $this->assertNull($error->getPrevious());
        }
    }
}
