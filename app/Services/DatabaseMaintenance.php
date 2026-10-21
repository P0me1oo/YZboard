<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DatabaseMaintenance
{
    public const BATCH_SIZE = 500;
    public const PREVIEW_SECONDS = 600;

    // 仅开放已有定时清理规则中的历史表，不接受客户端指定表名或条件。
    public const TARGETS = [
        'access' => ['v2_proxy_access_log', 'connected_at'],
        'machine' => ['v2_server_machine_load_history', 'recorded_at'],
        'audit' => ['v2_admin_audit_log', 'created_at'],
        'telegram' => ['v2_telegram_bot_updates', 'created_at'],
    ];

    public function overview(): array
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();
        $warnings = [];
        $free = null;
        if ($driver === 'mysql') {
            $tables = array_map(fn ($row) => [
                'name' => $row->name, 'engine' => $row->engine,
                'rows' => $row->rows_count === null ? null : (int) $row->rows_count,
                'data_bytes' => (int) $row->data_bytes, 'index_bytes' => (int) $row->index_bytes,
                'total_bytes' => (int) $row->data_bytes + (int) $row->index_bytes,
            ], $connection->select('SELECT TABLE_NAME AS name, ENGINE AS engine, TABLE_ROWS AS rows_count,
                DATA_LENGTH AS data_bytes, INDEX_LENGTH AS index_bytes FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = ? ORDER BY DATA_LENGTH + INDEX_LENGTH DESC, TABLE_NAME',
                [$connection->getDatabaseName(), 'BASE TABLE']));
            $total = array_sum(array_column($tables, 'total_bytes'));
        } elseif ($driver === 'sqlite') {
            $pageSize = (int) $connection->selectOne('PRAGMA page_size')->page_size;
            $total = (int) $connection->selectOne('PRAGMA page_count')->page_count * $pageSize;
            $free = (int) $connection->selectOne('PRAGMA freelist_count')->freelist_count * $pageSize;
            $sizes = null;
            try {
                // dbstat 可选；缺失时保留总占用，不能把未知表大小当成零。
                $sizes = collect($connection->select("SELECT s.tbl_name AS name,
                    SUM(CASE WHEN s.type = 'table' THEN d.pgsize ELSE 0 END) AS data_bytes,
                    SUM(CASE WHEN s.type = 'index' THEN d.pgsize ELSE 0 END) AS index_bytes
                    FROM dbstat AS d JOIN sqlite_schema AS s ON s.name = d.name GROUP BY s.tbl_name"))->keyBy('name');
            } catch (\Illuminate\Database\QueryException) {
                $warnings[] = 'sqlite_table_sizes_unavailable';
            }
            $tables = array_map(function ($row) use ($sizes) {
                $size = $sizes?->get($row->name);
                return ['name' => $row->name, 'engine' => 'SQLite', 'rows' => null,
                    'data_bytes' => $size ? (int) $size->data_bytes : null,
                    'index_bytes' => $size ? (int) $size->index_bytes : null,
                    'total_bytes' => $size ? (int) $size->data_bytes + (int) $size->index_bytes : null];
            }, $connection->select("SELECT name FROM sqlite_schema WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"));
            usort($tables, fn ($a, $b) => ($b['total_bytes'] ?? -1) <=> ($a['total_bytes'] ?? -1) ?: strcmp($a['name'], $b['name']));
        } else {
            throw ValidationException::withMessages(['database' => '数据库分析目前支持 MySQL、MariaDB 和 SQLite。']);
        }
        $prefix = $connection->getTablePrefix();
        foreach ($tables as &$table) {
            $table['schema_name'] = str_starts_with($table['name'], $prefix) ? substr($table['name'], strlen($prefix)) : $table['name'];
            $table['cleanup_target'] = null;
            foreach (self::TARGETS as $key => [$name]) {
                if ($table['name'] === $prefix . $name) $table['cleanup_target'] = $key;
            }
        }
        unset($table);
        return ['driver' => $driver, 'total_bytes' => $total, 'free_bytes' => $free,
            'table_count' => count($tables), 'tables' => $tables, 'warnings' => $warnings,
            'checked_at' => now()->toIso8601String(), 'batch_size' => self::BATCH_SIZE];
    }

    public function preview(array $targets, int $adminId): array
    {
        $now = now();
        $cutoffs = ['access' => $now->timestamp - ProxyAccessLog::RETENTION_DAYS * 86400,
            'machine' => $now->copy()->subHours(24)->timestamp,
            'audit' => strtotime('-3 month', $now->timestamp),
            'telegram' => $now->timestamp - 7 * 86400];
        $items = [];
        foreach ($targets as $key) {
            if (!isset(self::TARGETS[$key])) throw ValidationException::withMessages(['targets' => '不支持的清理项目。']);
            [$table] = self::TARGETS[$key];
            $available = Schema::hasTable($table);
            $item = ['target' => $key, 'available' => $available, 'cutoff' => $cutoffs[$key], 'max_id' => 0, 'count' => 0, 'ids' => []];
            if ($available) {
                $item['max_id'] = (int) DB::table($table)->max('id');
                $item['count'] = $this->query($item)->count();
                // 预览即固定本轮批次，提交后即使结果缓存写入中断，重试也不会删除下一批。
                if ($item['count'] > 0) $item['ids'] = $this->query($item)->orderBy('id')->limit(self::BATCH_SIZE)->pluck('id')->all();
            }
            $items[] = $item;
        }
        $token = Str::random(48);
        $expires = $now->timestamp + self::PREVIEW_SECONDS;
        Cache::put($this->cacheKey($token), ['admin_id' => $adminId, 'items' => $items, 'expires_at' => $expires], self::PREVIEW_SECONDS);
        return ['token' => $token, 'expires_at' => $expires, 'items' => $this->publicItems($items), 'batch_size' => self::BATCH_SIZE];
    }

    public function cleanup(string $token, int $adminId): array
    {
        $key = $this->cacheKey($token);
        $lock = Cache::lock($this->cacheKey('lock'), 30);
        abort_unless($lock->get(), 429, '已有清理正在执行，请稍后重试。');
        try {
            $preview = Cache::get($key);
            if (!$preview || $preview['admin_id'] !== $adminId || $preview['expires_at'] <= now()->timestamp) {
                throw ValidationException::withMessages(['token' => '清理预览已过期，请重新预览。']);
            }
            if (isset($preview['result'])) return $preview['result'];
            // 单次最多四组、每组 500 条。任意一组失败则全部回滚，重试仍使用同一预览。
            $items = DB::transaction(function () use ($preview) {
                $items = [];
                foreach ($preview['items'] as $item) {
                    $deleted = 0;
                    if ($item['available'] && $item['count'] > 0) {
                        $query = $this->query($item);
                        $ids = (clone $query)->whereIn('id', $item['ids'])->orderBy('id')->limit(self::BATCH_SIZE)->lockForUpdate()->pluck('id');
                        if ($ids->isNotEmpty()) $deleted = $query->whereIn('id', $ids)->delete();
                    }
                    $items[] = ['target' => $item['target'], 'deleted' => $deleted,
                        'has_more' => $item['available'] && $this->query($item)->exists()];
                }
                return $items;
            }, 3);
            $result = ['items' => $items, 'deleted' => array_sum(array_column($items, 'deleted')),
                'has_more' => in_array(true, array_column($items, 'has_more'), true)];
            $preview['result'] = $result;
            Cache::put($key, $preview, max(1, $preview['expires_at'] - now()->timestamp));
            return $result;
        } finally {
            $lock->release();
        }
    }

    private function query(array $item): Builder
    {
        [$table, $column] = self::TARGETS[$item['target']];
        return DB::table($table)->where($column, '<', $item['cutoff'])->where('id', '<=', $item['max_id']);
    }

    private function publicItems(array $items): array
    {
        return array_map(fn ($item) => array_diff_key($item, ['max_id' => true, 'ids' => true]), $items);
    }

    private function cacheKey(string $token): string
    {
        $connection = DB::connection();
        return 'yz:database-maintenance:' . hash('sha256', $connection->getName() . ':' . $connection->getDatabaseName()) . ':' . $token;
    }
}
