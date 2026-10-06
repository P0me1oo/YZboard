<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Models\StatServer;
use App\Models\User;
use App\Services\TrafficStatisticsRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DeletedServerStatisticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
        Sanctum::actingAs(User::create(['email' => 'statistics-admin@example.test', 'password' => Str::random(32),
            'uuid' => (string) Str::uuid(), 'token' => Str::random(32), 'is_admin' => true]));
    }

    private function path(string $endpoint): string
    {
        return '/api/v2/' . hash('crc32b', config('app.key')) . '/' . $endpoint;
    }

    private function node(string $name): Server
    {
        return Server::create(['name' => $name, 'type' => 'vless', 'host' => 'example.test',
            'port' => 443, 'server_port' => 443, 'rate' => 1, 'group_ids' => [], 'enabled' => false, 'show' => false]);
    }

    private function record(int $id, int $amount = 100): void
    {
        app(TrafficStatisticsRecorder::class)->add(101, $id, 'entry', now()->startOfDay()->timestamp, $amount, 0, $amount, 0);
        app(\App\Services\UserRouteTraffic::class)->record(101, $id, $id, 'entry', 1,
            now()->startOfDay()->timestamp, $amount, 0, $amount, 0, now()->timestamp);
        StatServer::create(['server_id' => $id, 'server_type' => 'vless', 'record_type' => 'd',
            'record_at' => now()->startOfDay()->timestamp, 'u' => $amount, 'd' => 0]);
    }

    public function test_single_delete_keeps_last_name_and_retry_does_not_change_history(): void
    {
        $node = $this->node('旧名称');
        $this->record($node->id);
        $node->update(['name' => '香港 01']);
        $this->postJson($this->path('server/manage/drop'), ['id' => $node->id])->assertOk();
        $this->assertDatabaseMissing('v2_server', ['id' => $node->id]);
        $saved = DB::table('v2_stat_server_name')->first();
        $this->assertSame('香港 01', $saved->name);
        $this->getJson($this->path('statistics/nodes'))->assertOk()
            ->assertJsonPath('data.list.0.name', '香港 01（已删除）')->assertJsonPath('data.list.0.total', 100);
        $this->getJson($this->path('statistics/user?user_id=101'))->assertOk()
            ->assertJsonPath('data.list.0.server_name', '香港 01（已删除）')
            ->assertJsonPath('data.nodes.0.name', '香港 01（已删除）')->assertJsonPath('data.actual_summary.total', 100);
        $this->travel(1)->hours();
        $this->assertNotSame(200, $this->postJson($this->path('server/manage/drop'), ['id' => $node->id])->status());
        $this->assertEquals($saved, DB::table('v2_stat_server_name')->first());
        $this->assertDatabaseCount('v2_stat_user_server', 1);
        $this->assertDatabaseCount('v2_stat_server', 1);
    }

    public function test_batch_delete_saves_all_names_and_repeated_ids_do_not_duplicate(): void
    {
        $first = $this->node('东京'); $second = $this->node('新加坡'); $kept = $this->node('保留节点');
        foreach ([$first, $second, $kept] as $node) {
            $this->record($node->id);
        }
        $ids = [$second->id, $first->id, $first->id, 99999];
        $this->postJson($this->path('server/manage/batchDelete'), ['ids' => $ids])->assertOk();
        $saved = DB::table('v2_stat_server_name')->orderBy('server_id')->get();
        $this->assertCount(2, $saved);
        $this->travel(1)->hours();
        $this->postJson($this->path('server/manage/batchDelete'), ['ids' => $ids])->assertOk();
        $this->assertEquals($saved, DB::table('v2_stat_server_name')->orderBy('server_id')->get());
        $this->assertDatabaseCount('v2_server', 1);
        $this->getJson($this->path('statistics/nodes'))->assertOk()->assertJsonPath('data.total', 3)
            ->assertJsonPath('data.list.0.name', '东京（已删除）')->assertJsonPath('data.list.1.name', '新加坡（已删除）')
            ->assertJsonPath('data.list.2.name', '保留节点');
        $this->getJson($this->path('statistics/traffic'))->assertJsonPath('data.summary.total', 300);
    }

    public function test_unknown_old_nodes_are_hidden_before_pagination_without_changing_accounted_traffic(): void
    {
        $first = $this->node('可见节点一'); $second = $this->node('可见节点二');
        $this->record(99999, 9000);
        $this->record($first->id, 200); $this->record($second->id, 100);
        $this->getJson($this->path('statistics/nodes?page_size=1'))->assertOk()
            ->assertJsonPath('data.total', 2)->assertJsonPath('data.last_page', 2)->assertJsonPath('data.list.0.id', $first->id);
        $this->getJson($this->path('statistics/nodes?page_size=1&page=2'))->assertOk()->assertJsonPath('data.list.0.id', $second->id);
        $this->getJson($this->path('statistics/user?user_id=101&page_size=1'))->assertOk()
            ->assertJsonPath('data.total', 2)->assertJsonCount(2, 'data.nodes')->assertJsonPath('data.last_page', 2)
            ->assertJsonPath('data.list.0.server_id', $first->id)->assertJsonPath('data.actual_summary.total', 9300);
        $this->getJson($this->path('statistics/user?user_id=101&server_id=99999'))->assertOk()->assertJsonCount(0, 'data.list');
        $this->getJson($this->path('statistics/traffic'))->assertJsonPath('data.summary.total', 9300);
        $this->getJson($this->path('statistics/users'))->assertJsonPath('data.list.0.total', 9300);
        $this->assertDatabaseCount('v2_stat_user_server', 3);
    }

    public static function deletionEndpoints(): array
    {
        return [['drop'], ['batchDelete']];
    }

    #[DataProvider('deletionEndpoints')]
    public function test_failed_deletion_rolls_back_saved_names_and_can_be_retried(string $endpoint): void
    {
        $node = $this->node('回滚节点');
        $this->record($node->id);
        $params = $endpoint === 'drop' ? ['id' => $node->id] : ['ids' => [$node->id]];
        DB::unprepared("CREATE TEMP TRIGGER statistics_block_delete BEFORE DELETE ON v2_server BEGIN SELECT RAISE(ABORT, 'test delete failed'); END");
        try {
            $response = $this->postJson($this->path('server/manage/' . $endpoint), $params);
            $this->assertNotSame(200, $response->status());
            $this->assertDatabaseHas('v2_server', ['id' => $node->id]);
            $this->assertDatabaseCount('v2_stat_server_name', 0);
            $this->getJson($this->path('statistics/nodes'))->assertJsonPath('data.list.0.name', '回滚节点');
        } finally {
            DB::unprepared('DROP TRIGGER statistics_block_delete');
        }
        $this->postJson($this->path('server/manage/' . $endpoint), $params)->assertOk();
        $this->getJson($this->path('statistics/nodes'))->assertJsonPath('data.list.0.name', '回滚节点（已删除）')
            ->assertJsonPath('data.list.0.total', 100);
    }

    public function test_expired_names_are_removed_only_after_their_statistics_expire(): void
    {
        $this->record(201);
        foreach ([201 => 30, 202 => 30, 203 => 29] as $id => $days) {
            DB::table('v2_stat_server_name')->insert(['server_id' => $id, 'name' => '历史节点 ' . $id,
                'deleted_at' => now()->startOfDay()->subDays($days)->timestamp]);
        }
        $this->artisan('reset:log')->assertSuccessful();
        $this->assertDatabaseHas('v2_stat_server_name', ['server_id' => 201]);
        $this->assertDatabaseHas('v2_stat_server_name', ['server_id' => 203]);
        $this->assertDatabaseMissing('v2_stat_server_name', ['server_id' => 202]);
        $this->travel(30)->days();
        $this->artisan('reset:log')->assertSuccessful();
        $this->assertDatabaseCount('v2_stat_server_name', 0);
        $this->assertDatabaseCount('v2_stat_user_server', 0);
        $this->assertDatabaseCount('v2_stat_server', 0);
    }
}
