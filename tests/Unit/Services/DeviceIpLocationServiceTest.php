<?php

namespace Tests\Unit\Services;

use App\Services\DeviceIpLocationService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DeviceIpLocationServiceTest extends TestCase
{
    public function test_asn_is_cached_and_private_addresses_are_not_queried(): void
    {
        Cache::flush();
        Http::fake(['api.ip2location.io/*' => Http::response([
            'ip' => '8.8.8.8', 'asn' => '15169', 'as' => 'Google LLC',
        ])]);

        $service = app(DeviceIpLocationService::class);
        $first = $service->lookup('8.8.8.8');
        $second = $service->lookup('8.8.8.8');
        $private = $service->lookup('192.168.1.1');

        $this->assertSame('AS15169', $first['asn']);
        $this->assertSame('Google LLC', $first['as_name']);
        $this->assertSame($first, $second);
        $this->assertNull($private['asn']);
        Http::assertSentCount(1);
    }

    public function test_rate_limit_returns_unknown_without_retrying(): void
    {
        Cache::flush();
        Http::fake(['api.ip2location.io/*' => Http::response(['error' => 'limited'], 429)]);

        $service = app(DeviceIpLocationService::class);
        $this->assertNull($service->lookup('8.8.8.8')['asn']);
        $this->assertNull($service->lookup('1.1.1.1')['asn']);
        Http::assertSentCount(1);
    }

    public function test_multiple_addresses_are_queried_once_and_cached_separately(): void
    {
        Cache::flush();
        Http::fake(function ($request) {
            $ip = $request->data()['ip'];
            return Http::response(['ip' => $ip, 'asn' => $ip === '8.8.8.8' ? 15169 : 13335, 'as' => '测试网络']);
        });

        $service = app(DeviceIpLocationService::class);
        $result = $service->lookupMany(['8.8.8.8', '1.1.1.1', '8.8.8.8', '192.168.1.1']);

        $this->assertSame(['8.8.8.8', '1.1.1.1', '192.168.1.1'], array_keys($result));
        $this->assertSame('AS15169', $result['8.8.8.8']['asn']);
        $this->assertSame('AS13335', $result['1.1.1.1']['asn']);
        $this->assertNull($result['192.168.1.1']['asn']);
        Http::assertSentCount(2);

        $this->assertSame($result, $service->lookupMany(['8.8.8.8', '1.1.1.1', '192.168.1.1']));
        Http::assertSentCount(2);
    }
}
