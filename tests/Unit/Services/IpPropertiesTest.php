<?php

namespace Tests\Unit\Services;

use App\Services\IpProperties;
use Tests\TestCase;

class IpPropertiesTest extends TestCase
{
    public function test_extended_properties_survive_storage_and_merge_without_losing_zero(): void
    {
        $data = IpProperties::fromResponse(['fraud_score' => 0, 'as_info' => ['as_domain' => 'network.example'],
            'net_speed' => 'DSL', 'mobile_brand' => ' Test Mobile ', 'district' => ' Test District ']);
        $this->assertSame(0, $data['fraud_score']);
        $this->assertSame('network.example', $data['as_domain']);
        $this->assertSame('DSL', $data['net_speed']);
        $this->assertSame('Test Mobile', $data['mobile_brand']);
        $this->assertSame('Test District', $data['district']);
        $this->assertSame($data, IpProperties::fromResponse(json_decode(json_encode($data), true)));
        $this->assertSame($data, IpProperties::merge($data, []));
        $this->assertSame(0, IpProperties::merge(['fraud_score' => 99], $data)['fraud_score']);
        $this->assertSame(99, IpProperties::fromResponse(['fraud_score' => 99])['fraud_score']);
        $this->assertArrayNotHasKey('address_type', $data);
    }

    public function test_missing_placeholder_and_wrong_types_are_not_treated_as_known_values(): void
    {
        foreach ([null, '', '-', '0', '99', -1, 100, 1.5, 0.0, false, true, []] as $value) {
            $this->assertNull(IpProperties::fromResponse(['fraud_score' => $value])['fraud_score']);
        }
        foreach ([null, '', '-', 0, false, [], 'https://network.example', 'bad domain.example'] as $value) {
            $this->assertNull(IpProperties::fromResponse(['as_info' => ['as_domain' => $value]])['as_domain']);
        }
        $this->assertNull(IpProperties::fromResponse(['as_info' => 'invalid'])['as_domain']);
        foreach ([null, '', '-', 0, false, []] as $value) {
            $data = IpProperties::fromResponse(['district' => $value, 'mobile_brand' => $value]);
            $this->assertNull($data['district']);
            $this->assertNull($data['mobile_brand']);
        }
        foreach (['DIAL', 'DSL', 'COMP', 'T1', 'SAT'] as $code) {
            $this->assertSame($code, IpProperties::fromResponse(['net_speed' => $code])['net_speed']);
        }
        foreach (['dsl', 'UNKNOWN', '-', [], true] as $value) {
            $this->assertNull(IpProperties::fromResponse(['net_speed' => $value])['net_speed']);
        }
    }
}
