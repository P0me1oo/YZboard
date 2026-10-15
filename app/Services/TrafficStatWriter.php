<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use OverflowException;

/** 只合并调用方已计算好的整数；不持有跨调用状态，不创建或提交事务。 */
class TrafficStatWriter
{
    public static function add(string $table, array $rows, array $keys, array $columns, array $replace = []): void
    {
        if ($rows === []) return;
        $grouped = [];
        foreach ($rows as $row) {
            $key = json_encode(array_map(fn ($column) => $row[$column], $keys), JSON_THROW_ON_ERROR);
            if (!isset($grouped[$key])) {
                $grouped[$key] = $row;
                continue;
            }
            foreach ($columns as $column) {
                $sum = $grouped[$key][$column] + $row[$column];
                if (!is_int($sum)) throw new OverflowException('流量统计合计超出整数范围');
                $grouped[$key][$column] = $sum;
            }
            foreach ($replace as $column) $grouped[$key][$column] = $row[$column];
        }
        ksort($grouped, SORT_STRING);
        $mysql = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
        $updates = $replace;
        foreach ($columns as $column) {
            $updates[$column] = DB::raw($mysql ? "{$column} + VALUES({$column})" : "{$table}.{$column} + excluded.{$column}");
        }
        // 兼容 SQLite 的绑定数量限制；所有分块仍在调用方原来的事务中。
        $size = max(1, min(128, intdiv(900, count(reset($grouped)))));
        foreach (array_chunk(array_values($grouped), $size) as $chunk) {
            DB::table($table)->upsert($chunk, $keys, $updates);
        }
    }
}
