<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_landing_pool', fn (Blueprint $table) => $table->unsignedBigInteger('node_id')->nullable()->change());
    }

    public function down(): void
    {
        if (DB::table('v2_landing_pool')->whereNull('node_id')->exists()) {
            throw new RuntimeException('回退前请重新配置或删除缺少主线路的落地池');
        }
        Schema::table('v2_landing_pool', fn (Blueprint $table) => $table->unsignedBigInteger('node_id')->nullable(false)->change());
    }
};
