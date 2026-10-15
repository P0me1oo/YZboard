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
        $cutoff = \App\Services\TrafficStatisticsRetention::dailyStart()->timestamp;
        $hourCutoff = \App\Services\TrafficStatisticsRetention::hourlyStart()->timestamp;
        $today = \App\Services\TrafficStatisticsRetention::today()->timestamp;
        StatUser::where('record_at', '<', $cutoff)->delete();
        DB::table('v2_stat_user_inbound_ip')->where('record_at', '<', $hourCutoff)->delete();
        DB::table('v2_inbound_ip')->whereNotExists(fn ($query) => $query->selectRaw('1')->from('v2_stat_user_inbound_ip')
            ->whereColumn('v2_stat_user_inbound_ip.ip', 'v2_inbound_ip.ip'))->delete();
        StatServer::where('record_at', '<', $cutoff)->delete();
        DB::table('v2_stat_proxy_traffic')->where('record_at', '<', $cutoff)
            ->orWhere(fn ($query) => $query->where('unit', 'hour')->where('record_at', '<', $hourCutoff))
            ->orWhere(fn ($query) => $query->where('unit', 'minute')->where('record_at', '<', $today))
            ->delete();
        DB::table('v2_stat_user_server')->where('record_at', '<', $cutoff)->delete();
        DB::table('v2_stat_user_route_source')->where('record_at', '<', $cutoff)->delete();
        DB::table('v2_stat_route_hour')->where('record_at', '<', $hourCutoff)->delete();
        DB::table('v2_stat_route_minute')->where('record_at', '<', $today)->delete();
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
