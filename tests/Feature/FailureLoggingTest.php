<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Exceptions\Handler;
use App\Http\Controllers\V2\Admin\PaymentController;
use App\Models\Payment;
use App\Models\Ticket;
use App\Services\FailureLog;
use App\Services\TicketService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class FailureLoggingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        (new \ReflectionProperty(FailureLog::class, 'last'))->setValue(null, []);
    }

    public function test_handled_failures_keep_diagnostics_and_remove_credentials_and_query_data(): void
    {
        $records = [];
        Log::shouldReceive('log')->andReturnUsing(function ($level, $message, $context) use (&$records) {
            $records[] = [$level, $message, $context];
        });
        $failure = new \RuntimeException('connect failed token=test-secret url=https://secret.example/');
        for ($i = 0; $i < 5; $i++) FailureLog::record('connect', $failure, ['node_id' => 7, 'request' => 'secret-body']);
        $query = new QueryException('mysql', 'insert secret-table values (?)', ['secret-value'],
            new \RuntimeException('duplicate entry secret-value'));
        FailureLog::record('query', $query);
        $this->assertCount(2, $records);
        $this->assertSame(7, $records[0][2]['node_id']);
        $this->assertSame(\RuntimeException::class, $records[0][2]['exception_class']);
        $this->assertArrayHasKey('file', $records[0][2]);
        $this->assertArrayHasKey('line', $records[0][2]);
        $text = json_encode($records);
        foreach (['test-secret', 'secret.example', 'secret-body', 'secret-value', 'secret-table'] as $secret) {
            $this->assertStringNotContainsString($secret, $text);
        }
        $this->assertArrayHasKey('sql_state', $records[1][2]);
    }

    public function test_server_errors_are_reported_but_expected_api_rejections_are_not(): void
    {
        Log::shouldReceive('log')->twice()->withArgs(fn ($level, $message, $context) =>
            $level === 'error' && $context['code'] === 503);
        $handler = app(Handler::class);
        $handler->report(new ApiException('unavailable', 503));
        $handler->report(new HttpException(503, 'unavailable'));
        $handler->report(new ApiException('invalid', 400));
        $handler->report(new HttpException(403, 'denied'));
        FailureLog::record('expected', new ApiException('missing', 400202));
    }

    public function test_existing_websocket_protocol_warning_keeps_its_event_and_status(): void
    {
        Log::shouldReceive('log')->once()->withArgs(fn ($level, $message, $context) =>
            $level === 'warning' && $context['node_id'] === 7 && $context['code'] === 409
            && $context['event'] === 'runtime.state' && $context['error'] === 'state conflict');
        FailureLog::record('[WS] Node request failed', new HttpException(409, 'state conflict'),
            ['node_id' => 7, 'code' => 409, 'event' => 'runtime.state'], 'warning', includeExpected: true);
    }

    public function test_payment_sort_failure_is_logged_and_keeps_transaction_rollback(): void
    {
        $payment = Payment::create(['name' => 'test', 'payment' => 'test', 'uuid' => 'test-pay', 'config' => [], 'sort' => 10]);
        $level = DB::transactionLevel();
        $this->failNextQueryMatching('/update ["`]?v2_payment/i');
        Log::shouldReceive('log')->once()->withArgs(fn ($severity, $message, $context) =>
            $severity === 'error' && $message === '支付方式排序失败' && $context['exception_class'] === QueryException::class);
        $response = app(PaymentController::class)->sort(Request::create('/test', 'POST', ['ids' => [$payment->id]]));
        $this->assertSame(500, $response->status());
        $this->assertSame(10, $payment->fresh()->sort);
        $this->assertSame($level, DB::transactionLevel());
    }

    public function test_ticket_error_and_return_value_survive_log_writer_failure(): void
    {
        $ticket = Ticket::create(['user_id' => 1, 'subject' => 'test', 'level' => 1, 'status' => 0]);
        $level = DB::transactionLevel();
        $this->failNextQueryMatching('/insert into ["`]?v2_ticket_message/i');
        Log::shouldReceive('log')->once()->andThrow(new \RuntimeException('log disk failed'));
        $this->assertFalse(app(TicketService::class)->reply($ticket, 'test body', 1));
        $this->assertDatabaseCount('v2_ticket_message', 0);
        $this->assertSame($level, DB::transactionLevel());
    }
}
