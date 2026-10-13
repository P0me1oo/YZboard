<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_server', function (Blueprint $table) {
            $table->decimal('rate', 20, 8)->comment('Traffic Rate')->change();
        });
        Schema::table('v2_stat_user', function (Blueprint $table) {
            $table->decimal('server_rate', 20, 8)->change();
        });
    }

    public function down(): void
    {
        // 较宽精度兼容旧代码；缩回两位会改写倍率并可能合并历史唯一键，因此保留。
    }
};
