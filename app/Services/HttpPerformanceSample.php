<?php

namespace App\Services;

/** 只保存数字，不保留查询、缓存键或请求内容。 */
class HttpPerformanceSample
{
    public int $dbCount = 0;
    public float $dbMs = 0;
    public int $redisCount = 0;
    public float $redisMs = 0;
    public int $cacheReads = 0;
    public int $cacheWrites = 0;
}
