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
            'country_name' => 'United States', 'region_name' => 'California', 'city_name' => 'Mountain View',
        ])]);

        $service = app(DeviceIpLocationService::class);
        $first = $service->lookup('8.8.8.8');
        $second = $service->lookup('8.8.8.8');
        $private = $service->lookup('192.168.1.1');

        $this->assertSame('AS15169', $first['asn']);
        $this->assertSame('Google LLC', $first['as_name']);
        $this->assertSame('United States California Mountain View', $first['region']);
        $this->assertSame('ip2location', $first['location_source']);
        $this->assertSame('local', $private['location_source']);
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

    public function test_failed_empty_or_mismatched_locations_fall_back_to_local(): void
    {
        foreach ([
            Http::response([], 500),
            Http::response(['ip' => '8.8.8.8', 'country_name' => '-', 'region_name' => [], 'city_name' => '']),
            Http::response(['ip' => '1.1.1.1', 'country_name' => '错误位置']),
            Http::failedConnection(),
        ] as $response) {
            Cache::flush();
            Http::fake(['api.ip2location.io/*' => $response]);
            $result = app(DeviceIpLocationService::class)->lookup('8.8.8.8');
            $this->assertSame('local', $result['location_source']);
            $this->assertNotSame('错误位置', $result['region']);
            $this->assertNotEmpty($result['region']);
        }
    }

    public function test_quota_exhaustion_uses_local_and_old_asn_cache_does_not_mask_location(): void
    {
        Cache::flush();
        Cache::put('device_asn:v1:' . hash('sha256', '8.8.8.8'), ['asn' => 'AS1', 'as_name' => '旧缓存'], 86400);
        Http::fake(['api.ip2location.io/*' => Http::response([
            'ip' => '8.8.8.8', 'country_name' => '美国', 'region_name' => '美国', 'city_name' => '测试城市',
        ])]);
        $service = app(DeviceIpLocationService::class);
        $this->assertSame('美国 测试城市', $service->lookup('8.8.8.8')['region']);
        Cache::put('device_asn:quota:' . now('UTC')->toDateString(), 1000, 86400);
        $this->assertSame('local', $service->lookup('1.1.1.1')['location_source']);
        Http::assertSentCount(1);
    }

    public function test_missing_location_is_retried_after_short_cache_expires(): void
    {
        Cache::flush();
        Http::fake(['api.ip2location.io/*' => Http::sequence()
            ->push(['ip' => '8.8.8.8', 'asn' => '15169'])
            ->push(['ip' => '8.8.8.8', 'asn' => '15169', 'country_name' => '美国'])]);
        $service = app(DeviceIpLocationService::class);
        $this->assertSame('local', $service->lookup('8.8.8.8')['location_source']);
        $this->assertSame('local', $service->lookup('8.8.8.8')['location_source']);
        Http::assertSentCount(1);
        $this->travel(301)->seconds();
        $this->assertSame('ip2location', $service->lookup('8.8.8.8')['location_source']);
        Http::assertSentCount(2);
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
