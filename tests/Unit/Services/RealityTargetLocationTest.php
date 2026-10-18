<?php

namespace Tests\Unit\Services;

use App\Services\RealityTargetLocation;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RealityTargetLocationTest extends TestCase
{
    public function test_local_database_enriches_actual_ip_without_external_requests(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $results = app(RealityTargetLocation::class)->enrich([
            ['host' => 'example.com', 'ip' => '8.8.8.8', 'feasible' => true],
            ['host' => 'example.net', 'ip' => '8.8.8.8', 'feasible' => false],
            ['host' => 'ipv6.example', 'ip' => '2606:4700:4700::1111'],
            ['ip' => ''], ['ip' => '127.0.0.1'], ['ip' => 'invalid'],
        ]);
        $this->assertNotEmpty($results[0]['location']['region']);
        $this->assertSame('ip2region', $results[0]['location']['source']);
        $this->assertSame($results[0]['location'], $results[1]['location']);
        $this->assertFalse($results[1]['feasible']);
        $this->assertNotEmpty($results[2]['location']['region']);
        $this->assertNotEmpty($results[2]['location']['isp']);
        $this->assertSame('ip2region', $results[2]['location']['source']);
        foreach (array_slice($results, 3) as $result) {
            $this->assertNull($result['location']['region']);
            $this->assertNull($result['location']['isp']);
        }
        Http::assertNothingSent();
    }

    public function test_placeholders_and_repeated_region_names_are_not_displayed(): void
    {
        $this->assertSame(['region' => '中国 北京', 'isp' => '测试运营商', 'source' => 'ip2region'],
            RealityTargetLocation::parse('中国|北京|北京|测试运营商|CN'));
        $this->assertSame(['region' => null, 'isp' => null, 'source' => 'ip2region'],
            RealityTargetLocation::parse('0|0|0|0|0'));
    }
}
