<?php

namespace App\Services;

use RuntimeException;

/** 秒级时间差编码允许零差值，同秒的多次请求各保留一次。 */
class SubscriptionTimeCodec
{
    public const MAX_COUNT = 4096;

    public function encode(array $times): array
    {
        if (!$times || count($times) > self::MAX_COUNT) throw new RuntimeException('订阅时间块数量无效');
        sort($times, SORT_NUMERIC);
        $raw = '';
        $previous = 0;
        foreach ($times as $at) {
            if (!is_int($at) || $at < 0 || $at > 4294967295) throw new RuntimeException('订阅时间无效');
            $delta = $at - $previous;
            do {
                $byte = $delta & 127;
                $delta >>= 7;
                $raw .= chr($byte | ($delta ? 128 : 0));
            } while ($delta);
            $previous = $at;
        }
        $compressed = gzdeflate($raw, 6);
        $codec = $compressed !== false && strlen($compressed) < strlen($raw) ? 2 : 1;
        return ['first_at' => $times[0], 'last_at' => $times[count($times) - 1], 'count' => count($times),
            'codec' => $codec, 'checksum' => hash('sha256', $raw),
            'payload' => base64_encode($codec === 2 ? $compressed : $raw)];
    }

    public function decode(array $block): array
    {
        $count = (int) $block['count'];
        $raw = base64_decode($block['payload'], true);
        if ($count < 1 || $count > self::MAX_COUNT || $raw === false || strlen($raw) > self::MAX_COUNT * 5
            || !in_array((int) $block['codec'], [1, 2], true)) throw new RuntimeException('订阅时间块无效');
        if ((int) $block['codec'] === 2) $raw = @gzinflate($raw, self::MAX_COUNT * 5);
        if ($raw === false || !hash_equals($block['checksum'], hash('sha256', $raw))) throw new RuntimeException('订阅时间块校验失败');
        $times = [];
        $at = 0;
        for ($i = 0, $length = strlen($raw); $i < $length;) {
            $delta = 0;
            $shift = 0;
            do {
                if ($i >= $length || $shift > 28) throw new RuntimeException('订阅时间块截断');
                $byte = ord($raw[$i++]);
                $delta |= ($byte & 127) << $shift;
                $shift += 7;
            } while ($byte & 128);
            $at += $delta;
            if ($at > 4294967295 || count($times) >= $count) throw new RuntimeException('订阅时间块越界');
            $times[] = $at;
        }
        if (count($times) !== $count || $times[0] !== (int) $block['first_at'] || end($times) !== (int) $block['last_at']) {
            throw new RuntimeException('订阅时间块边界不一致');
        }
        return $times;
    }
}
