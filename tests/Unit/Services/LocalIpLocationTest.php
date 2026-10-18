<?php

namespace Tests\Unit\Services;

use App\Services\LocalIpLocation;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LocalIpLocationTest extends TestCase
{
    public function test_both_official_databases_match_the_pinned_source_and_are_readable(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $source = json_decode(file_get_contents(resource_path('ip/ip2region.json')), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('https://github.com/lionsoul2014/ip2region', $source['repository']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{40}$/D', $source['commit']);
        $this->assertCount(2, $source['databases']);
        foreach ($source['databases'] as $database) {
            $path = resource_path('ip/' . $database['file']);
            $this->assertSame($database['bytes'], filesize($path));
            $this->assertSame($database['sha256'], hash_file('sha256', $path));
            $header = \ip2region\xdb\Util::loadHeaderFromFile($path);
            $this->assertSame(3, $header['version']);
            $this->assertSame($database['ip_version'], $header['ipVersion']);
            $this->assertSame(strtotime($database['generated_at_utc']), $header['createdAt']);
            $this->assertNull(\ip2region\xdb\Util::verifyFromFile($path));
        }

        $reader = new LocalIpLocation();
        foreach (['61.142.118.231', '2400:3200::1'] as $ip) {
            $location = $reader->lookup($ip);
            $this->assertNotEmpty($location['region']);
            $this->assertNotEmpty($location['isp']);
            $this->assertSame($location, $reader->lookup($ip));
            $this->assertSame($location, (new LocalIpLocation())->lookup($ip));
        }
        Http::assertNothingSent();
    }

    public function test_v3_fields_do_not_mix_the_isp_or_country_code_into_the_region(): void
    {
        $this->assertSame(['region' => '中国 北京市', 'isp' => '测试运营商', 'province' => '北京市', 'country_code' => 'CN'],
            LocalIpLocation::parse('中国|北京市|北京市|测试运营商|CN'));
        $this->assertSame(['region' => 'United States California San Jose', 'isp' => 'Example ISP', 'province' => 'United States', 'country_code' => 'US'],
            LocalIpLocation::parse('United States|California|San Jose|Example ISP|US'));
        foreach (['0|0|0|0|0', ' | - | 0 | - |0', '', '<html>error</html>'] as $value) {
            $this->assertSame(['region' => null, 'isp' => null, 'province' => '未知', 'country_code' => ''], LocalIpLocation::parse($value));
        }
    }

    public function test_missing_database_does_not_fall_back_to_the_package_or_disable_the_other_family(): void
    {
        $missing = resource_path('ip/missing-test-database.xdb');
        $unknown = ['region' => null, 'isp' => null, 'province' => '未知', 'country_code' => ''];
        $this->assertFileDoesNotExist($missing);
        $noV4 = new LocalIpLocation($missing);
        $this->assertSame($unknown, $noV4->lookup('8.8.8.8'));
        $this->assertNotEmpty($noV4->lookup('2400:3200::1')['region']);
        $noV6 = new LocalIpLocation(null, $missing);
        $this->assertSame($unknown, $noV6->lookup('2400:3200::1'));
        $this->assertNotEmpty($noV6->lookup('8.8.8.8')['region']);
    }

    public function test_corrupt_or_wrong_family_database_does_not_break_other_queries(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'yz-xdb-');
        try {
            file_put_contents($path, 'invalid xdb');
            $reader = new LocalIpLocation($path);
            $this->assertSame(['region' => null, 'isp' => null, 'province' => '未知', 'country_code' => ''], $reader->lookup('8.8.8.8'));
            $this->assertNotEmpty($reader->lookup('2400:3200::1')['region']);
            $wrongFamily = new LocalIpLocation(resource_path('ip/ip2region_v6.xdb'));
            $this->assertSame(['region' => null, 'isp' => null, 'province' => '未知', 'country_code' => ''], $wrongFamily->lookup('8.8.8.8'));
            $this->assertNotEmpty($wrongFamily->lookup('2400:3200::1')['region']);
        } finally {
            unlink($path);
        }
    }

    public function test_non_public_or_invalid_addresses_are_unknown(): void
    {
        $reader = new LocalIpLocation();
        foreach (['', 'invalid', '127.0.0.1', '192.168.1.1', '::1', 'fc00::1', 'fe80::1'] as $ip) {
            $this->assertSame(['region' => null, 'isp' => null, 'province' => '未知', 'country_code' => ''], $reader->lookup($ip));
        }
    }
}
