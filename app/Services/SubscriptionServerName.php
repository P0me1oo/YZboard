<?php

namespace App\Services;

class SubscriptionServerName
{
    private static ?array $countryRules = null;

    /** 只处理订阅输出名称，不改节点原名或计费倍率。 */
    public static function format(string $name, mixed $rate, bool $showFlag, bool $showRate): string
    {
        if ($showFlag && !preg_match('/[\x{1F1E6}-\x{1F1FF}]{2}/u', $name)) {
            $code = self::country($name);
            if ($code !== null) {
                $name = mb_chr(0x1F1E6 + ord($code[0]) - 65) . mb_chr(0x1F1E6 + ord($code[1]) - 65) . ' ' . $name;
            }
        }
        if ($showRate && is_numeric($rate) && is_finite((float) $rate) && (float) $rate >= 0) {
            // 已有相同格式的倍率尾缀由实际值替换，避免重复或沿用过期倍率。
            $name = preg_replace('/｜\s*\d+(?:\.\d+)?\s*[xX×]\s*$/u', '', $name);
            if ((float) $rate !== 1.0) {
                $name .= '｜' . rtrim(rtrim(number_format((float) $rate, 8, '.', ''), '0'), '.') . '×';
            }
        }
        return $name;
    }

    public static function country(string $name): ?string
    {
        // 国家、城市和缩写沿用管理端节点分类词表；不把运营商名称当成地区。
        self::$countryRules ??= self::buildCountryRules();
        $name = self::normalizeName($name);
        $name = preg_replace('/中国(?:移动|联通|电信)|中國(?:移動|聯通|電信)|CN2/u', ' ', $name);
        $best = null;
        $bestPosition = PHP_INT_MAX;
        $bestLength = 0;
        if (preg_match_all('/[\x{1F1E6}-\x{1F1FF}]{2}/u', $name, $flags, PREG_OFFSET_CAPTURE)) {
            $codes = array_column(self::$countryRules, 0);
            foreach ($flags[0] as [$flag, $position]) {
                $code = chr(mb_ord(mb_substr($flag, 0, 1)) - 0x1F1E6 + 65)
                    . chr(mb_ord(mb_substr($flag, 1, 1)) - 0x1F1E6 + 65);
                if (in_array($code, $codes, true)) {
                    $best = $code;
                    $bestPosition = $position;
                    $bestLength = 2;
                    break;
                }
            }
        }
        foreach (self::$countryRules as [$code, $pattern, $length]) {
            if (!preg_match($pattern, $name, $match, PREG_OFFSET_CAPTURE)) continue;
            $position = $match[0][1];
            if ($position < $bestPosition || ($position === $bestPosition && $length > $bestLength)) {
                $best = $code;
                $bestPosition = $position;
                $bestLength = $length;
            }
        }
        return $best;
    }

    /** 词表固定，名称规范化和规则生成只做一次，避免每个订阅节点重复处理。 */
    private static function buildCountryRules(): array
    {
        $countries = json_decode(file_get_contents(resource_path('data/node-country-aliases.json')), true, 512, JSON_THROW_ON_ERROR);
        $rules = [];
        foreach ($countries as [$code, $chinese, $english, $abbreviations]) {
            foreach ($chinese as $alias) {
                $alias = self::normalizeName($alias);
                $rules[] = [$code, '/' . preg_quote($alias, '/') . '/u', mb_strlen($alias)];
            }
            foreach ($english as $alias) {
                $alias = self::normalizeName($alias);
                $rules[] = [$code, '/(?<![A-Za-z])' . str_replace(' ', '[\s_-]*', preg_quote($alias, '/')) . '(?![A-Za-z])/iu', mb_strlen($alias)];
            }
            foreach ($abbreviations as $alias) {
                $rules[] = [$code, '/(?<![A-Za-z])' . preg_quote($alias, '/') . '(?![A-Za-z])/u', mb_strlen($alias)];
            }
        }
        return $rules;
    }

    private static function normalizeName(string $name): string
    {
        $name = mb_convert_kana($name, 'as', 'UTF-8');
        if (class_exists(\Normalizer::class)) {
            $name = preg_replace('/\p{Mn}/u', '', \Normalizer::normalize($name, \Normalizer::FORM_KD));
        }
        return $name;
    }
}
