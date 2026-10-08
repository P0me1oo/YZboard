<?php

namespace Tests\Unit\Services;

use App\Services\IpProvince;
use App\Services\QqwryLocation;
use Tests\TestCase;

class QqwryLocationTest extends TestCase
{
    public function test_bundled_database_matches_pinned_checksum_and_reads_real_records(): void
    {
        $this->assertSame('46cc2175f60cf8d62e24774bc4ecefdffb8047f60877dc95d85cd543d34e780a', hash_file('sha256', resource_path('ip/qqwry.dat')));
        $reader = new QqwryLocation();
        $this->assertNotSame('未知', $reader->lookup('8.8.8.8')['region']);
        $this->assertSame('浙江省', $reader->lookup('223.5.5.5')['province']);
        $this->assertSame('未知', $reader->lookup('2400:cb00::1')['region']);
    }

    public function test_direct_and_redirected_gbk_records_and_corrupt_offsets(): void
    {
        $directory = storage_path('framework/testing');
        if (!is_dir($directory)) mkdir($directory, 0755, true);
        $path = tempnam($directory, 'qqwry-test-');
        try {
            $country = mb_convert_encoding('广东省广州市', 'GB18030', 'UTF-8') . "\0";
            $area = mb_convert_encoding('测试网络', 'GB18030', 'UTF-8') . "\0";
            $pointer = fn ($number) => substr(pack('V', $number), 0, 3);
            foreach ([$country . $area, "\x01" . $pointer(16) . $country . $area,
                "\x02" . $pointer(16 + strlen($area)) . $area . $country] as $body) {
                $record = pack('V', ip2long('8.8.8.255')) . $body;
                $index = 8 + strlen($record);
                file_put_contents($path, pack('VV', $index, $index) . $record . pack('V', ip2long('8.8.8.0')) . $pointer(8));
                $reader = new QqwryLocation($path);
                $this->assertSame(['region' => '广东省广州市 测试网络', 'province' => '广东省'], $reader->lookup('8.8.8.8'));
                $this->assertSame('未知', $reader->lookup('8.8.9.0')['region']);
                unset($reader);
            }
            file_put_contents($path, pack('VV', 16, 16) . pack('V', ip2long('8.8.8.255')) . "\x01" . $pointer(12)
                . pack('V', ip2long('8.8.8.0')) . $pointer(8));
            $reader = new QqwryLocation($path);
            $this->assertSame('未知', $reader->lookup('8.8.8.8')['region']);
            unset($reader);
        } finally {
            unlink($path);
        }
    }

    public function test_province_aliases_and_foreign_country_classification(): void
    {
        foreach (['中国广东省广州市' => '广东省', '广西南宁市' => '广西壮族自治区', '中国' => '未知', '美国 加利福尼亚州' => '美国', '美国加利福尼亚州' => '美国'] as $input => $expected) {
            $this->assertSame($expected, IpProvince::fromChinese($input));
        }
        $this->assertSame('陕西省', IpProvince::fromExternal(['country_code' => 'CN', 'region_name' => 'Shaanxi']));
        $this->assertSame('美国', IpProvince::fromExternal(['country_code' => 'US', 'country_name' => '美国', 'region_name' => 'California']));
        $this->assertSame('未知', IpProvince::fromExternal(['country_code' => 'CN', 'region_name' => 'unrecognized']));
    }

    public function test_chinese_provinces_accept_database_separators_without_guessing_unknown_locations(): void
    {
        foreach (['–', '-', '—', '－', '/', ' ', ''] as $separator) {
            $this->assertSame('上海市', IpProvince::fromChinese("中国{$separator}上海{$separator}上海{$separator}宝山区"));
            $this->assertSame('广东省', IpProvince::fromChinese("中国{$separator}广东{$separator}广州"));
            $this->assertSame('未知', IpProvince::fromChinese("中国{$separator}未知地区"));
        }
        $this->assertSame('未知', IpProvince::fromChinese('中国–未知地区 上海电信'));
        $this->assertSame('美国', IpProvince::fromChinese('美国–加利福尼亚州'));
        $this->assertSame('未知', IpProvince::fromChinese(''));
    }
}
