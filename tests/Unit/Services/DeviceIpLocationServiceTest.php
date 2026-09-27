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
}
