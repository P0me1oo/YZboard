<?php

namespace Tests\Feature\Server;

use App\Models\Server;
use App\Services\NodeStateService;
use App\Services\ServerAutoSort;
use App\Services\ServerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServerAutoSortTest extends TestCase
{
    use RefreshDatabase;

    private function node(string $name, ?int $sort, ?array $groups = [1]): Server
    {
        return Server::create([
            'name' => $name, 'sort' => $sort, 'group_ids' => $groups,
            'type' => Server::TYPE_VMESS, 'host' => '127.0.0.1', 'port' => 443,
            'server_port' => 443, 'rate' => 1, 'enabled' => true,
        ]);
    }

    private function ids(): array
    {
        return ServerService::getAllServers()->pluck('id')->all();
    }

    public function test_first_report_inserts_after_last_matching_group_and_country_only_once(): void
    {
        $first = $this->node('香港 01', 10, ['2', '1']);
        $otherCountry = $this->node('日本', 20, [1, 2]);
        $last = $this->node('Hong-Kong 02', 30, [1, 2]);
        $otherGroup = $this->node('香港 03', 40, [1]);
        $tail = $this->node('美国', 50, [1, 2]);
        admin_setting(['server_auto_sort_enable' => true]);
        $new = $this->node('HK04', 60, [2, '1', 2]);
        $this->assertSame([$first->id, $otherCountry->id, $last->id, $otherGroup->id, $tail->id, $new->id], $this->ids());

        ServerService::touchPush($new);
        $this->assertSame([$first->id, $otherCountry->id, $last->id, $new->id, $otherGroup->id, $tail->id], $this->ids());
        $this->assertFalse($new->fresh()->auto_sort_pending);
        // 重复上报和管理员此后手调的位置都不会被自动排序覆盖。
        Server::whereKey($new->id)->update(['sort' => 100]);
        $saved = Server::pluck('sort', 'id')->all();
        ServerService::touchPush($new);
        NodeStateService::apply($new->fresh(), []);
        $this->assertSame($saved, Server::pluck('sort', 'id')->all());
    }

    public function test_enabling_does_not_move_existing_nodes_even_after_they_report(): void
    {
        $this->node('香港 01', 10);
        $this->node('日本', 20);
        $old = $this->node('香港 02', 30);
        $saved = Server::pluck('sort', 'id')->all();
        admin_setting(['server_auto_sort_enable' => true]);
        ServerService::touchPush($old);
        $this->assertSame($saved, Server::pluck('sort', 'id')->all());
        $this->assertFalse($old->fresh()->auto_sort_pending);
    }

    public function test_ungrouped_nodes_match_by_country_and_keep_other_countries_in_order(): void
    {
        $hk = $this->node('香港 01', 10, null);
        $jp = $this->node('日本', 20, []);
        $us = $this->node('美国', 30, null);
        admin_setting(['server_auto_sort_enable' => true]);
        $new = $this->node('香港 02', 40, []);
        NodeStateService::apply($new, []);
        $this->assertSame([$hk->id, $new->id, $jp->id, $us->id], $this->ids());
    }

    public function test_no_matching_group_or_country_keeps_original_sort_values(): void
    {
        $this->node('香港', 10, [1]);
        $this->node('日本', 20, [2]);
        admin_setting(['server_auto_sort_enable' => true]);
        foreach ([['香港 02', [2]], ['美国', [1]], ['香港 03', []]] as [$name, $groups]) {
            $new = $this->node($name, 30, $groups);
            $saved = Server::pluck('sort', 'id')->all();
            ServerAutoSort::onReport($new);
            $this->assertSame($saved, Server::pluck('sort', 'id')->all());
            $this->assertFalse($new->fresh()->auto_sort_pending);
        }
    }

    public function test_unknown_country_waits_until_recognized_without_moving_old_nodes(): void
    {
        $hk = $this->node('香港 01', 10);
        $jp = $this->node('日本', 20);
        admin_setting(['server_auto_sort_enable' => true]);
        $new = $this->node('新线路', 30);
        $saved = Server::pluck('sort', 'id')->all();
        ServerService::touchPush($new);
        $this->assertSame($saved, Server::pluck('sort', 'id')->all());
        $this->assertTrue($new->fresh()->auto_sort_pending);
        $new->update(['name' => '香港 02']);
        ServerService::touchPush($new->fresh());
        $this->assertSame([$hk->id, $new->id, $jp->id], $this->ids());
    }

    public function test_report_while_disabled_consumes_eligibility_and_reenable_does_not_move_it(): void
    {
        $this->node('香港 01', 10);
        $this->node('日本', 20);
        admin_setting(['server_auto_sort_enable' => true]);
        $new = $this->node('香港 02', 30);
        admin_setting(['server_auto_sort_enable' => false]);
        ServerService::touchPush($new);
        $this->assertFalse($new->fresh()->auto_sort_pending);
        $saved = Server::pluck('sort', 'id')->all();
        admin_setting(['server_auto_sort_enable' => true]);
        ServerService::touchPush($new->fresh());
        $this->assertSame($saved, Server::pluck('sort', 'id')->all());
    }

    public function test_several_new_nodes_and_null_or_duplicate_sort_values_remain_stable(): void
    {
        $hk = $this->node('香港', null);
        $jp = $this->node('日本', 10);
        $us = $this->node('美国', 10);
        admin_setting(['server_auto_sort_enable' => true]);
        $first = $this->node('HK02', null);
        $second = $this->node('香港 03', null);
        ServerAutoSort::onReport($first);
        ServerAutoSort::onReport($second);
        $this->assertSame([$hk->id, $first->id, $second->id, $jp->id, $us->id], $this->ids());
    }

    public function test_failure_rolls_back_sort_and_marker_and_allows_retry(): void
    {
        $hk = $this->node('香港 01', 10);
        $jp = $this->node('日本', 20);
        admin_setting(['server_auto_sort_enable' => true]);
        $new = $this->node('香港 02', 30);
        $saved = Server::pluck('sort', 'id')->all();
        $this->failNextQueryMatching('/update.*v2_server.*auto_sort_pending/i');
        try {
            ServerAutoSort::onReport($new);
            $this->fail('排序和一次性标记应一起回滚');
        } catch (\Illuminate\Database\QueryException) {
            $this->assertSame($saved, Server::pluck('sort', 'id')->all());
            $this->assertTrue($new->fresh()->auto_sort_pending);
        }
        ServerAutoSort::onReport($new->fresh());
        $this->assertSame([$hk->id, $new->id, $jp->id], $this->ids());
    }

    public function test_creation_resets_copied_eligibility_and_internal_pool_nodes_do_not_move(): void
    {
        admin_setting(['server_auto_sort_enable' => true]);
        $source = $this->node('香港', 10);
        admin_setting(['server_auto_sort_enable' => false]);
        $copy = $source->replicate();
        $copy->save();
        $this->assertFalse($copy->fresh()->auto_sort_pending);
        admin_setting(['server_auto_sort_enable' => true]);
        $internal = $this->node('香港', 20);
        $internal->update(['balancer_role' => 'member']);
        $saved = Server::pluck('sort', 'id')->all();
        ServerAutoSort::onReport($internal);
        $this->assertSame($saved, Server::pluck('sort', 'id')->all());
    }

    public function test_authenticated_reports_trigger_sort_but_config_reads_do_not(): void
    {
        $hk = $this->node('香港 01', 10);
        $jp = $this->node('日本', 20);
        admin_setting(['server_auto_sort_enable' => true, 'server_token' => 'auto-sort-test-only']);
        $new = $this->node('香港 02', 30);
        $auth = ['node_id' => $new->id, 'token' => 'auto-sort-test-only'];
        $saved = Server::pluck('sort', 'id')->all();
        $this->getJson('/api/v2/server/config?' . http_build_query($auth))->assertOk();
        ServerService::touchNode($new);
        $this->assertSame($saved, Server::pluck('sort', 'id')->all());
        $this->postJson('/api/v2/server/report', $auth + ['traffic' => []])->assertOk();
        $this->assertSame([$hk->id, $new->id, $jp->id], $this->ids());
    }
}
