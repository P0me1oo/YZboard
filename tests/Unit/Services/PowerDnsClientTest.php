<?php

namespace Tests\Unit\Services;

use App\Exceptions\DdnsSyncException;
use App\Models\BalancerDnsPreset;
use App\Services\PowerDnsClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PowerDnsClientTest extends TestCase
{
    private BalancerDnsPreset $preset;
    private array $logs = [];

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
        Http::preventStrayRequests();
        Log::shouldReceive('log')->andReturnUsing(function ($level, $message, $context) {
            $this->logs[] = [$level, $message, $context];
        });
        $this->preset = new BalancerDnsPreset();
        $this->preset->forceFill(['id' => 7, 'provider' => 'powerdns', 'domain' => 'example.test',
            'api_url' => 'https://dns-api.example.test', 'server_id' => 'localhost', 'api_token' => 'test-api-secret']);
    }

    private function zone(array $rrsets = []): array
    {
        return ['name' => 'example.test.', 'rrsets' => $rrsets];
    }

    private function rrset(string $ip = '192.0.2.1'): array
    {
        return ['name' => 'entry.example.test.', 'type' => 'A', 'ttl' => 60,
            'records' => [['content' => $ip, 'disabled' => false]],
            'comments' => [['content' => 'test-private-marker', 'account' => 'YZboard']]];
    }

    private function failure(string $method = 'get', array $data = [], ?array $baseline = null): DdnsSyncException
    {
        try {
            app(PowerDnsClient::class)->request($this->preset, $method, $data, $baseline);
            $this->fail('请求应失败');
        } catch (DdnsSyncException $error) {
            $this->assertNull($error->getPrevious());
            return $error;
        }
    }

    public function test_fourth_attempt_can_recover_with_five_second_limits_and_one_log(): void
    {
        $calls = 0;
        Http::fake(function ($request, $options) use (&$calls) {
            $this->assertEquals(5, $options['connect_timeout']);
            $this->assertEquals(5, $options['timeout']);
            $this->assertFalse($options['allow_redirects']);
            $this->assertSame(['test-api-secret'], $request->header('X-API-Key'));
            return ++$calls < 4 ? Http::response(['error' => 'test-api-secret'], 503) : Http::response($this->zone());
        });
        $this->assertSame($this->zone(), app(PowerDnsClient::class)->request($this->preset, 'get'));
        $this->assertSame(4, $calls);
        Sleep::assertSequence([Sleep::for(200)->milliseconds(), Sleep::for(400)->milliseconds(), Sleep::for(800)->milliseconds()]);
        $this->assertCount(1, $this->logs);
        $this->assertSame('powerdns.recovered', $this->logs[0][2]['event']);
        $this->assertSame(4, $this->logs[0][2]['requests']);
        $this->assertStringNotContainsString('test-api-secret', json_encode($this->logs));
    }

    public function test_retries_stop_after_four_requests_and_record_safe_diagnostics(): void
    {
        Http::fake(['*' => Http::response(['error' => 'test-api-secret'], 503)]);
        $error = $this->failure();
        Http::assertSentCount(4);
        Sleep::assertSleptTimes(3);
        $this->assertStringContainsString('HTTP 503', $error->getMessage());
        $this->assertStringContainsString('请求 4 次', $error->getMessage());
        $this->assertCount(1, $this->logs);
        $this->assertSame('warning', $this->logs[0][0]);
        $this->assertSame('server_error', $this->logs[0][2]['reason']);
        $this->assertSame(503, $this->logs[0][2]['http_status']);
        $this->assertStringNotContainsString('test-api-secret', json_encode([$this->logs, $error->getMessage()]));
        $this->assertStringNotContainsString('dns-api.example.test', json_encode($this->logs));
    }

    public static function permanentStatuses(): array
    {
        return [[401, '密钥认证失败', 'unauthorized'], [403, '白名单', 'forbidden'],
            [404, '区域不存在', 'not_found'], [422, '记录请求被拒绝', 'invalid_records'], [302, '重定向', 'redirect']];
    }

    #[DataProvider('permanentStatuses')]
    public function test_permanent_errors_are_specific_and_not_retried(int $status, string $message, string $reason): void
    {
        Http::fake(['*' => Http::response('test-api-secret', $status, ['Location' => 'https://other.example.test'])]);
        $this->assertStringContainsString($message, $this->failure()->getMessage());
        Http::assertSentCount(1);
        Sleep::assertNeverSlept();
        $this->assertSame($reason, $this->logs[0][2]['reason']);
    }

    public static function connectionFailures(): array
    {
        return [[6, 'dns_resolution', 4], [7, 'connection', 4], [28, 'timeout', 4],
            [35, 'tls_handshake', 1], [60, 'tls_certificate', 1], [56, 'connection', 4]];
    }

    #[DataProvider('connectionFailures')]
    public function test_transport_errors_use_curl_code_without_exposing_exception(int $errno, string $reason, int $attempts): void
    {
        $calls = 0;
        Http::fake(function () use ($errno, &$calls) {
            $calls++;
            $cause = new ConnectException('test-api-secret private-body',
                new Request('GET', 'https://dns-api.example.test', ['X-API-Key' => 'test-api-secret']),
                null, ['errno' => $errno, 'error' => 'private-body']);
            throw new ConnectionException('test-api-secret private-body', 0, $cause);
        });
        $error = $this->failure();
        $this->assertSame($attempts, $calls);
        $this->assertSame($reason, $this->logs[0][2]['reason']);
        $this->assertSame($errno, $this->logs[0][2]['curl_errno']);
        foreach (['test-api-secret', 'private-body', 'dns-api.example.test'] as $secret) {
            $this->assertStringNotContainsString($secret, json_encode([$this->logs, $error->getMessage()]));
        }
    }

    public static function invalidZones(): array
    {
        return [['<html>test-api-secret</html>', 'invalid_response'],
            [['name' => 'wrong.test.', 'rrsets' => []], 'zone_mismatch'],
            [['name' => 'example.test.', 'rrsets' => [['name' => 'broken']]], 'invalid_response']];
    }

    #[DataProvider('invalidZones')]
    public function test_invalid_zone_response_is_not_retried(array|string $body, string $reason): void
    {
        Http::fake(['*' => Http::response($body)]);
        $this->failure();
        Http::assertSentCount(1);
        $this->assertSame($reason, $this->logs[0][2]['reason']);
        $this->assertStringNotContainsString('test-api-secret', json_encode($this->logs));
    }

    public function test_corrupt_stored_key_stops_before_network_request(): void
    {
        $this->preset->setRawAttributes([...$this->preset->getAttributes(), 'api_token' => 'test-corrupt-secret']);
        Http::fake();
        $this->assertStringContainsString('密钥无法解密', $this->failure()->getMessage());
        Http::assertNothingSent();
        $this->assertSame('credential_decrypt', $this->logs[0][2]['reason']);
    }

    public function test_lost_write_response_is_confirmed_without_duplicate_patch(): void
    {
        $action = [...$this->rrset(), 'changetype' => 'REPLACE'];
        Http::fakeSequence()->pushStatus(503)->push($this->zone([$this->rrset()]));
        $this->assertSame([], app(PowerDnsClient::class)->request($this->preset, 'patch', ['rrsets' => [$action]], []));
        $this->assertSame(['PATCH', 'GET'], Http::recorded()->map(fn ($pair) => $pair[0]->method())->all());
        $this->assertSame('powerdns.recovered', $this->logs[0][2]['event']);
        foreach (['test-private-marker', '192.0.2.1', 'entry.example.test'] as $private) {
            $this->assertStringNotContainsString($private, json_encode($this->logs));
        }
    }

    public function test_unapplied_write_retries_only_after_unchanged_snapshot_is_read(): void
    {
        $before = $this->rrset();
        $action = [...$this->rrset('192.0.2.2'), 'changetype' => 'REPLACE'];
        Http::fakeSequence()->pushStatus(502)->push($this->zone([$before]))->pushStatus(204);
        $this->assertSame([], app(PowerDnsClient::class)->request($this->preset, 'patch', ['rrsets' => [$action]], [$before]));
        $this->assertSame(['PATCH', 'GET', 'PATCH'], Http::recorded()->map(fn ($pair) => $pair[0]->method())->all());
        $this->assertSame(2, $this->logs[0][2]['requests']);
    }

    public function test_unavailable_verification_never_resends_an_uncertain_write(): void
    {
        $action = [...$this->rrset(), 'changetype' => 'REPLACE'];
        Http::fake(['*' => Http::response([], 503)]);
        $error = $this->failure('patch', ['rrsets' => [$action]], []);
        $this->assertStringContainsString('写入结果待确认', $error->getMessage());
        $this->assertSame(['PATCH', 'GET', 'GET', 'GET', 'GET'], Http::recorded()->map(fn ($pair) => $pair[0]->method())->all());
    }

    public function test_fourth_patch_can_be_confirmed_by_final_read_after_response_loss(): void
    {
        $action = [...$this->rrset(), 'changetype' => 'REPLACE'];
        $sequence = Http::fakeSequence();
        for ($i = 0; $i < 3; $i++) $sequence->pushStatus(503)->push($this->zone());
        $sequence->pushStatus(503)->push($this->zone([$this->rrset()]));
        $this->assertSame([], app(PowerDnsClient::class)->request($this->preset, 'patch', ['rrsets' => [$action]], []));
        Http::assertSentCount(8);
        $this->assertSame(4, $this->logs[0][2]['requests']);
        $this->assertSame(4, $this->logs[0][2]['verification_requests']);
    }

    public function test_unapplied_patch_stops_after_three_retries(): void
    {
        $action = [...$this->rrset(), 'changetype' => 'REPLACE'];
        Http::fake(fn ($r) => $r->method() === 'GET' ? Http::response($this->zone()) : Http::response([], 503));
        $this->failure('patch', ['rrsets' => [$action]], []);
        $this->assertSame(4, $this->logs[0][2]['requests']);
        Http::assertSentCount(8);
    }

    public static function changedSnapshots(): array
    {
        return [['foreign_owner'], ['different_content'], ['new_wildcard'], ['new_address_family'], ['disabled_record']];
    }

    #[DataProvider('changedSnapshots')]
    public function test_changed_zone_stops_retry_without_overwriting_or_deleting(string $change): void
    {
        $before = $this->rrset();
        $actual = [$before];
        if ($change === 'foreign_owner') $actual[0]['comments'] = [['content' => 'foreign owner']];
        if ($change === 'different_content') $actual[0]['records'][0]['content'] = '192.0.2.99';
        if ($change === 'disabled_record') $actual[0]['records'][0]['disabled'] = true;
        if ($change === 'new_wildcard') $actual[] = [...$before, 'name' => '*.example.test.'];
        if ($change === 'new_address_family') $actual[] = [...$before, 'type' => 'AAAA', 'records' => [['content' => '2001:db8::1', 'disabled' => false]]];
        $action = ['name' => $before['name'], 'type' => 'A', 'changetype' => 'DELETE'];
        Http::fakeSequence()->pushStatus(503)->push($this->zone($actual));
        $this->assertStringContainsString('区域记录已变化', $this->failure('patch', ['rrsets' => [$action]], [$before])->getMessage());
        $this->assertSame(['PATCH', 'GET'], Http::recorded()->map(fn ($pair) => $pair[0]->method())->all());
    }

    public function test_missing_baseline_prevents_resending_unconfirmed_patch(): void
    {
        Http::fakeSequence()->pushStatus(503)->push($this->zone());
        $this->failure('patch', ['rrsets' => [[...$this->rrset(), 'changetype' => 'REPLACE']]]);
        Http::assertSentCount(2);
    }

    public function test_successful_request_does_not_log_and_log_failure_does_not_break_recovery(): void
    {
        Http::fakeSequence()->push($this->zone())->pushStatus(503)->push($this->zone());
        app(PowerDnsClient::class)->request($this->preset, 'get');
        $this->assertSame([], $this->logs);
        Log::swap(\Mockery::mock()->shouldReceive('log')->andThrow(new \RuntimeException('log unavailable'))->getMock());
        $this->assertSame($this->zone(), app(PowerDnsClient::class)->request($this->preset, 'get'));
    }

    public function test_retry_after_is_honoured_when_it_fits_within_the_budget(): void
    {
        Http::fakeSequence()->pushStatus(429, ['Retry-After' => '2'])->push($this->zone());
        app(PowerDnsClient::class)->request($this->preset, 'get');
        Sleep::assertSequence([Sleep::for(2000)->milliseconds()]);
        $this->assertSame('rate_limited', $this->logs[0][2]['reason']);
    }

    public function test_retry_after_larger_than_the_budget_stops_without_hammering_api(): void
    {
        Http::fake(['*' => Http::response([], 429, ['Retry-After' => '60'])]);
        $this->assertStringContainsString('处理时限', $this->failure()->getMessage());
        Http::assertSentCount(1);
        Sleep::assertNeverSlept();
        $this->assertSame('rate_limited', $this->logs[0][2]['reason']);
        $this->assertSame(429, $this->logs[0][2]['http_status']);
        $this->assertTrue($this->logs[0][2]['budget_exhausted']);
    }

    public function test_shared_deadline_bounds_request_timeout_and_prevents_new_requests_when_expired(): void
    {
        Http::fake(function ($request, $options) {
            $this->assertLessThanOrEqual(2, $options['timeout']);
            $this->assertGreaterThan(1, $options['timeout']);
            return Http::response($this->zone());
        });
        app(PowerDnsClient::class)->request($this->preset, 'get', deadline: microtime(true) + 2);
        try {
            app(PowerDnsClient::class)->request($this->preset, 'get', deadline: microtime(true) - 1);
            $this->fail('过期预算不能继续请求');
        } catch (DdnsSyncException $error) {
            $this->assertStringContainsString('处理时限', $error->getMessage());
        }
        Http::assertSentCount(1);
    }
}
