<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_server', function (Blueprint $table) {
            // null 保留历史 Xray 语义；新建节点的默认内核由保存入口显式写入。
            $table->string('kernel_type', 16)->nullable()->after('machine_id');
        });
    }

    public function down(): void
    {
        Schema::table('v2_server', function (Blueprint $table) {
            $table->dropColumn('kernel_type');
        });
    }
};
