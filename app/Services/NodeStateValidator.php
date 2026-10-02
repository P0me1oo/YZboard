<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

/** 直接遍历固定结构，避免为每个用户和 IP 展开通配规则。 */
final class NodeStateValidator
{
    public static function validate(array $state): array
    {
        $depths = [
            'alive' => 2, 'online' => 1, 'connection_counts' => 1,
            'relay_user_alive' => 3, 'relay_connection_counts' => 2,
            'user_speeds' => 0, 'status' => 0, 'metrics' => 0,
        ];
        $state = array_intersect_key($state, $depths);
        foreach ($state as $field => $value) {
            if (!is_array($value)) self::fail($field);
            if ($depths[$field] > 0) {
                self::walk($value, $depths[$field], $field, in_array($field, ['alive', 'relay_user_alive'], true));
            }
        }
        foreach ($state['user_speeds'] ?? [] as $userId => $speeds) {
            if (filter_var($userId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false
                || !is_array($speeds) || array_keys($speeds) !== [0, 1]
                || !is_int($speeds[0]) || !is_int($speeds[1])
                || $speeds[0] < 0 || $speeds[1] < 0
                || $speeds[0] > 9007199254740991 || $speeds[1] > 9007199254740991) {
                self::fail('user_speeds');
            }
        }
        return $state;
    }

    private static function walk(array $values, int $depth, string $field, bool $ips): void
    {
        foreach ($values as $value) {
            if ($depth > 1) {
                if (!is_array($value)) self::fail($field);
                self::walk($value, $depth - 1, $field, $ips);
            } elseif ($ips
                ? filter_var($value, FILTER_VALIDATE_IP) === false
                : filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false) {
                self::fail($field);
            }
        }
    }

    private static function fail(string $field): never
    {
        throw ValidationException::withMessages(['state.' . $field => '节点状态的数据结构或取值无效。']);
    }
}
