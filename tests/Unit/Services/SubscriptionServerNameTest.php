<?php

namespace Tests\Unit\Services;

use App\Services\SubscriptionServerName;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SubscriptionServerNameTest extends TestCase
{
    public static function countries(): array
    {
        return [
            ['香港 01', 'HK'], ['中国香港', 'HK'], ['中國台灣', 'TW'], ['印度尼西亚', 'ID'],
            ['日本东京', 'JP'], ['大阪 02', 'JP'], ['SG01', 'SG'], ['ＨＫ02', 'HK'],
            ['Hong-Kong 03', 'HK'], ['Singapore', 'SG'], ['Los Angeles', 'US'], ['LA01', 'US'],
            ['中国移动 日本', 'JP'], ['CN2 美国', 'US'], ['HKBN 01', 'HK'], ['PCCW 香港', 'HK'],
            ['马来西亚', 'MY'], ['英国 02', 'GB'], ['德国法兰克福', 'DE'], ['新西兰', 'NZ'],
            ['未知节点', null], ['亚洲高速', null], ['HKTunnel', null], ['ChinaMobile', null],
        ];
    }

    #[DataProvider('countries')]
    public function test_country_detection(string $name, ?string $code): void
    {
        $flag = $code === null ? '' : mb_chr(0x1F1E6 + ord($code[0]) - 65) . mb_chr(0x1F1E6 + ord($code[1]) - 65) . ' ';
        $this->assertSame($flag . $name, SubscriptionServerName::format($name, 1, true, false));
    }

    public static function rates(): array
    {
        return [[0, '｜0×'], ['0.80000000', '｜0.8×'], [2, '｜2×'], [1, ''], ['1.0000', ''],
            [0.12345678, '｜0.12345678×'], [0.00000001, '｜0.00000001×'], [null, ''], ['invalid', ''], [-1, '']];
    }

    #[DataProvider('rates')]
    public function test_actual_rate_format(mixed $rate, string $suffix): void
    {
        $this->assertSame('香港' . $suffix, SubscriptionServerName::format('香港', $rate, false, true));
    }

    public function test_disabled_existing_flag_and_repeated_formatting(): void
    {
        $name = '🇭🇰 香港｜0.5×';
        $this->assertSame($name, SubscriptionServerName::format($name, 0.8, false, false));
        $formatted = SubscriptionServerName::format($name, 0.8, true, true);
        $this->assertSame('🇭🇰 香港｜0.8×', $formatted);
        $this->assertSame($formatted, SubscriptionServerName::format($formatted, 0.8, true, true));
        $this->assertSame('🇭🇰 香港', SubscriptionServerName::format($formatted, 1, true, true));
        $this->assertSame('🇺🇸 日本', SubscriptionServerName::format('🇺🇸 日本', 1, true, false));
    }
}
