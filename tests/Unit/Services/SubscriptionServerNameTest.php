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
            ['格陵兰 01', 'GL'], ['格陵蘭 02', 'GL'], ['Greenland', 'GL'], ['greenland', 'GL'],
            ['GL01', 'GL'], ['ＧＬ02', 'GL'], ['Nuuk', 'GL'], ['法罗群岛', 'FO'],
            ['马恩岛', 'IM'], ['泽西岛', 'JE'], ['直布罗陀', 'GI'], ['库拉索', 'CW'],
            ['Curaçao', 'CW'], ['Curacao', 'CW'], ['刚果（金）', 'CD'], ['刚果(金)', 'CD'],
            ['刚果（布）', 'CG'], ['Democratic Republic of the Congo', 'CD'], ['North Korea', 'KP'],
            ['Guinea-Bissau', 'GW'], ['Papua New Guinea', 'PG'], ['格陵兰-日本', 'GL'],
            ['日本-格陵兰', 'JP'], ['Laos', 'LA'], ['New Jersey', 'US'],
            ['未知节点', null], ['亚洲高速', null], ['HKTunnel', null], ['ChinaMobile', null],
            ['node in us', null], ['send to me', null], ['is this ok', null], ['gl01', null],
            ['GLTunnel', null], ['EU', null], ['UN', null], ['Georgia', null],
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

    public function test_every_country_alias_formats_without_collision_or_duplicate_flag(): void
    {
        $countries = json_decode(file_get_contents(resource_path('data/node-country-aliases.json')), true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(257, $countries);
        $this->assertCount(257, array_unique(array_column($countries, 0)));
        foreach ($countries as [$code, $chinese, $english, $abbreviations]) {
            $flag = mb_chr(0x1F1E6 + ord($code[0]) - 65) . mb_chr(0x1F1E6 + ord($code[1]) - 65);
            foreach (array_merge($chinese, $english, $abbreviations) as $alias) {
                $name = $alias . ' 01';
                $this->assertSame($flag . ' ' . $name, SubscriptionServerName::format($name, 1, true, false), $code . ': ' . $alias);
            }
            $name = $flag . ' ' . $chinese[0];
            $this->assertSame($name, SubscriptionServerName::format($name, 1, true, false));
        }
    }
}
