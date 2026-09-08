<?php

namespace App\Utils;

use InvalidArgumentException;

/** 解析 Hysteria2 端口列表，不把连续范围展开为逐端口数组。 */
final class PortSet
{
    public static function parse(string $value): array
    {
        $ranges = [];
        foreach (explode(',', $value) as $item) {
            if (!preg_match('/^\s*([0-9]+)\s*(?:-\s*([0-9]+)\s*)?$/D', $item, $match)) {
                throw new InvalidArgumentException('端口格式无效，请填写单端口、连续范围或逗号分隔的组合');
            }
            $first = (int) $match[1];
            $last = isset($match[2]) ? (int) $match[2] : $first;
            if ($first < 1 || $last > 65535 || $first > $last) {
                throw new InvalidArgumentException('端口必须在 1 到 65535 之间，范围起点不能大于终点');
            }
            $ranges[] = [$first, $last];
        }
        usort($ranges, fn (array $left, array $right) => $left[0] <=> $right[0]);
        $merged = [];
        foreach ($ranges as [$first, $last]) {
            $index = count($merged) - 1;
            if ($index >= 0 && $first <= $merged[$index][1] + 1) {
                $merged[$index][1] = max($merged[$index][1], $last);
            } else {
                $merged[] = [$first, $last];
            }
        }
        return $merged;
    }

    public static function random(string $value): int
    {
        $ranges = self::parse($value);
        $count = array_sum(array_map(fn (array $range) => $range[1] - $range[0] + 1, $ranges));
        $offset = random_int(0, $count - 1);
        foreach ($ranges as [$first, $last]) {
            $length = $last - $first + 1;
            if ($offset < $length) {
                return $first + $offset;
            }
            $offset -= $length;
        }
        throw new InvalidArgumentException('端口列表为空');
    }

    public static function singBoxRanges(string $value): array
    {
        return array_map(fn (array $range) => "{$range[0]}:{$range[1]}", self::parse($value));
    }

    public static function normalize(string $value): string
    {
        return implode(',', array_map(
            fn (array $range) => $range[0] === $range[1] ? (string) $range[0] : "{$range[0]}-{$range[1]}",
            self::parse($value)
        ));
    }

    public static function isMultiple(string $value): bool
    {
        return str_contains($value, '-') || str_contains($value, ',');
    }
}
