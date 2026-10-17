<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class SubscriptionStatisticsWriter
{
    public function __construct(private readonly SubscriptionTimeCodec $codec) {}

    /** 批次去重、汇总和时间块同事务提交；失败后用原批次重试。 */
    public function write(string $batchId, array $events): void
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $batchId) || count($events) > 500) throw new \InvalidArgumentException('订阅统计批次无效');
        $today = CarbonImmutable::now(config('app.timezone'))->startOfDay();
        $groups = [];
        foreach ($events as $event) {
            $at = (int) $event['at'];
            $day = CarbonImmutable::createFromTimestamp($at, config('app.timezone'))->startOfDay()->timestamp;
            if ($day < $today->subDays(89)->timestamp || $at > now()->timestamp) continue;
            $row = [];
            foreach (['user_id', 'ip', 'client', 'client_version', 'format', 'status'] as $field) $row[$field] = $event[$field];
            $row['client_key'] = hash('sha256', $row['client'] . ':' . $row['client_version']);
            $row['day'] = $day;
            $identity = hash('sha256', json_encode(array_values($row), JSON_THROW_ON_ERROR));
            $groups[$identity]['row'] = $row;
            $groups[$identity]['times'][] = $at;
        }
        ksort($groups);
        DB::transaction(function () use ($batchId, $groups, $today): void {
            if (!DB::table('v2_subscription_batch')->insertOrIgnore(['id' => $batchId, 'created_at' => now()->timestamp])) return;
            foreach ($groups as $identity => $group) {
                $times = $group['times'];
                $row = $group['row'] + ['identity' => $identity, 'count' => 0, 'first_at' => min($times), 'last_at' => max($times)];
                DB::table('v2_subscription_stat')->insertOrIgnore($row);
                $stat = DB::table('v2_subscription_stat')->where('identity', $identity)->lockForUpdate()->first();
                if (!$stat) throw new \RuntimeException('订阅日统计写入失败');
                DB::table('v2_subscription_stat')->where('id', $stat->id)->update([
                    'count' => $stat->count + count($times), 'first_at' => min($stat->first_at, min($times)),
                    'last_at' => max($stat->last_at, max($times)),
                ]);
                if ($row['day'] >= $today->subDays(6)->timestamp) {
                    DB::table('v2_subscription_block')->insert(['stat_id' => $stat->id, 'day' => $row['day']] + $this->codec->encode($times));
                }
            }
        }, 3);
    }

    /** 合并小块时不读写日计数，校验通过才在同事务中替换。 */
    public function compact(int $limit = 200): int
    {
        $ids = DB::table('v2_subscription_block')->where('count', '<', SubscriptionTimeCodec::MAX_COUNT)
            ->select('stat_id')->groupBy('stat_id')->havingRaw('COUNT(*) > 1')->orderBy('stat_id')->limit($limit)->pluck('stat_id');
        $merged = 0;
        foreach ($ids as $id) {
            $merged += DB::transaction(function () use ($id): int {
                $stat = DB::table('v2_subscription_stat')->where('id', $id)->lockForUpdate()->first();
                if (!$stat) return 0;
                $blocks = DB::table('v2_subscription_block')->where('stat_id', $id)->where('count', '<', SubscriptionTimeCodec::MAX_COUNT)
                    ->orderBy('id')->limit(256)->get();
                if ($blocks->count() < 2) return 0;
                $times = [];
                $replaced = [];
                foreach ($blocks as $block) {
                    if (count($replaced) >= 2 && count($times) + $block->count > SubscriptionTimeCodec::MAX_COUNT * 4) break;
                    array_push($times, ...$this->codec->decode((array) $block));
                    $replaced[] = $block->id;
                }
                sort($times, SORT_NUMERIC);
                foreach (array_chunk($times, SubscriptionTimeCodec::MAX_COUNT) as $chunk) {
                    $encoded = $this->codec->encode($chunk);
                    if ($this->codec->decode($encoded) !== $chunk) throw new \RuntimeException('订阅时间块合并校验失败');
                    DB::table('v2_subscription_block')->insert(['stat_id' => $id, 'day' => $stat->day] + $encoded);
                }
                DB::table('v2_subscription_block')->whereIn('id', $replaced)->delete();
                return count($replaced);
            }, 3);
        }
        return $merged;
    }

    public function prune(): void
    {
        $today = CarbonImmutable::now(config('app.timezone'))->startOfDay();
        // 小批清理避免长事务；查询也限制保留边界，不依赖清理准时执行。
        foreach ([['v2_subscription_block', 'day', $today->subDays(6)->timestamp],
            ['v2_subscription_stat', 'day', $today->subDays(89)->timestamp],
            ['v2_subscription_batch', 'created_at', $today->subDays(90)->timestamp]] as [$table, $column, $cutoff]) {
            do {
                $ids = DB::table($table)->where($column, '<', $cutoff)->orderBy('id')->limit(1000)->pluck('id');
                if ($ids->isNotEmpty()) DB::table($table)->whereIn('id', $ids)->delete();
            } while ($ids->count() === 1000);
        }
    }
}
