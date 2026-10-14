<?php

namespace Tests\Unit\Services;

use App\Services\InboundIpWebService;
use App\Services\Ip2LocationWebParser;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InboundIpWebServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(\Carbon\Carbon::parse('2026-10-14 07:00:00', 'UTC'));
        Cache::flush();
        Http::preventStrayRequests();
    }

    private function page(string $ip = '8.8.8.8'): string
    {
        return str_replace('8.8.8.8', $ip, file_get_contents(base_path('tests/Fixtures/ip2location-web.html')));
    }

    private function jsonPage(array $body): string
    {
        return '<pre id="code2">' . htmlspecialchars(json_encode($body, JSON_THROW_ON_ERROR), ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</pre>';
    }

    public function test_full_page_preserves_explicit_positive_and_negative_security_results(): void
    {
        $parser = app(Ip2LocationWebParser::class);
        $data = $parser->parse($this->page(), '8.8.8.8');
        $this->assertSame('AS64500', $data['asn']);
        $this->assertSame('Test Carrier & Transit', $data['ip_properties']['isp']);
        $this->assertSame('DCH', $data['ip_properties']['usage_type']);
        $this->assertFalse($data['ip_properties']['is_proxy']);
        $this->assertSame('ip2location.com', $data['source']);
        foreach ($data['ip_properties']['proxy'] as $field => $value) $this->assertSame($field === 'is_data_center', $value);

        $html = $this->jsonPage(['ip' => '8.8.8.8', 'usage_type' => 'ISP/MOB', 'is_proxy' => true,
            'proxy' => ['is_vpn' => true, 'is_tor' => false, 'is_spammer' => true, 'is_scanner' => true,
                'is_data_center' => 'false', 'is_botnet' => 0]]);
        $data = $parser->parse($html, '8.8.8.8');
        $this->assertSame('ISP/MOB', $data['ip_properties']['usage_type']);
        $this->assertTrue($data['ip_properties']['proxy']['is_vpn']);
        $this->assertFalse($data['ip_properties']['proxy']['is_tor']);
        $this->assertTrue($data['ip_properties']['proxy']['is_spammer']);
        $this->assertTrue($data['ip_properties']['proxy']['is_scanner']);
        $this->assertNull($data['ip_properties']['proxy']['is_data_center']);
        $this->assertNull($data['ip_properties']['proxy']['is_botnet']);
        $this->assertNull($data['ip_properties']['proxy']['is_ai_crawler']);
        $this->assertNull($parser->parse($html, '1.1.1.1'));
        $this->assertNull($parser->parse('<html><body>Access denied</body></html>', '8.8.8.8'));
    }

    public function test_network_codes_do_not_infer_missing_proxy_results(): void
    {
        $parser = app(Ip2LocationWebParser::class);
        foreach (['COM', 'ORG', 'GOV', 'MIL', 'EDU', 'LIB', 'CDN', 'ISP', 'MOB', 'ISP/MOB', 'DCH', 'SES', 'AIC', 'RSV'] as $code) {
            $html = $this->jsonPage(['ip' => '8.8.8.8', 'usage_type' => $code, 'is_proxy' => false,
                'proxy' => ['proxy_type' => '-', 'threat' => '-']]);
            $data = $parser->parse($html, '8.8.8.8');
            $this->assertSame($code, $data['ip_properties']['usage_type']);
            $this->assertFalse($data['ip_properties']['is_proxy']);
            foreach ($data['ip_properties']['proxy'] as $value) $this->assertNull($value);
        }
        foreach (['', '-', 'UNKNOWN', 'isp/mob', 'ISP/MOB extra', '(ISP/MOB) Example', ['ISP/MOB']] as $value) {
            $html = $this->jsonPage(['ip' => '8.8.8.8', 'usage_type' => $value, 'is_proxy' => false]);
            $this->assertNull($parser->parse($html, '8.8.8.8')['ip_properties']['usage_type']);
        }
    }

    public function test_wrong_missing_duplicate_or_malformed_json_is_not_used_as_ip_data(): void
    {
        $parser = app(Ip2LocationWebParser::class);
        foreach (['<pre id="code2">invalid json</pre>', '<pre id="code2">null</pre>',
            '<pre id="code2">[]</pre>', $this->jsonPage(['ip' => ['8.8.8.8']]), $this->jsonPage(['ip' => '8.8.8.8']),
            $this->page() . $this->jsonPage(['ip' => '1.1.1.1', 'isp' => 'Wrong Network']),
            '<script type="application/json">{"ip":"8.8.8.8","isp":"Script Network"}</script>',
            str_replace('id="code2"', 'id="code1"', $this->page())] as $html) {
            $this->assertNull($parser->parse($html, '8.8.8.8'));
        }
        $ipv6 = '2606:4700:4700::1111';
        $data = $parser->parse($this->jsonPage(['ip' => '2606:4700:4700:0:0:0:0:1111',
            'usage_type' => 'DCH', 'asn' => 13335, 'as' => 'Cloudflare']), $ipv6);
        $this->assertSame('AS13335', $data['asn']);
    }

    public function test_cache_deduplicates_addresses_for_thirty_days_without_changing_device_api_state(): void
    {
        $key = 'device_location:v4:' . hash('sha256', '8.8.8.8');
        Cache::put($key, ['asn' => 'AS64501'], 30 * 86400);
        Cache::put('device_asn:backoff', true, 86400);
        Cache::put('device_asn:quota:' . now('UTC')->toDateString(), 1000, 86400);
        Http::fake(['www.ip2location.com/*' => Http::response($this->page(), 200, ['Content-Type' => 'text/html'])]);
        $service = app(InboundIpWebService::class);
        $first = $service->lookupMany(['8.8.8.8', '8.8.8.8', '192.168.1.1', '203.0.113.1', 'invalid']);
        $this->assertSame(['8.8.8.8'], array_keys($first));
        $this->assertSame('AS64500', $first['8.8.8.8']['data']['asn']);
        $this->assertSame(['asn' => 'AS64501'], Cache::get($key));
        $this->assertTrue(Cache::get('device_asn:backoff'));
        $this->assertSame(1000, Cache::get('device_asn:quota:' . now('UTC')->toDateString()));
        $this->travel(29)->days();
        $this->assertSame($first, $service->lookupMany(['8.8.8.8']));
        Http::assertSentCount(1);
        $this->travel(1)->days();
        $service->lookupMany(['8.8.8.8']);
        Http::assertSentCount(2);
    }

    public function test_ordinary_failure_is_retried_per_address_and_does_not_pause_other_addresses(): void
    {
        Http::fake(fn ($request) => str_ends_with($request->url(), '/8.8.8.8') ? Http::response([], 500)
            : Http::response($this->page(rawurldecode(basename($request->url()))), 200));
        $service = app(InboundIpWebService::class);
        $result = $service->lookupMany(['8.8.8.8', '1.1.1.1']);
        $this->assertNull($result['8.8.8.8']['data']);
        $this->assertSame(now()->addMinutes(5)->timestamp, $result['8.8.8.8']['retry_at']);
        $this->assertSame('DCH', $result['1.1.1.1']['data']['ip_properties']['usage_type']);
        $this->assertNull(Cache::get('inbound_ip_web:backoff'));
        $service->lookupMany(['8.8.8.8', '9.9.9.9']);
        Http::assertSentCount(3);
        $this->travel(301)->seconds();
        $service->lookupMany(['8.8.8.8']);
        Http::assertSentCount(4);
    }

    public function test_throttling_honors_retry_after_only_in_the_website_query_scope(): void
    {
        Http::fake(['www.ip2location.com/*' => Http::sequence()->push('limited', 429, ['Retry-After' => '600'])
            ->push($this->page('1.1.1.1'), 200)]);
        $service = app(InboundIpWebService::class);
        $result = $service->lookupMany(['8.8.8.8']);
        $this->assertSame(now()->addMinutes(10)->timestamp, $result['8.8.8.8']['retry_at']);
        $this->assertNull($service->lookupMany(['1.1.1.1'])['1.1.1.1']['data']);
        $this->assertNull(Cache::get('device_asn:backoff'));
        Http::assertSentCount(1);
        $this->travel(601)->seconds();
        $this->assertSame('AS64500', $service->lookupMany(['1.1.1.1'])['1.1.1.1']['data']['asn']);
        Http::assertSentCount(2);
    }

    public function test_in_flight_lookup_is_not_sent_a_second_time(): void
    {
        $service = app(InboundIpWebService::class);
        Http::fake(function () use ($service) {
            $this->assertSame([], $service->lookupMany(['8.8.8.8']));
            return Http::response($this->page(), 200);
        });
        $this->assertSame('AS64500', $service->lookupMany(['8.8.8.8'])['8.8.8.8']['data']['asn']);
        Http::assertSentCount(1);
    }

    public function test_quota_is_reserved_before_requests_and_only_resets_on_the_next_utc_day(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-10-14 23:59:00', 'UTC'));
        Cache::put('inbound_ip_web:quota:' . now('UTC')->toDateString(), 999, 120);
        Http::fake(fn ($request) => Http::response($this->page(rawurldecode(basename($request->url()))), 200));
        $service = app(InboundIpWebService::class);
        $result = $service->lookupMany(['8.8.8.8', '1.1.1.1']);
        $this->assertNotNull($result['8.8.8.8']['data']);
        $this->assertNull($result['1.1.1.1']['data']);
        $this->assertSame(now('UTC')->addDay()->startOfDay()->timestamp, $result['1.1.1.1']['retry_at']);
        $service->lookupMany(['9.9.9.9']);
        Http::assertSentCount(1);
        $this->travel(61)->seconds();
        $this->assertNotNull($service->lookupMany(['1.1.1.1'])['1.1.1.1']['data']);
        Http::assertSentCount(2);
    }

    public function test_ipv6_keeps_colons_and_wrong_oversized_or_redirected_pages_are_not_cached_as_success(): void
    {
        $ip = '2606:4700:4700::1111';
        Http::fake(['www.ip2location.com/*' => Http::response($this->page($ip), 200)]);
        $this->assertNotNull(app(InboundIpWebService::class)->lookupMany([$ip])[$ip]['data']);
        Http::assertSent(fn ($request) => $request->url() === 'https://www.ip2location.com/' . $ip);
        foreach ([Http::response($this->page('1.1.1.1'), 200), Http::response(str_repeat('x', 262145), 200),
            Http::response('', 302, ['Location' => 'http://127.0.0.1/']), Http::failedConnection()] as $response) {
            Cache::flush();
            Http::fake(['www.ip2location.com/*' => $response]);
            $result = app(InboundIpWebService::class)->lookupMany(['8.8.8.8']);
            $this->assertNull($result['8.8.8.8']['data']);
            $this->assertSame(now()->addMinutes(5)->timestamp, $result['8.8.8.8']['retry_at']);
        }
    }
}
