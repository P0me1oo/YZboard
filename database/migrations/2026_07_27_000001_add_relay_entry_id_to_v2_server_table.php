<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * 中转入口节点 ID。
     *
     * 与 parent_id 完全独立：parent_id 保持上游语义（共享运行状态与 SS2022 服务端密钥），
     * relay_entry_id 才表示“本节点是中转逻辑节点，客户端连接的是入口节点”。
     * 拆开是为了让一个节点既能沿用原有的父节点关系，又能独立参与中转拓扑。
     */
    public function up(): void
    {
        Schema::table('v2_server', function (Blueprint $table) {
            $table->unsignedInteger('relay_entry_id')
                ->nullable()
                ->after('parent_id')
                ->comment('Relay Entry Server ID');
            $table->index('relay_entry_id');
        });
    }

    public function down(): void
    {
        Schema::table('v2_server', function (Blueprint $table) {
            $table->dropIndex(['relay_entry_id']);
            $table->dropColumn('relay_entry_id');
        });
    }
};
