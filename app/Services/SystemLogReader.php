<?php

namespace App\Services;

class SystemLogReader
{
    public function fetch(array $filters, int $page, int $size): array
    {
        $date = $filters['date'] ?? now()->toDateString();
        $dates = [];
        for ($i = 0; $i < 14; $i++) $dates[] = now()->subDays($i)->toDateString();
        abort_unless(in_array($date, $dates, true), 422, '系统日志仅支持最近 14 天');
        $directory = realpath(storage_path('logs'));
        $rows = []; $limited = false;
        foreach (['laravel-' . $date . '.log', 'deprecations-' . $date . '.log', ...( $date === now()->toDateString() ? ['laravel.log'] : [])] as $name) {
            $path = $directory ? realpath($directory . DIRECTORY_SEPARATOR . $name) : false;
            if (!$path || dirname($path) !== $directory || !is_file($path) || !is_readable($path)) continue;
            $handle = fopen($path, 'rb');
            if (!$handle) continue;
            try {
                $bytes = fstat($handle)['size'];
                $offset = max(0, $bytes - 2 * 1024 * 1024); $limited = $limited || $offset > 0;
                fseek($handle, $offset);
                if ($offset) fgets($handle);
                $text = stream_get_contents($handle, 2 * 1024 * 1024);
            } finally { fclose($handle); }
            // 只识别条目开头；多行正文与堆栈不单独作为日志行展示。
            preg_match_all('/^\[([^\]\r\n]+)\]\s+[^\s.]+\.([A-Z]+):\s*([^\r\n]*)/m', $text, $matches, PREG_SET_ORDER);
            foreach ($matches as $index => $match) {
                $at = strtotime($match[1]);
                if (!$at || date('Y-m-d', $at) !== $date) continue;
                $level = match (strtolower($match[2])) { 'warning', 'notice' => 'warn', 'critical', 'alert', 'emergency' => 'fatal', default => strtolower($match[2]) };
                $message = LogSanitizer::text($match[3]);
                if (!empty($filters['level']) && $filters['level'] !== $level) continue;
                if (!empty($filters['keyword']) && mb_stripos($message, $filters['keyword']) === false) continue;
                if (!empty($filters['start_at']) && $at < $filters['start_at'] || !empty($filters['end_at']) && $at > $filters['end_at']) continue;
                $rows[] = ['id' => $name . ':' . $index, 'occurred_at' => $at, 'source' => 'panel', 'level' => $level, 'message' => $message];
            }
        }
        usort($rows, fn ($a, $b) => $b['occurred_at'] <=> $a['occurred_at']);
        $total = count($rows);
        return ['list' => array_slice($rows, ($page - 1) * $size, $size), 'page' => $page, 'page_size' => $size,
            'total' => $total, 'last_page' => max(1, (int) ceil($total / $size)), 'date' => $date, 'dates' => $dates,
            'limited' => $limited, 'retention_days' => 14, 'timezone' => config('app.timezone')];
    }
}
