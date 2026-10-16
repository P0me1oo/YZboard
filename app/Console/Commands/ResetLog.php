<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ResetLog extends Command
{
    protected $builder;
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reset:log
        {--batch=500 : 每批最多删除的历史记录数}
        {--batches=100 : 单轮最多批次数}
        {--max-seconds=20 : 达到该时长后不再开始下一批}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '清空日志';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $size = max(1, min(500, (int) $this->option('batch')));
        $batches = max(1, min(1000, (int) $this->option('batches')));
        $seconds = max(1, min(300, (int) $this->option('max-seconds')));
        $started = hrtime(true);
        $cutoff = \App\Services\TrafficStatisticsRetention::dailyStart()->timestamp;
        $hourCutoff = \App\Services\TrafficStatisticsRetention::hourlyStart()->timestamp;
        $today = \App\Services\TrafficStatisticsRetention::today()->timestamp;
        $deleted = 0;
        for ($round = 0; $round < $batches; $round++) {
            $roundDeleted = 0;
            $roundDeleted += $this->deleteIds('v2_stat_user', 'id', fn ($q) => $q->where('record_at', '<', $cutoff), $size);
            $roundDeleted += $this->deleteComposite('v2_stat_user_inbound_ip', ['user_id', 'ip', 'record_at'], fn ($q) => $q->where('record_at', '<', $hourCutoff), $size);
            $roundDeleted += $this->deleteIds('v2_stat_server', 'id', fn ($q) => $q->where('record_at', '<', $cutoff), $size);
            $roundDeleted += $this->deleteProxy($cutoff, $hourCutoff, $today, $size);
            $roundDeleted += $this->deleteIds('v2_stat_user_server', 'id', fn ($q) => $q->where('record_at', '<', $cutoff), $size);
            $roundDeleted += $this->deleteIds('v2_stat_user_route_source', 'id', fn ($q) => $q->where('record_at', '<', $cutoff), $size);
            $roundDeleted += $this->deleteIds('v2_stat_route_hour', 'id', fn ($q) => $q->where('record_at', '<', $hourCutoff), $size);
            $roundDeleted += $this->deleteIds('v2_stat_route_minute', 'id', fn ($q) => $q->where('record_at', '<', $today), $size);
            $roundDeleted += $this->deleteOrphanIps($size);
            $roundDeleted += $this->deleteOldNames($cutoff, $size);
            $roundDeleted += $this->deleteIds('v2_admin_audit_log', 'id', fn ($q) => $q->where('created_at', '<', strtotime('-3 month', time())), $size);
            $deleted += $roundDeleted;
            if ($roundDeleted === 0 || hrtime(true) - $started >= $seconds * 1_000_000_000) break;
        }
        $this->info("已分批清理 {$deleted} 条历史记录。保留周期和防重记录未改变。");
        return self::SUCCESS;
    }

    private function deleteIds(string $table, string $key, callable $scope, int $size): int
    {
        return DB::transaction(function () use ($table, $key, $scope, $size): int {
            $query = DB::table($table); $scope($query);
            $ids = (clone $query)->orderBy($key)->limit($size)->lockForUpdate()->pluck($key);
            return $ids->isEmpty() ? 0 : $query->whereIn($key, $ids->all())->delete();
        }, 3);
    }

    private function deleteComposite(string $table, array $keys, callable $scope, int $size): int
    {
        return DB::transaction(function () use ($table, $keys, $scope, $size): int {
            $query = DB::table($table); $scope($query);
            $cursor = clone $query;
            foreach ($keys as $key) $cursor->orderBy($key);
            $rows = $cursor->limit($size)->lockForUpdate()->get($keys);
            if ($rows->isEmpty()) return 0;
            $query->where(function ($part) use ($rows, $keys): void {
                foreach ($rows as $row) $part->orWhere(function ($one) use ($row, $keys): void {
                    foreach ($keys as $key) $one->where($key, $row->$key);
                });
            });
            return $query->delete();
        }, 3);
    }

    private function deleteProxy(int $cutoff, int $hourCutoff, int $today, int $size): int
    {
        return $this->deleteComposite('v2_stat_proxy_traffic', ['scope', 'unit', 'record_at', 'entity_id'], function ($q) use ($cutoff, $hourCutoff, $today): void {
            $q->where(function ($part) use ($cutoff, $hourCutoff, $today): void {
                $part->where('record_at', '<', $cutoff)
                    ->orWhere(fn ($fine) => $fine->where('unit', 'hour')->where('record_at', '<', $hourCutoff))
                    ->orWhere(fn ($fine) => $fine->where('unit', 'minute')->where('record_at', '<', $today));
            });
        }, $size);
    }

    private function deleteOrphanIps(int $size): int
    {
        return DB::transaction(function () use ($size): int {
            $query = DB::table('v2_inbound_ip')->whereNotExists(fn ($q) => $q->selectRaw('1')->from('v2_stat_user_inbound_ip')
                ->whereColumn('v2_stat_user_inbound_ip.ip', 'v2_inbound_ip.ip'));
            $ips = (clone $query)->orderBy('ip')->limit($size)->lockForUpdate()->pluck('ip');
            return $ips->isEmpty() ? 0 : $query->whereIn('ip', $ips->all())->delete();
        }, 3);
    }

    private function deleteOldNames(int $cutoff, int $size): int
    {
        return $this->deleteIds('v2_stat_server_name', 'server_id', function ($q) use ($cutoff): void {
            $q->where('deleted_at', '<', $cutoff)
                ->whereNotExists(fn ($sub) => $sub->selectRaw('1')->from('v2_stat_server')->whereColumn('v2_stat_server.server_id', 'v2_stat_server_name.server_id'))
                ->whereNotExists(fn ($sub) => $sub->selectRaw('1')->from('v2_stat_user_server')->whereColumn('v2_stat_user_server.server_id', 'v2_stat_server_name.server_id'))
                ->whereNotExists(fn ($sub) => $sub->selectRaw('1')->from('v2_stat_user_route_source')->whereColumn('v2_stat_user_route_source.server_id', 'v2_stat_server_name.server_id'));
        }, $size);
    }
}
