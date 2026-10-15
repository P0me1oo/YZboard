<?php

namespace App\Services;

/** 由调用方为一次快照或一轮同步创建，包含未找到的结果，不跨轮保存。 */
class RealtimeReadContext
{
    private array $records = [];

    public function __construct(private readonly RealtimeStateStore $states)
    {
    }

    public function readMany(array $sources): array
    {
        $sources = array_values(array_unique($sources));
        $missing = array_values(array_diff($sources, array_keys($this->records)));
        if ($missing !== []) {
            $this->records += $this->states->readMany($missing);
        }
        return array_intersect_key($this->records, array_fill_keys($sources, true));
    }
}
