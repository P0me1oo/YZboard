<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('v2_server_group', 'sort')) {
            Schema::table('v2_server_group', function (Blueprint $table) {
                // 与节点排序同语义：数值越小越靠前，为空时回落到 id 兜底排序。
                $table->integer('sort')->nullable()->unsigned()->index()->after('name');
            });
        }

        // 历史数据按升级前的列表顺序（id 倒序）写成 1..N，避免升级后顺序突变。
        DB::table('v2_server_group')
            ->orderByDesc('id')
            ->pluck('id')
            ->each(function ($id, $index) {
                DB::table('v2_server_group')
                    ->where('id', $id)
                    ->update(['sort' => $index + 1]);
            });
    }

    public function down(): void
    {
        if (Schema::hasColumn('v2_server_group', 'sort')) {
            Schema::table('v2_server_group', function (Blueprint $table) {
                $table->dropColumn('sort');
            });
        }
    }
};
