<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('v2_server', function (Blueprint $table) {
            // 旧节点和功能关闭时创建的节点均不参与自动排序。
            $table->boolean('auto_sort_pending')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('v2_server', fn (Blueprint $table) => $table->dropColumn('auto_sort_pending'));
    }
};
