<?php

namespace App\Services;

use App\Models\NodeReportBatch;
use App\Models\Server;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class TrafficReconciliation
{
    public function rows(Request $r): array
    {
        $range = app(TrafficStatisticsService::class)->range($r, 30);
        $filter = $r->validate(['only_issues' => 'sometimes|boolean']);
        $query = Server::query()->when($r->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$r->input('search').'%'));
        // 异常筛选和全页摘要需要所有匹配节点，但只读取展示所需字段和一批状态缓存。
        $nodes = (clone $query)->orderBy('id')->get(['id', 'name', 'type', 'machine_id', 'parent_id', 'enabled', 'health_check', 'relay_entry_id', 'relay_pool_id']);
        $batches = NodeReportBatch::query()->whereIn('server_id', (clone $query)->select('id'))
            ->where('status', '!=', NodeReportBatch::STATUS_PROCESSED)->selectRaw('server_id,status,COUNT(*) as count,MIN(created_at) as oldest')->groupBy('server_id', 'status')->get()->groupBy('server_id');
        $views = app(NodeOperations::class)->views($nodes);
        $items = [];
        foreach ($nodes as $node) {
            $view = $views[$node->id];
            $states = $batches->get($node->id, collect());
            $failed = (int)$states->where('status', 'failed')->sum('count');
            $pending = (int)$states->where('status', '!=', 'failed')->sum('count');
            $item = ['id' => (int)$node->id,'name' => $node->name,'machine_id' => $node->machine_id,'pending_batches' => $pending,'failed_batches' => $failed,'oldest_pending' => $states->min('oldest'),'agent_pending_bytes' => $view['fresh'] ? ($view['state']['pending_bytes'] ?? null) : null,'agent_fresh' => $view['fresh'],'supported' => $view['supported'],'relay' => $node->isRelayChild(),'checked_at' => $view['state']['received_at'] ?? null];
            $item['issue'] = $failed > 0 || $pending > 0 || !$view['fresh'] || ($item['agent_pending_bytes'] ?? 0) > 0;
            if (!($filter['only_issues'] ?? false) || $item['issue']) {
                $items[] = $item;
            }
        }
        $size = $range['page_size'];
        $page = $range['page'];
        $list = array_slice($items, ($page - 1) * $size, $size);
        // 日账本只聚合当前页，翻页或异常筛选不会重复扫描所有节点的历史流量。
        $totals = $list === [] ? collect() : DB::table('v2_stat_user_server')->whereIn('server_id', array_column($list, 'id'))
            ->where('kind', 'entry')->where('record_at', '>=', $range['start']->timestamp)->where('record_at', '<', $range['end']->timestamp)
            ->selectRaw('server_id, SUM(u+d) as actual, SUM(billed_u+billed_d) as billed')->groupBy('server_id')->get()->keyBy('server_id');
        foreach ($list as &$item) {
            $item['actual'] = (int) ($totals[$item['id']]->actual ?? 0);
            $item['billed'] = (int) ($totals[$item['id']]->billed ?? 0);
        }
        unset($item);
        return ['list' => $list,'total' => count($items),'page' => $page,'last_page' => max(1, (int)ceil(count($items) / $size)),'meta' => $range['meta'],'summary' => ['failed' => array_sum(array_column($items, 'failed_batches')),'pending' => array_sum(array_column($items, 'pending_batches')),'unknown' => count(array_filter($items, fn ($row) => !$row['agent_fresh']))]];
    }
    public function detail(Request $r): array
    {
        $p = $r->validate(['node_id' => 'required|integer|min:1','page' => 'sometimes|integer|min:1']);
        $node = Server::findOrFail($p['node_id']);
        $batches = NodeReportBatch::where('server_id', $node->id)->where('status', '!=', NodeReportBatch::STATUS_PROCESSED)->orderBy('created_at')->paginate(30, ['id','status','attempts','last_error','created_at','updated_at']);
        $rows = $batches->getCollection()->map(function ($batch) {
            $row = $batch->toArray();
            $row['last_error'] = $row['last_error'] ? LogSanitizer::text($row['last_error']) : null;
            return $row;
        });
        return ['node_id' => (int)$node->id,'name' => $node->name,'operations' => app(NodeOperations::class)->view($node),'list' => $rows,'total' => $batches->total(),'page' => $batches->currentPage(),'last_page' => $batches->lastPage()];
    }
}
