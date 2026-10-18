<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_server', function (Blueprint $table) {
            // 空值保留旧节点的名单策略；新建节点由保存接口明确设为关闭。
            $table->boolean('accept_proxy_protocol')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('v2_server', fn (Blueprint $table) => $table->dropColumn('accept_proxy_protocol'));
    }
};
