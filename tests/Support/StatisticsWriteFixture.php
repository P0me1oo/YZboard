<?php

namespace Tests\Support;

use App\Models\NodeReportBatch;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StatisticsWriteFixture
{
    public static function enable(): void
    {
        foreach (['traffic_hourly_started_at', 'node_hourly_started_at', 'user_route_statistics_started_at', 'fine_traffic_started_at'] as $name) {
            DB::table('v2_settings')->updateOrInsert(['name' => $name], ['value' => (string) now()->startOfDay()->subDays(29)->timestamp]);
        }
    }

    public static function make(int $count = 3): array
    {
        self::enable();
        $machines = [];
        foreach (['同机', '异机'] as $name) {
            $machines[] = ServerMachine::create(['name' => $name, 'is_active' => true, 'token' => Str::random(32)]);
        }
        $nodes = [];
        foreach (['入口', '同机落地', '异机落地'] as $index => $name) {
            $nodes[] = Server::create(['name' => $name, 'type' => 'vless', 'host' => 'example.test',
                'port' => 443, 'server_port' => 443, 'rate' => 1.125, 'group_ids' => [], 'enabled' => true,
                'machine_id' => $machines[$index === 2 ? 1 : 0]->id]);
        }
        $users = []; $traffic = []; $relayUsers = [];
        for ($i = 0; $i < $count; $i++) {
            $user = User::create(['email' => Str::random(16) . '@example.test', 'password' => Str::random(32),
                'uuid' => (string) Str::uuid(), 'token' => Str::random(32)]);
            $users[] = $user;
            $traffic[$user->id] = [101 + $i, 203 + $i];
            $relayUsers[$user->id] = [$nodes[1]->id => [31, 61], $nodes[2]->id => [11, 21]];
        }
        $snapshot = ['id' => $nodes[0]->id, 'rate' => 1.125,
            'relay_rates' => [$nodes[1]->id => 1.125, $nodes[2]->id => 2.75],
            'proxy_traffic' => ['version' => 1, 'entry_id' => $nodes[0]->id,
                'nodes' => collect($nodes)->mapWithKeys(fn ($node) => [$node->id => ['name' => $node->name, 'machine_id' => $node->machine_id]])->all(),
                'machines' => collect($machines)->pluck('name', 'id')->all()]];
        $batch = NodeReportBatch::create(['server_id' => $nodes[0]->id, 'server_type' => 'vless',
            'report_id' => Str::random(24), 'report_key' => Str::random(64), 'server_snapshot' => $snapshot,
            'traffic' => $traffic, 'relay_user_traffic' => $relayUsers,
            'relay_traffic' => [
                ['server_id' => $nodes[1]->id, 'server_type' => 'vless', 'u' => 31 * $count, 'd' => 61 * $count],
                ['server_id' => $nodes[2]->id, 'server_type' => 'vless', 'u' => 11 * $count, 'd' => 21 * $count],
            ], 'record_at' => now()->startOfDay()->timestamp, 'status' => 'pending', 'attempts' => 0]);
        return compact('users', 'nodes', 'machines', 'batch');
    }

    public static function duplicate(NodeReportBatch $batch): NodeReportBatch
    {
        $copy = $batch->replicate();
        $copy->forceFill(['report_id' => Str::random(24), 'report_key' => Str::random(64),
            'status' => 'pending', 'processed_at' => null, 'payload_cleanup_at' => 0, 'attempts' => 0])->save();
        return $copy;
    }

    /** 不返回测试身份或认证信息，只用于前后等价性核对。 */
    public static function totals(): array
    {
        $result = [];
        foreach (['v2_stat_user', 'v2_stat_server', 'v2_stat_user_server', 'v2_stat_traffic_hour', 'v2_stat_node_hour',
            'v2_stat_node_hour_detail', 'v2_stat_node_minute_detail', 'v2_stat_user_route_source',
            'v2_stat_route_hour', 'v2_stat_route_minute', 'v2_stat_proxy_traffic'] as $table) {
            $columns = in_array($table, ['v2_stat_user_server', 'v2_stat_user_route_source', 'v2_stat_route_hour', 'v2_stat_route_minute'])
                ? ['u', 'd', 'billed_u', 'billed_d'] : ['u', 'd'];
            $result[$table] = ['rows' => DB::table($table)->count()];
            foreach ($columns as $column) $result[$table][$column] = (int) DB::table($table)->sum($column);
        }
        foreach (['v2_user' => ['u', 'd'], 'v2_server' => ['u', 'd', 'usage_u', 'usage_d'], 'v2_server_machine' => ['proxy_u', 'proxy_d']] as $table => $columns) {
            foreach ($columns as $column) $result[$table][$column] = (int) DB::table($table)->sum($column);
        }
        return $result;
    }

    public static function fingerprint(): string
    {
        $result = [];
        foreach (array_keys(self::totals()) as $table) {
            if (in_array($table, ['v2_user', 'v2_server', 'v2_server_machine'])) continue;
            $rows = DB::table($table)->get()->map(function ($row): string {
                $row = (array) $row;
                unset($row['id'], $row['created_at'], $row['updated_at']);
                ksort($row);
                return json_encode($row, JSON_THROW_ON_ERROR);
            })->all();
            sort($rows, SORT_STRING);
            $result[$table] = $rows;
        }
        return hash('sha256', json_encode($result, JSON_THROW_ON_ERROR));
    }
}
