<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private const INDEXES = [
        'v2_stat_server' => [
            'v2_stat_server_server_id_index' => ['server_id'],
            'v2_stat_server_record_at_index' => ['record_at'],
            'v2_stat_server_u_index' => ['u'],
            'v2_stat_server_d_index' => ['d'],
        ],
        'v2_stat_user' => [
            'v2_stat_user_u_index' => ['u'],
            'v2_stat_user_d_index' => ['d'],
        ],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $targets) {
            $indexes = collect(Schema::getIndexes($table))->keyBy('name');
            foreach ($targets as $name => $columns) {
                $index = $indexes->get($name);
                if (!$index || $index['unique'] || $index['columns'] !== $columns) continue;
                // 重复索引只有在原始等价索引仍存在时才删除，不猜测外部修改过的结构。
                if ($table === 'v2_stat_server' && in_array($columns[0], ['server_id', 'record_at'], true)) {
                    $original = $indexes->get($columns[0]);
                    if (!$original || $original['columns'] !== $columns) continue;
                }
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($name));
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $targets) {
            foreach ($targets as $name => $columns) {
                if (!Schema::hasIndex($table, $name)) {
                    Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index($columns, $name));
                }
            }
        }
    }
};
