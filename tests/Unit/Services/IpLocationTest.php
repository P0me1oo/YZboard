<?php

namespace Tests\Unit\Services;

use App\Services\IpLocation;
use App\Services\LocalIpLocation;
use App\Services\QqwryLocation;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IpLocationTest extends TestCase
{
    public function test_only_mainland_ipv4_uses_qqwry_and_other_addresses_use_ip2region(): void
    {
        $local = $this->createMock(LocalIpLocation::class);
        $local->method('lookup')->willReturnCallback(fn ($ip) => [
            'region' => 'ip2region 地区', 'province' => 'ip2region 省份', 'isp' => '测试运营商',
            'country_code' => $ip === '1.32.0.1' ? 'HK' : ($ip === '8.8.8.8' ? 'US' : 'CN'),
        ]);
        $qqwry = $this->createMock(QqwryLocation::class);
        $qqwry->expects($this->once())->method('lookup')->with('223.5.5.5')
            ->willReturn(['region' => '纯真国内地区', 'province' => '浙江省', 'isp' => '纯真运营商']);
        $service = new IpLocation($local, $qqwry);
        $china = $service->lookup('223.5.5.5');
        $this->assertSame('纯真国内地区', $china['region']);
        $this->assertSame('浙江省', $china['province']);
        $this->assertSame('qqwry', $china['source']);
        $this->assertSame('纯真运营商', $china['isp']);
        foreach (['8.8.8.8', '1.32.0.1', '2400:3200::1'] as $ip) {
            $location = $service->lookup($ip);
            $this->assertSame('ip2region 地区', $location['region']);
            $this->assertSame('ip2region', $location['source']);
            $this->assertSame('local', $location['location_source']);
            $this->assertSame('测试运营商', $location['isp']);
        }
    }

    public function test_real_databases_apply_the_same_rule_without_network_requests(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $service = new IpLocation();
        foreach (['223.5.5.5' => 'qqwry', '8.8.8.8' => 'ip2region', '2400:3200::1' => 'ip2region'] as $ip => $source) {
            $result = $service->lookup($ip);
            $this->assertSame($source, $result['source']);
            $this->assertNotSame('未知', $result['region']);
            $expected = $source === 'qqwry' ? (new QqwryLocation())->lookup($ip) : (new LocalIpLocation())->lookup($ip);
            $this->assertSame($expected['isp'], $result['isp']);
            $this->assertSame($result, (new IpLocation())->lookup($ip));
        }
        Http::assertNothingSent();
    }

    public function test_unavailable_qqwry_falls_back_to_ip2region(): void
    {
        $service = new IpLocation(new LocalIpLocation(), new QqwryLocation(resource_path('ip/missing-test-qqwry.dat')));
        $result = $service->lookup('223.5.5.5');
        $this->assertSame('ip2region', $result['source']);
        $this->assertSame((new LocalIpLocation())->lookup('223.5.5.5')['region'], $result['region']);
        $this->assertSame((new LocalIpLocation())->lookup('223.5.5.5')['isp'], $result['isp']);
    }

    public function test_unknown_qqwry_operator_does_not_mix_in_another_database(): void
    {
        $local = $this->createMock(LocalIpLocation::class);
        $local->method('lookup')->willReturn(['country_code' => 'CN', 'region' => '其他地区', 'province' => '其他省份', 'isp' => '其他运营商']);
        $qqwry = $this->createMock(QqwryLocation::class);
        $qqwry->method('lookup')->willReturn(['region' => '中国–广东–广州', 'province' => '广东省', 'isp' => '未知']);
        $result = (new IpLocation($local, $qqwry))->lookup('202.96.128.86');
        $this->assertSame('未知', $result['isp']);
        $this->assertSame('qqwry', $result['source']);
        $this->assertSame('广东省', $result['province']);
    }
}
