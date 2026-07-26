<?php

use App\Models\Server;
use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * 为节点增加 VLESS 路由编号。
     *
     * 编号写入客户端 UUID 的第 7、8 字节（0 基下标 6、7），Xray 认证时会把这两个
     * 字节清零，因此不影响用户身份匹配。0 不可用：Xray 的端口列表解析会丢弃数字 0。
     */
    public function up(): void
    {
        Schema::table('v2_server', function (Blueprint $table) {
            $table->unsignedSmallInteger('vless_route')
                ->nullable()
                ->after('parent_id')
                ->comment('VLESS Route ID (1-65535)');
            $table->index('vless_route');
        });

        $this->backfillRouteIds();
    }

    public function down(): void
    {
        Schema::table('v2_server', function (Blueprint $table) {
            $table->dropIndex(['vless_route']);
            $table->dropColumn('vless_route');
        });
    }

    /**
     * 给存量节点按 id 顺序分配编号，并记录游标，保证后续新建节点不会复用已删除节点的编号。
     */
    private function backfillRouteIds(): void
    {
        $next = 1;

        DB::table('v2_server')
            ->orderBy('id')
            ->select('id')
            ->chunk(200, function ($servers) use (&$next) {
                foreach ($servers as $server) {
                    if ($next > 65535) {
                        return false;
                    }
                    DB::table('v2_server')
                        ->where('id', $server->id)
                        ->update(['vless_route' => $next]);
                    $next++;
                }
                return true;
            });

        $cursor = $next - 1;
        if ($cursor < 1) {
            return;
        }

        $existing = Setting::where('name', Server::ROUTE_CURSOR_SETTING)->first();
        if ($existing && (int) $existing->getRawOriginal('value') >= $cursor) {
            return;
        }

        Setting::createOrUpdate(Server::ROUTE_CURSOR_SETTING, (string) $cursor);
    }
};
