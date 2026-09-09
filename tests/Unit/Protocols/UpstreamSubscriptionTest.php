<?php

namespace Tests\Unit\Protocols;

use App\Protocols\General;
use App\Protocols\Shadowrocket;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UpstreamSubscriptionTest extends TestCase
{
    public function test_vless_name_round_trips_spaces_plus_signs_and_chinese(): void
    {
        $name = '新加坡 A+B #1';
        $uri = General::buildVless('unused', [
            'name' => $name,
            'host' => '192.0.2.10',
            'port' => 443,
            'protocol_settings' => ['network' => 'tcp', 'tls' => 0],
        ]);
        $fragment = parse_url(trim($uri), PHP_URL_FRAGMENT);

        $this->assertSame($name, rawurldecode($fragment));
        $this->assertStringContainsString('%20', $fragment);
        $this->assertStringNotContainsString('+', $fragment);
    }

    #[DataProvider('hysteriaBandwidths')]
    public function test_shadowrocket_hysteria2_preserves_bandwidth_tls_and_port_hopping(
        array $bandwidth,
        array $expectedBandwidth,
    ): void {
        $uri = Shadowrocket::buildHysteria('unused', [
            'name' => 'subscription-test',
            'host' => '192.0.2.10',
            'port' => 443,
            'ports' => '443,8443',
            'protocol_settings' => [
                'version' => 2,
                'bandwidth' => $bandwidth,
                'tls' => ['server_name' => 'example.invalid', 'allow_insecure' => false],
                'hop_interval' => 10,
            ],
        ]);
        parse_str(parse_url(trim($uri), PHP_URL_QUERY), $params);

        $this->assertSame($expectedBandwidth, array_intersect_key($params, array_flip(['upmbps', 'downmbps'])));
        $this->assertSame('example.invalid', $params['peer']);
        $this->assertSame('0', $params['insecure']);
        $this->assertSame('443,8443', $params['mport']);
        $this->assertSame('10', $params['keepalive']);
    }

    public static function hysteriaBandwidths(): array
    {
        return [
            '上下行带宽' => [['up' => 20, 'down' => 100], ['upmbps' => '20', 'downmbps' => '100']],
            '仅上行带宽' => [['up' => 25], ['upmbps' => '25']],
            '未设置带宽' => [[], []],
            '无效带宽' => [['up' => 0, 'down' => -1], []],
        ];
    }
}
