<?php

namespace App\Console\Commands;

use App\Models\AdminAuditLog;
use App\Models\StatServer;
use App\Models\StatUser;
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
    protected $signature = 'reset:log';

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
        // 保留今天及此前 29 个自然日，与统计页的最近 30 天一致。
        $cutoff = now()->startOfDay()->subDays(29)->timestamp;
        StatUser::where('record_at', '<', $cutoff)->delete();
        StatServer::where('record_at', '<', $cutoff)->delete();
        DB::table('v2_stat_user_server')->where('record_at', '<', $cutoff)->delete();
        DB::table('v2_stat_traffic_hour')->where('record_at', '<', $cutoff)->delete();
        DB::table('v2_stat_node_hour')->where('record_at', '<', $cutoff)->delete();
        DB::table('v2_stat_user_route_source')->where('record_at', '<', $cutoff)->delete();
        foreach (['node_hour_detail', 'route_hour'] as $table) {
            DB::table('v2_stat_' . $table)->where('record_at', '<', $cutoff)->delete();
        }
        foreach (['node_minute_detail', 'route_minute'] as $table) {
            DB::table('v2_stat_' . $table)->where('record_at', '<', now()->startOfDay()->timestamp)->delete();
        }
        DB::table('v2_stat_server_name')->where('deleted_at', '<', $cutoff)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('v2_stat_server')
                ->whereColumn('v2_stat_server.server_id', 'v2_stat_server_name.server_id'))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('v2_stat_user_server')
                ->whereColumn('v2_stat_user_server.server_id', 'v2_stat_server_name.server_id'))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('v2_stat_user_route_source')
                ->whereColumn('v2_stat_user_route_source.server_id', 'v2_stat_server_name.server_id'))
            ->delete();
        AdminAuditLog::where('created_at', '<', strtotime('-3 month', time()))->delete();
    }
}
