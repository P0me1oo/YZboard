<?php

namespace App\Services;

use Carbon\CarbonImmutable;

class SystemLogPruner
{
    public function prune(int $days): int
    {
        $directory = realpath(storage_path('logs'));
        if ($directory === false) return 0;
        // 文件按自然日轮转，保留今天及前 days - 1 天。
        $cutoff = CarbonImmutable::now(config('app.timezone'))->startOfDay()->subDays($days - 1);
        $deleted = 0;
        foreach (new \DirectoryIterator($directory) as $file) {
            if ($file->isLink() || !$file->isFile()) continue;
            $name = $file->getFilename();
            if (preg_match('/\A(?:laravel|deprecations)-(\d{4}-\d{2}-\d{2})\.log\z/', $name, $match)) {
                if ($match[1] >= $cutoff->toDateString()) continue;
            } elseif ($name === 'laravel.log') {
                // 兼容旧的单文件日志；新写入已使用按天轮转。
                if ($file->getMTime() >= $cutoff->timestamp) continue;
            } else {
                continue;
            }
            if (!unlink($file->getPathname())) throw new \RuntimeException('到期系统日志文件清理失败');
            $deleted++;
            if ($deleted >= 500) break;
        }
        return $deleted;
    }
}
