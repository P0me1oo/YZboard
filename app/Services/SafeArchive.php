<?php

namespace App\Services;

use App\Exceptions\ApiException;
use ZipArchive;

final class SafeArchive
{
    public static function validate(ZipArchive $zip): void
    {
        if ($zip->numFiles < 1 || $zip->numFiles > 10000) throw new ApiException('压缩包文件数量超出限制', 422);
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = $stat['name'] ?? '';
            $segments = explode('/', $name);
            if ($name === '' || strlen($name) > 1024 || count($segments) > 32 || str_contains($name, '\\')
                || str_contains($name, "\0") || str_starts_with($name, '/') || preg_match('/\A[A-Za-z]:/', $name)
                || in_array('..', $segments, true) || in_array('.', $segments, true)) {
                throw new ApiException('压缩包包含不安全的文件路径', 422);
            }
            $zip->getExternalAttributesIndex($i, $system, $attributes);
            if ($system === ZipArchive::OPSYS_UNIX && (($attributes >> 16) & 0170000) === 0120000) {
                throw new ApiException('压缩包不能包含软链接', 422);
            }
            $size = (int) ($stat['size'] ?? 0);
            $total += $size;
            if ($size > 64 * 1024 * 1024 || $total > 256 * 1024 * 1024) {
                throw new ApiException('压缩包解压大小超出限制', 422);
            }
            if (($stat['encryption_method'] ?? 0) !== 0) throw new ApiException('不支持加密压缩包', 422);
        }
    }
}
