<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class TrafficPayload
{
    /** 上限兼容 JSON 的精确整数，禁止负数、浮点、科学计数和溢出转换。 */
    public const MAX_BYTES = 9007199254740991;

    public static function normalize(array $traffic): array
    {
        $result = [];
        foreach ($traffic as $id => $pair) {
            $uid = self::integer($id, PHP_INT_MAX);
            if ($uid === null || $uid < 1 || !is_array($pair) || array_keys($pair) !== [0, 1]) self::invalid();
            $u = self::integer($pair[0], self::MAX_BYTES);
            $d = self::integer($pair[1], self::MAX_BYTES);
            if ($u === null || $d === null || $u > self::MAX_BYTES - $d) self::invalid();
            if ($u > 0 || $d > 0) $result[$uid] = [$u, $d];
        }
        return $result;
    }

    public static function integer(mixed $value, int $max): ?int
    {
        if (!is_int($value) && !(is_string($value) && preg_match('/\A(?:0|[1-9][0-9]*)\z/', $value))) return null;
        $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => $max]]);
        return $number === false ? null : $number;
    }

    public static function validateRate(array $traffic, mixed $rate): void
    {
        if (!is_numeric($rate) || !is_finite((float) $rate) || (float) $rate < 0) self::invalid();
        $total = 0;
        foreach ($traffic as [$u, $d]) {
            if ($u > self::MAX_BYTES - $d || $total > self::MAX_BYTES - $u - $d) self::invalid();
            $total += $u + $d;
        }
        if ($total * (float) $rate > self::MAX_BYTES) self::invalid();
    }

    private static function invalid(): never
    {
        throw ValidationException::withMessages(['traffic' => '流量必须为范围内的非负整数']);
    }
}
