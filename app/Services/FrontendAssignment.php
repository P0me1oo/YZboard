<?php

namespace App\Services;

/** 加权稳定分配：同一标签和候选集结果不变，撤下成员只影响分到该成员的用户。 */
class FrontendAssignment
{
    public static function choose(string $label, array $candidates): ?int
    {
        $winner = null;
        $best = INF;
        foreach ($candidates as $candidate) {
            $weight = (float) ($candidate['weight'] ?? 1);
            if ($weight <= 0 || !is_finite($weight) || empty($candidate['addresses'])) continue;
            // 取 52 位整数，避免浮点舍入到 1；哈希只使用随机域名标签与成员编号。
            $number = hexdec(substr(hash('sha256', $label . ':' . $candidate['member_id']), 0, 13));
            $score = -log(($number + 1) / 4503599627370497) / $weight;
            if ($score < $best || ($score === $best && $candidate['member_id'] < $winner)) {
                $best = $score;
                $winner = (int) $candidate['member_id'];
            }
        }
        return $winner;
    }
}
